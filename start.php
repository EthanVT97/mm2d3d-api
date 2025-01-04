<?php
// Allow from any origin
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $allowedOrigins = [
        'https://ethanvt97.github.io',
        'http://localhost:5500',
        'http://127.0.0.1:5500'
    ];
    
    $origin = $_SERVER['HTTP_ORIGIN'];
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
        header('Access-Control-Allow-Credentials: true');
    }
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
    }

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
        header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
    }

    exit(0);
}

require_once __DIR__ . '/vendor/autoload.php';

// Load environment variables if .env exists
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Set default values if environment variables are not set
$_ENV['SUPABASE_URL'] = $_ENV['SUPABASE_URL'] ?? 'https://jaubdheyosmukdxvctbq.supabase.co';
$_ENV['FRONTEND_URL'] = $_ENV['FRONTEND_URL'] ?? 'https://ethanvt97.github.io';

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
