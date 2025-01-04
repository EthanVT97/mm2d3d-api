<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../../../config/database.php';

$database = new Database();
$db = $database->connect();

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->username) && !empty($data->password) && isset($data->initial_balance)) {
    // Check if username already exists
    $check_query = "SELECT id FROM agents WHERE username = :username";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(":username", $data->username);
    $check_stmt->execute();
    
    if($check_stmt->rowCount() > 0) {
        http_response_code(400);
        echo json_encode(array(
            "status" => "error",
            "message" => "Username already exists"
        ));
        exit();
    }
    
    // Hash password
    $hashed_password = password_hash($data->password, PASSWORD_DEFAULT);
    
    // Create new agent
    $query = "INSERT INTO agents (username, password, balance, created_by) VALUES (:username, :password, :balance, :created_by)";
    $stmt = $db->prepare($query);
    
    $stmt->bindParam(":username", $data->username);
    $stmt->bindParam(":password", $hashed_password);
    $stmt->bindParam(":balance", $data->initial_balance);
    $stmt->bindParam(":created_by", $data->admin_id);
    
    try {
        if($stmt->execute()) {
            $agent_id = $db->lastInsertId();
            
            // Create initial balance transaction record
            if($data->initial_balance > 0) {
                $trans_query = "INSERT INTO transactions (user_or_agent_id, type, amount, status) VALUES (:agent_id, 'deposit', :amount, 'completed')";
                $trans_stmt = $db->prepare($trans_query);
                $trans_stmt->bindParam(":agent_id", $agent_id);
                $trans_stmt->bindParam(":amount", $data->initial_balance);
                $trans_stmt->execute();
            }
            
            http_response_code(201);
            echo json_encode(array(
                "status" => "success",
                "message" => "Agent created successfully",
                "data" => array(
                    "id" => $agent_id,
                    "username" => $data->username,
                    "balance" => $data->initial_balance
                )
            ));
        } else {
            http_response_code(500);
            echo json_encode(array(
                "status" => "error",
                "message" => "Unable to create agent"
            ));
        }
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(array(
            "status" => "error",
            "message" => "Database error: " . $e->getMessage()
        ));
    }
} else {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => "Username, password, and initial balance are required"
    ));
}
?>
