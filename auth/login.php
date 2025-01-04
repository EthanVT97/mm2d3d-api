<?php
require_once '../../config/cors.php';
cors();

header("Content-Type: application/json; charset=UTF-8");

include_once '../../config/database.php';
include_once '../../config/jwt.php';

$database = new Database();
$db = $database->connect();
$jwt = new JWT();

// Get posted data
$data = json_decode(file_get_contents("php://input"));

if(!empty($data->phone_number) && !empty($data->password)) {
    $phone_number = $data->phone_number;
    $password = $data->password;
    
    $query = "SELECT id, phone_number, name, password, balance, status FROM users WHERE phone_number = :phone_number";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":phone_number", $phone_number);
    
    try {
        $stmt->execute();
        
        if($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(password_verify($password, $row['password'])) {
                // Check if account is active
                if($row['status'] !== 'active') {
                    http_response_code(403);
                    echo json_encode(array(
                        "status" => "error",
                        "message" => "Account is " . $row['status']
                    ));
                    exit();
                }
                
                // Generate JWT token
                $token = $jwt->generate_token([
                    'user_id' => $row['id'],
                    'phone_number' => $row['phone_number'],
                    'name' => $row['name']
                ]);
                
                // Update last login timestamp
                $update_query = "UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(":id", $row['id']);
                $update_stmt->execute();
                
                // Update analytics
                $analytics_query = "UPDATE user_analytics 
                                  SET login_count = login_count + 1, 
                                      updated_at = CURRENT_TIMESTAMP 
                                  WHERE user_id = :user_id";
                $analytics_stmt = $db->prepare($analytics_query);
                $analytics_stmt->bindParam(":user_id", $row['id']);
                $analytics_stmt->execute();
                
                http_response_code(200);
                echo json_encode(array(
                    "status" => "success",
                    "message" => "Login successful",
                    "data" => array(
                        "id" => $row['id'],
                        "phone_number" => $row['phone_number'],
                        "name" => $row['name'],
                        "balance" => $row['balance'],
                        "token" => $token
                    )
                ));
            } else {
                http_response_code(401);
                echo json_encode(array(
                    "status" => "error",
                    "message" => "Invalid password"
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
} else {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => "Missing phone number or password"
    ));
}
?>
