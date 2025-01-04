<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../../config/database.php';

$database = new Database();
$db = $database->connect();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Handle transaction creation
        $data = json_decode(file_get_contents("php://input"), true);
        
        if (!isset($data['agent_id']) || !isset($data['user_id']) || !isset($data['amount']) || !isset($data['type'])) {
            throw new Exception("Missing required fields");
        }
        
        $db->beginTransaction();
        
        try {
            // Verify agent and user
            $verify_query = "
                SELECT u.id, u.balance, a.balance as agent_balance 
                FROM users u 
                JOIN agents a ON u.agent_id = a.id
                WHERE u.id = :user_id AND a.id = :agent_id";
            $verify_stmt = $db->prepare($verify_query);
            $verify_stmt->bindParam(':user_id', $data['user_id']);
            $verify_stmt->bindParam(':agent_id', $data['agent_id']);
            $verify_stmt->execute();
            
            if ($verify_stmt->rowCount() === 0) {
                throw new Exception("Invalid user or agent");
            }
            
            $account = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check balances
            if ($data['type'] === 'withdraw') {
                if ($account['balance'] < $data['amount']) {
                    throw new Exception("Insufficient user balance");
                }
            } else if ($data['type'] === 'deposit') {
                if ($account['agent_balance'] < $data['amount']) {
                    throw new Exception("Insufficient agent balance");
                }
            }
            
            // Create transaction
            $transaction_query = "
                INSERT INTO transactions (user_or_agent_id, type, amount, status, reference_number)
                VALUES (:user_id, :type, :amount, 'completed', :reference)
                RETURNING id";
            
            $reference = uniqid('TXN');
            $transaction_stmt = $db->prepare($transaction_query);
            $transaction_stmt->bindParam(':user_id', $data['user_id']);
            $transaction_stmt->bindParam(':type', $data['type']);
            $transaction_stmt->bindParam(':amount', $data['amount']);
            $transaction_stmt->bindParam(':reference', $reference);
            $transaction_stmt->execute();
            
            // Update balances
            if ($data['type'] === 'deposit') {
                // Add to user balance, subtract from agent
                $update_user = "UPDATE users SET balance = balance + :amount WHERE id = :user_id";
                $update_agent = "UPDATE agents SET balance = balance - :amount WHERE id = :agent_id";
            } else {
                // Subtract from user balance, add to agent
                $update_user = "UPDATE users SET balance = balance - :amount WHERE id = :user_id";
                $update_agent = "UPDATE agents SET balance = balance + :amount WHERE id = :agent_id";
            }
            
            $update_user_stmt = $db->prepare($update_user);
            $update_user_stmt->bindParam(':amount', $data['amount']);
            $update_user_stmt->bindParam(':user_id', $data['user_id']);
            $update_user_stmt->execute();
            
            $update_agent_stmt = $db->prepare($update_agent);
            $update_agent_stmt->bindParam(':amount', $data['amount']);
            $update_agent_stmt->bindParam(':agent_id', $data['agent_id']);
            $update_agent_stmt->execute();
            
            $db->commit();
            
            // Get updated balances
            $balance_query = "
                SELECT u.balance as user_balance, a.balance as agent_balance
                FROM users u 
                JOIN agents a ON u.agent_id = a.id
                WHERE u.id = :user_id AND a.id = :agent_id";
            $balance_stmt = $db->prepare($balance_query);
            $balance_stmt->bindParam(':user_id', $data['user_id']);
            $balance_stmt->bindParam(':agent_id', $data['agent_id']);
            $balance_stmt->execute();
            $balances = $balance_stmt->fetch(PDO::FETCH_ASSOC);
            
            http_response_code(200);
            echo json_encode(array(
                "status" => "success",
                "message" => "Transaction completed successfully",
                "data" => array(
                    "reference" => $reference,
                    "user_balance" => floatval($balances['user_balance']),
                    "agent_balance" => floatval($balances['agent_balance'])
                )
            ));
            
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
        
    } else {
        // Handle transaction listing
        if (!isset($_GET['agent_id'])) {
            throw new Exception("Agent ID is required");
        }
        
        $agent_id = $_GET['agent_id'];
        $conditions = array("u.agent_id = :agent_id");
        $params = array(':agent_id' => $agent_id);
        
        // Filter by type
        if (isset($_GET['type']) && $_GET['type'] !== 'all') {
            $conditions[] = "t.type = :type";
            $params[':type'] = $_GET['type'];
        }
        
        // Filter by date
        if (isset($_GET['date'])) {
            $conditions[] = "DATE(t.created_at) = :date";
            $params[':date'] = $_GET['date'];
        }
        
        // Filter by status
        if (isset($_GET['status'])) {
            $conditions[] = "t.status = :status";
            $params[':status'] = $_GET['status'];
        }
        
        $where_clause = implode(" AND ", $conditions);
        
        $query = "
            SELECT 
                t.id,
                t.type,
                t.amount,
                t.status,
                t.reference_number,
                t.created_at,
                u.name as user_name,
                u.phone_number as user_phone
            FROM transactions t
            JOIN users u ON t.user_or_agent_id = u.id
            WHERE $where_clause
            ORDER BY t.created_at DESC";
        
        // Add pagination
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
        $offset = ($page - 1) * $per_page;
        
        $query .= " LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($query);
        foreach($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        // Get total count for pagination
        $count_query = "
            SELECT COUNT(*) as total 
            FROM transactions t
            JOIN users u ON t.user_or_agent_id = u.id
            WHERE $where_clause";
        $count_stmt = $db->prepare($count_query);
        foreach($params as $key => $value) {
            $count_stmt->bindValue($key, $value);
        }
        $count_stmt->execute();
        $total_transactions = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $transactions = array();
        while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $transaction_item = array(
                "id" => $row['id'],
                "type" => $row['type'],
                "amount" => floatval($row['amount']),
                "status" => $row['status'],
                "reference_number" => $row['reference_number'],
                "created_at" => $row['created_at'],
                "user" => array(
                    "name" => $row['user_name'],
                    "phone" => $row['user_phone']
                )
            );
            array_push($transactions, $transaction_item);
        }
        
        // Get summary statistics
        $stats_query = "
            SELECT 
                COUNT(*) as total_count,
                COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
                COALESCE(SUM(CASE WHEN type = 'withdraw' THEN amount ELSE 0 END), 0) as total_withdrawals,
                COUNT(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 END) as today_count,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE AND type = 'deposit' THEN amount ELSE 0 END), 0) as today_deposits,
                COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE AND type = 'withdraw' THEN amount ELSE 0 END), 0) as today_withdrawals
            FROM transactions t
            JOIN users u ON t.user_or_agent_id = u.id
            WHERE $where_clause";
        
        $stats_stmt = $db->prepare($stats_query);
        foreach($params as $key => $value) {
            $stats_stmt->bindValue($key, $value);
        }
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
        
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "data" => array(
                "transactions" => $transactions,
                "stats" => array(
                    "total_count" => (int)$stats['total_count'],
                    "total_deposits" => floatval($stats['total_deposits']),
                    "total_withdrawals" => floatval($stats['total_withdrawals']),
                    "today_count" => (int)$stats['today_count'],
                    "today_deposits" => floatval($stats['today_deposits']),
                    "today_withdrawals" => floatval($stats['today_withdrawals'])
                ),
                "pagination" => array(
                    "total" => (int)$total_transactions,
                    "per_page" => $per_page,
                    "current_page" => $page,
                    "total_pages" => ceil($total_transactions / $per_page)
                )
            )
        ));
    }

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ));
} catch(Exception $e) {
    http_response_code(400);
    echo json_encode(array(
        "status" => "error",
        "message" => $e->getMessage()
    ));
}
?>
