<?php
require_once '../../config/cors.php';
cors();

header("Content-Type: application/json; charset=UTF-8");

include_once '../../config/jwt.php';
include_once '../../config/database.php';

$database = new Database();
$db = $database->connect();
$jwt = new JWT();

// Get authorization header
$headers = getallheaders();

if (!isset($headers['Authorization'])) {
    http_response_code(401);
    echo json_encode(array(
        "status" => "error",
        "message" => "No authorization token provided"
    ));
    exit();
}

$auth_header = $headers['Authorization'];
$token = str_replace('Bearer ', '', $auth_header);

$user_data = $jwt->validate_token($token);

if (!$user_data) {
    http_response_code(401);
    echo json_encode(array(
        "status" => "error",
        "message" => "Invalid or expired token"
    ));
    exit();
}

// Check if user exists and is active
$query = "SELECT status FROM users WHERE id = :id AND phone_number = :phone_number";
$stmt = $db->prepare($query);
$stmt->bindParam(":id", $user_data['user_id']);
$stmt->bindParam(":phone_number", $user_data['phone_number']);

try {
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row['status'] === 'active') {
            http_response_code(200);
            echo json_encode(array(
                "status" => "success",
                "message" => "Token is valid",
                "data" => array(
                    "user_id" => $user_data['user_id'],
                    "phone_number" => $user_data['phone_number'],
                    "name" => $user_data['name']
                )
            ));
        } else {
            http_response_code(403);
            echo json_encode(array(
                "status" => "error",
                "message" => "Account is " . $row['status']
            ));
        }
    } else {
        http_response_code(404);
        echo json_encode(array(
            "status" => "error",
            "message" => "User not found"
        ));
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ));
}
