<?php
error_log("Register endpoint hit");
require_once '../../config/cors.php';
cors();

header("Content-Type: application/json; charset=UTF-8");

include_once '../../config/database.php';
include_once '../../config/jwt.php';

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Create log file
$logFile = __DIR__ . '/../../logs/debug.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0777, true);
}

function logDebug($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

$database = new Database();
$db = $database->connect();
$jwt = new JWT();

// Log raw input
$raw_input = file_get_contents("php://input");
logDebug("Raw input: " . $raw_input);
error_log("Raw input received: " . $raw_input);

$data = json_decode($raw_input);
error_log("Decoded data: " . print_r($data, true));

// Log decoded data
logDebug("Decoded data: " . print_r($data, true));

if(!empty($data->phone_number) && !empty($data->name) && !empty($data->password)) {
    logDebug("All required fields present");
    
    // Validate phone number format (Myanmar format)
    if (!preg_match("/^(09|\+?959)\d{7,9}$/", $data->phone_number)) {
        logDebug("Invalid phone number format: " . $data->phone_number);
        http_response_code(400);
        echo json_encode(array(
            "status" => "error",
            "message" => "Invalid phone number format"
        ));
        exit();
    }

    // Validate password strength
    if (strlen($data->password) < 6) {
        logDebug("Password too short: " . strlen($data->password) . " characters");
        http_response_code(400);
        echo json_encode(array(
            "status" => "error",
            "message" => "Password must be at least 6 characters long"
        ));
        exit();
    }

    // Check if phone number already exists
    $check_query = "SELECT id FROM users WHERE phone_number = :phone_number";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(":phone_number", $data->phone_number);
    
    try {
        $check_stmt->execute();
        logDebug("Checked for existing phone number");
        
        if($check_stmt->rowCount() > 0) {
            logDebug("Phone number already exists: " . $data->phone_number);
            http_response_code(400);
            echo json_encode(array(
                "status" => "error",
                "message" => "Phone number already registered"
            ));
            exit();
        }
        
        // Hash password
        $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);
        
        // Create new user with UUID
        $query = "INSERT INTO users (phone_number, name, password, status, created_at) 
                  VALUES (:phone_number, :name, :password, 'active', CURRENT_TIMESTAMP) RETURNING id";
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(":phone_number", $data->phone_number);
        $stmt->bindParam(":name", $data->name);
        $stmt->bindParam(":password", $hashed_password);
        
        logDebug("Attempting to insert new user");
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $user_id = $result['id'];
        
        if($user_id) {
            logDebug("User created successfully with ID: " . $user_id);
            
            // Generate JWT token
            $token = $jwt->generate_token([
                'user_id' => $user_id,
                'phone_number' => $data->phone_number,
                'name' => $data->name
            ]);
            
            // Create initial analytics record
            $analytics_query = "INSERT INTO user_analytics (user_id, created_at, updated_at) 
                              VALUES (:user_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)";
            $analytics_stmt = $db->prepare($analytics_query);
            $analytics_stmt->bindParam(":user_id", $user_id);
            $analytics_stmt->execute();
            logDebug("Analytics record created");
            
            http_response_code(201);
            echo json_encode(array(
                "status" => "success",
                "message" => "User registered successfully",
                "data" => array(
                    "id" => $user_id,
                    "phone_number" => $data->phone_number,
                    "name" => $data->name,
                    "token" => $token
                )
            ));
        } else {
            logDebug("Failed to create user");
            http_response_code(500);
            echo json_encode(array(
                "status" => "error",
                "message" => "Unable to register user"
            ));
        }
    } catch(PDOException $e) {
        logDebug("Database error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(array(
            "status" => "error",
            "message" => "Database error: " . $e->getMessage()
        ));
    }
} else {
    logDebug("Missing required fields");
    logDebug("phone_number: " . (isset($data->phone_number) ? "set" : "not set"));
    logDebug("name: " . (isset($data->name) ? "set" : "not set"));
    logDebug("password: " . (isset($data->password) ? "set" : "not set"));
    
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => "Missing required fields (phone_number, name, password)"
    ));
}
