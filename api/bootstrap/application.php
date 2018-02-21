<?php

$configPath = realpath(__DIR__ . '/../../') . '/config';
$configFilePath = $configPath . '/api.php';

// Creates a simple endpoint to test the server rewriting
// If the server responds "pong" it means the rewriting works
if (!file_exists($configFilePath)) {
    return create_ping_server();
}

// Get Environment name
$env = get_api_env();
if ($env !== '_') {
    $configFilePath = sprintf('%s/api.%s.php', $configPath, $env);
    if (!file_exists($configFilePath)) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => [
                'error' => 8,
                'message' => 'API Environment Configuration Not Found: ' . $env
            ]
        ]);
        exit;
    }
}

$app = new \Directus\Application\Application(realpath(__DIR__ . '/../../'), require $configFilePath);

create_ping_route($app);

return $app;
