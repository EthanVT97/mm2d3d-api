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

if(!empty($data->lottery_type) && !empty($data->winning_numbers) && !empty($data->draw_date)) {
    try {
        // Begin transaction
        $db->beginTransaction();
        
        // Insert new result
        $query = "INSERT INTO results (lottery_type, winning_numbers, draw_date) VALUES (:lottery_type, :winning_numbers, :draw_date)";
        $stmt = $db->prepare($query);
        
        $stmt->bindParam(":lottery_type", $data->lottery_type);
        $stmt->bindParam(":winning_numbers", $data->winning_numbers);
        $stmt->bindParam(":draw_date", $data->draw_date);
        
        if($stmt->execute()) {
            // Update playbets with matching numbers
            $update_query = "
                UPDATE playbets 
                SET result = CASE 
                    WHEN number_selected = :winning_numbers THEN 'win'
                    ELSE 'lose'
                END
                WHERE lottery_type = :lottery_type 
                AND DATE(created_at) = :draw_date
                AND result = 'pending'";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(":winning_numbers", $data->winning_numbers);
            $update_stmt->bindParam(":lottery_type", $data->lottery_type);
            $update_stmt->bindParam(":draw_date", $data->draw_date);
            $update_stmt->execute();
            
            // Process winning bets
            $winning_query = "
                SELECT user_id, bet_amount 
                FROM playbets 
                WHERE lottery_type = :lottery_type 
                AND DATE(created_at) = :draw_date
                AND result = 'win'";
            
            $winning_stmt = $db->prepare($winning_query);
            $winning_stmt->bindParam(":lottery_type", $data->lottery_type);
            $winning_stmt->bindParam(":draw_date", $data->draw_date);
            $winning_stmt->execute();
            
            while($winner = $winning_stmt->fetch(PDO::FETCH_ASSOC)) {
                // Calculate winning amount based on lottery type
                $winning_amount = calculateWinningAmount($data->lottery_type, $winner['bet_amount']);
                
                // Update user balance
                $balance_query = "
                    UPDATE users 
                    SET balance = balance + :winning_amount 
                    WHERE id = :user_id";
                
                $balance_stmt = $db->prepare($balance_query);
                $balance_stmt->bindParam(":winning_amount", $winning_amount);
                $balance_stmt->bindParam(":user_id", $winner['user_id']);
                $balance_stmt->execute();
                
                // Create transaction record
                $trans_query = "
                    INSERT INTO transactions (user_or_agent_id, type, amount, status) 
                    VALUES (:user_id, 'winning', :amount, 'completed')";
                
                $trans_stmt = $db->prepare($trans_query);
                $trans_stmt->bindParam(":user_id", $winner['user_id']);
                $trans_stmt->bindParam(":amount", $winning_amount);
                $trans_stmt->execute();
            }
            
            // Commit transaction
            $db->commit();
            
            http_response_code(201);
            echo json_encode(array(
                "status" => "success",
                "message" => "Result created and processed successfully"
            ));
        } else {
            $db->rollBack();
            http_response_code(500);
            echo json_encode(array(
                "status" => "error",
                "message" => "Unable to create result"
            ));
        }
    } catch(PDOException $e) {
        $db->rollBack();
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
        "message" => "Lottery type, winning numbers, and draw date are required"
    ));
}

// Helper function to calculate winning amount
function calculateWinningAmount($lottery_type, $bet_amount) {
    switch($lottery_type) {
        case '2D':
            return $bet_amount * 85; // 85 times payout for 2D
        case '3D':
            return $bet_amount * 500; // 500 times payout for 3D
        case 'Thai':
        case 'Lao':
            return $bet_amount * 90; // 90 times payout for Thai and Lao
        default:
            return 0;
    }
}
?>
