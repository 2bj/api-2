<?php

namespace Directus\Services;

use Directus\Authentication\AbstractSocialProvider;
use Directus\Authentication\Exception\ExpiredResetPasswordToken;
use Directus\Authentication\Exception\InvalidResetPasswordTokenException;
use Directus\Authentication\Exception\InvalidUserCredentialsException;
use Directus\Authentication\Exception\UserNotFoundException;
use Directus\Authentication\Exception\UserWithEmailNotFoundException;
use Directus\Authentication\OneSocialProvider;
use Directus\Authentication\Provider;
use Directus\Authentication\Social;
use Directus\Authentication\User\UserInterface;
use Directus\Database\TableGateway\DirectusActivityTableGateway;
use Directus\Database\TableGateway\DirectusGroupsTableGateway;
use Directus\Exception\BadRequestException;
use Directus\Exception\UnauthorizedException;
use Directus\Util\ArrayUtils;
use Directus\Util\JWTUtils;
use Directus\Util\StringUtils;

class AuthService extends AbstractService
{
    /**
     * Gets the user token using the authentication email/password combination
     *
     * @param string $email
     * @param string $password
     *
     * @return array
     *
     * @throws UnauthorizedException
     */
    public function loginWithCredentials($email, $password)
    {
        $this->validateCredentials($email, $password);

        /** @var Provider $auth */
        $auth = $this->container->get('auth');

        /** @var UserInterface $user */
        $user = $auth->login([
            'email' => $email,
            'password' => $password
        ]);

        // ------------------------------
        // Check if group needs whitelist
        /** @var DirectusGroupsTableGateway $groupTableGateway */
        $groupTableGateway = $this->createTableGateway('directus_groups', false);
        if (!$groupTableGateway->acceptIP($user->getGroupId(), get_request_ip())) {
            throw new UnauthorizedException('Request not allowed from IP address');
        }

        $hookEmitter = $this->container->get('hook_emitter');
        $hookEmitter->run('directus.authenticated', [$user]);

        // TODO: Move to the hook above
        /** @var DirectusActivityTableGateway $activityTableGateway */
        $activityTableGateway = $this->createTableGateway('directus_activity', false);
        $activityTableGateway->recordLogin($user->get('id'));

        return [
            'data' => [
                'token' => $this->generateAuthToken($user)
            ]
        ];
    }

    /**
     * @param string $name
     *
     * @return array
     */
    public function getAuthenticationRequestData($name)
    {
        return [
            'data' => $this->getSsoAuthorizationData($name)
        ];
    }

    /**
     * @param string $name
     *
     * @return array
     */
    public function getSsoAuthorizationData($name)
    {
        /** @var Social $socialAuth */
        $socialAuth = $this->container->get('external_auth');
        /** @var AbstractSocialProvider $service */
        $service = $socialAuth->get($name);

        return [
            'authorization_url' => $service->getRequestAuthorizationUrl(),
            'state' => $service->getProvider()->getState()
        ];
    }

    /**
     * @param string $name
     *
     * @return array
     */
    public function getSsoCallbackData($name)
    {
        /** @var Social $socialAuth */
        $socialAuth = $this->container->get('external_auth');
        /** @var AbstractSocialProvider $service */
        $service = $socialAuth->get($name);

        return [
            'callback_url' => $service->getRequestAuthorizationUrl()
        ];
    }

    public function handleAuthenticationRequestCallback($name)
    {
        /** @var Social $socialAuth */
        $socialAuth = $this->container->get('external_auth');
        /** @var AbstractSocialProvider $service */
        $service = $socialAuth->get($name);

        $serviceUser = $service->handle();

        $user = $this->authenticateWithEmail($serviceUser->getEmail());

        return [
            'data' => [
                'token' => $this->generateAuthToken($user)
            ]
        ];
    }

    /**
     * @param $token
     *
     * @return UserInterface
     */
    public function authenticateWithToken($token)
    {
        if (JWTUtils::isJWT($token)) {
            $authenticated = $this->getAuthProvider()->authenticateWithToken($token);
        } else {
            $authenticated = $this->getAuthProvider()->authenticateWithPrivateToken($token);
        }

        return $authenticated;
    }

    /**
     * Authenticates a user with the given email
     *
     * @param $email
     *
     * @return \Directus\Authentication\User\User
     *
     * @throws UserWithEmailNotFoundException
     */
    public function authenticateWithEmail($email)
    {
        return $this->getAuthProvider()->authenticateWithEmail($email);
    }

    /**
     * Authenticate an user with the SSO authorization code
     *
     * @param string $service
     * @param array $params
     *
     * @return array
     */
    public function authenticateWithSsoCode($service, array $params)
    {
        /** @var Social $socialAuth */
        $socialAuth = $this->container->get('external_auth');
        /** @var AbstractSocialProvider $service */
        $service = $socialAuth->get($service);

        if ($service instanceof OneSocialProvider) {
            $data = ArrayUtils::pick($params, ['oauth_token', 'oauth_verifier']);
        } else {
            $data = ArrayUtils::pick($params, ['code']);
        }

        $serviceUser = $service->getUserFromCode($data);
        $user = $this->authenticateWithEmail($serviceUser->getEmail());

        return [
            'data' => [
                'token' => $this->generateAuthToken($user)
            ]
        ];
    }

    /**
     * Generates JWT Token
     *
     * @param UserInterface $user
     *
     * @return string
     */
    public function generateAuthToken(UserInterface $user)
    {
        /** @var Provider $auth */
        $auth = $this->container->get('auth');

        return $auth->generateAuthToken($user);
    }

    /**
     * Sends a email with the reset password token
     *
     * @param $email
     */
    public function sendResetPasswordToken($email)
    {
        $this->validate(['email' => $email], ['email' => 'required|email']);

        /** @var Provider $auth */
        $auth = $this->container->get('auth');
        $user = $auth->findUserWithEmail($email);

        $resetToken = $auth->generateResetPasswordToken($user);

        send_forgot_password_email($user->toArray(), $resetToken);
    }

    public function resetPasswordWithToken($token)
    {
        if (!JWTUtils::isJWT($token)) {
            throw new InvalidResetPasswordTokenException($token);
        }

        if (JWTUtils::hasExpired($token)) {
            throw new ExpiredResetPasswordToken($token);
        }

        $payload = JWTUtils::getPayload($token);

        /** @var Provider $auth */
        $auth = $this->container->get('auth');
        $userProvider = $auth->getUserProvider();
        $user = $userProvider->find($payload->id);

        if (!$user) {
            throw new UserNotFoundException();
        }

        $newPassword = StringUtils::randomString(16);
        $userProvider->update($user, [
            'password' => $auth->hashPassword($newPassword)
        ]);

        send_reset_password_email($user->toArray(), $newPassword);
    }

    public function refreshToken($token)
    {
        $this->validate([
            'token' => $token
        ], [
            'token' => 'required'
        ]);

        /** @var Provider $auth */
        $auth = $this->container->get('auth');

        return ['data' => ['token' => $auth->refreshToken($token)]];
    }

    /**
     * @return Provider
     */
    protected function getAuthProvider()
    {
        return $this->container->get('auth');
    }

    /**
     * Validates email+password credentials
     *
     * @param $email
     * @param $password
     *
     * @throws BadRequestException
     */
    protected function validateCredentials($email, $password)
    {
        $payload = [
            'email' => $email,
            'password' => $password
        ];
        $constraints = [
            'email' => 'required|email',
            'password' => 'required'
        ];

        // throws an exception if the constraints are not met
        $this->validate($payload, $constraints);
    }
}
