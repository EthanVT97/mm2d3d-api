<?php
header('Access-Control-Allow-Origin: https://ethanvt97.github.io');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Parse the URL
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path = ltrim($path, '/');
$segments = explode('/', $path);

// Route the request
$controller = $segments[0] ?? 'home';
$action = $segments[1] ?? 'index';

// Map routes to files
$route_map = [
    'auth/login' => 'auth/login.php',
    'auth/register' => 'auth/register.php',
    'auth/verify' => 'auth/verify.php',
    'user/profile' => 'user/profile.php',
    'user/balance' => 'user/balance.php'
];

$route = $controller . '/' . $action;

if (isset($route_map[$route])) {
    require_once __DIR__ . '/' . $route_map[$route];
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}
