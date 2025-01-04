<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../../config/database.php';

$database = new Database();
$db = $database->connect();

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->username) && !empty($data->password)) {
    // In a real application, you would have an admins table and proper password hashing
    // This is just a simple example
    if($data->username === "admin" && $data->password === "admin123") {
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "message" => "Login successful",
            "data" => array(
                "username" => $data->username,
                "role" => "admin"
            )
        ));
    } else {
        http_response_code(401);
        echo json_encode(array(
            "status" => "error",
            "message" => "Invalid credentials"
        ));
    }
} else {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => "Username and password are required"
    ));
}
?>
