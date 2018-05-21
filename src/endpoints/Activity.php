<?php

namespace Directus\Api\Routes;

use Directus\Application\Application;
use Directus\Application\Http\Request;
use Directus\Application\Http\Response;
use Directus\Application\Route;
use Directus\Database\TableGateway\DirectusActivityTableGateway;

class Activity extends Route
{
    /**
     * @param Application $app
     */
    public function __invoke(Application $app)
    {
        $app->get('', [$this, 'all']);
        $app->get('/{id}', [$this, 'read']);
        $app->post('/comment', [$this, 'createComment']);
    }

    /**
     * @param Request $request
     * @param Response $response
     *
     * @return Response
     */
    public function all(Request $request, Response $response)
    {
        $dbConnection = $this->container->get('database');
        $acl = $this->container->get('acl');
        $params = $request->getQueryParams();

        $activityTableGateway = new DirectusActivityTableGateway($dbConnection, $acl);

        // a way to get records last updated from activity
        // if (ArrayUtils::get($params, 'last_updated')) {
        //     $table = key($params['last_updated']);
        //     $ids = ArrayUtils::get($params, 'last_updated.' . $table);
        //     $arrayOfIds = $ids ? explode(',', $ids) : [];
        //     $responseData = $activityTableGateway->getLastUpdated($table, $arrayOfIds);
        // } else {
        //
        // }

        $responseData = $activityTableGateway->getItems($params);

        return $this->responseWithData($request, $response, $responseData);
    }

    /**
     * @param Request $request
     * @param Response $response
     *
     * @return Response
     */
    public function read(Request $request, Response $response)
    {
        $dbConnection = $this->container->get('database');
        $acl = $this->container->get('acl');
        $params = array_merge($request->getQueryParams(), [
            'id' => $request->getAttribute('id')
        ]);

        $activityTableGateway = new DirectusActivityTableGateway($dbConnection, $acl);
        $responseData = $activityTableGateway->getItems($params);

        return $this->responseWithData($request, $response, $responseData);
    }

    /**
     * @param Request $request
     * @param Response $response
     *
     * @return Response
     */
    public function createComment(Request $request, Response $response)
    {
        $payload = $request->getParsedBody();
        $dbConnection = $this->container->get('database');
        $acl = $this->container->get('acl');
        $activityTableGateway = new DirectusActivityTableGateway($dbConnection, $acl);
        $payload = array_merge($payload, [
            'user' => $acl->getUserId()
        ]);

        $record = $activityTableGateway->recordMessage($payload);

        return $this->responseWithData($request, $response, $activityTableGateway->wrapData(
            $record->toArray(),
            true
        ));
    }
}
