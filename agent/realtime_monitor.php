<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

include_once '../../config/database.php';

$database = new Database();
$db = $database->connect();

try {
    if (!isset($_GET['agent_id'])) {
        throw new Exception("Agent ID is required");
    }

    $agent_id = $_GET['agent_id'];
    
    // Get active users in last 15 minutes
    $active_users_query = "
        WITH recent_activity AS (
            SELECT DISTINCT user_id, 'bet' as activity_type, created_at
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND created_at >= NOW() - INTERVAL '15 minutes'
            
            UNION
            
            SELECT DISTINCT user_or_agent_id, type, created_at
            FROM transactions t
            JOIN users u ON t.user_or_agent_id = u.id
            WHERE u.agent_id = :agent_id
            AND created_at >= NOW() - INTERVAL '15 minutes'
        )
        SELECT 
            u.id,
            u.name,
            u.phone_number,
            MAX(ra.created_at) as last_activity,
            STRING_AGG(DISTINCT ra.activity_type, ', ') as activities
        FROM users u
        JOIN recent_activity ra ON u.id = ra.user_id
        GROUP BY u.id, u.name, u.phone_number
        ORDER BY last_activity DESC";
    
    $active_users_stmt = $db->prepare($active_users_query);
    $active_users_stmt->bindParam(':agent_id', $agent_id);
    $active_users_stmt->execute();
    
    $active_users = array();
    while($row = $active_users_stmt->fetch(PDO::FETCH_ASSOC)) {
        $active_users[] = array(
            "id" => $row['id'],
            "name" => $row['name'],
            "phone_number" => $row['phone_number'],
            "last_activity" => $row['last_activity'],
            "activities" => explode(', ', $row['activities'])
        );
    }
    
    // Get recent transactions (last 15 minutes)
    $transactions_query = "
        SELECT 
            t.id,
            t.type,
            t.amount,
            t.status,
            t.created_at,
            u.name as user_name,
            u.phone_number as user_phone
        FROM transactions t
        JOIN users u ON t.user_or_agent_id = u.id
        WHERE u.agent_id = :agent_id
        AND t.created_at >= NOW() - INTERVAL '15 minutes'
        ORDER BY t.created_at DESC";
    
    $transactions_stmt = $db->prepare($transactions_query);
    $transactions_stmt->bindParam(':agent_id', $agent_id);
    $transactions_stmt->execute();
    
    $recent_transactions = array();
    while($row = $transactions_stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_transactions[] = array(
            "id" => $row['id'],
            "type" => $row['type'],
            "amount" => floatval($row['amount']),
            "status" => $row['status'],
            "created_at" => $row['created_at'],
            "user" => array(
                "name" => $row['user_name'],
                "phone" => $row['user_phone']
            )
        );
    }
    
    // Get recent bets (last 15 minutes)
    $bets_query = "
        SELECT 
            p.id,
            p.lottery_type,
            p.number,
            p.amount,
            p.created_at,
            u.name as user_name,
            u.phone_number as user_phone
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        AND p.created_at >= NOW() - INTERVAL '15 minutes'
        ORDER BY p.created_at DESC";
    
    $bets_stmt = $db->prepare($bets_query);
    $bets_stmt->bindParam(':agent_id', $agent_id);
    $bets_stmt->execute();
    
    $recent_bets = array();
    while($row = $bets_stmt->fetch(PDO::FETCH_ASSOC)) {
        $recent_bets[] = array(
            "id" => $row['id'],
            "lottery_type" => $row['lottery_type'],
            "number" => $row['number'],
            "amount" => floatval($row['amount']),
            "created_at" => $row['created_at'],
            "user" => array(
                "name" => $row['user_name'],
                "phone" => $row['user_phone']
            )
        );
    }
    
    // Get current system status
    $status_query = "
        SELECT 
            COUNT(DISTINCT CASE WHEN DATE(created_at) = CURRENT_DATE THEN user_id END) as active_users_today,
            COUNT(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 END) as total_bets_today,
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN amount ELSE 0 END), 0) as total_amount_today,
            COUNT(CASE WHEN created_at >= NOW() - INTERVAL '1 hour' THEN 1 END) as bets_last_hour
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id";
    
    $status_stmt = $db->prepare($status_query);
    $status_stmt->bindParam(':agent_id', $agent_id);
    $status_stmt->execute();
    
    $system_status = $status_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get alerts
    $alerts_query = "
        (
            -- High volume betting alert
            SELECT 
                'High Volume Betting' as alert_type,
                CONCAT(u.name, ' placed ', COUNT(*), ' bets in last 5 minutes') as message,
                'warning' as severity,
                MAX(p.created_at) as timestamp
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND p.created_at >= NOW() - INTERVAL '5 minutes'
            GROUP BY u.id, u.name
            HAVING COUNT(*) >= 10
        )
        
        UNION ALL
        
        (
            -- Large transaction alert
            SELECT 
                'Large Transaction' as alert_type,
                CONCAT(u.name, ' made a ', t.type, ' of ', t.amount, ' Ks') as message,
                'warning' as severity,
                t.created_at as timestamp
            FROM transactions t
            JOIN users u ON t.user_or_agent_id = u.id
            WHERE u.agent_id = :agent_id
            AND t.created_at >= NOW() - INTERVAL '15 minutes'
            AND t.amount >= 1000000
        )
        
        UNION ALL
        
        (
            -- Winning streak alert
            SELECT 
                'Winning Streak' as alert_type,
                CONCAT(u.name, ' won ', COUNT(*), ' bets in a row') as message,
                'warning' as severity,
                MAX(p.created_at) as timestamp
            FROM (
                SELECT 
                    user_id,
                    created_at,
                    ROW_NUMBER() OVER (PARTITION BY user_id ORDER BY created_at) as streak_num
                FROM playbets
                WHERE result = 'win'
                AND created_at >= NOW() - INTERVAL '24 hours'
            ) p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            GROUP BY u.id, u.name
            HAVING COUNT(*) >= 5
        )
        
        ORDER BY timestamp DESC";
    
    $alerts_stmt = $db->prepare($alerts_query);
    $alerts_stmt->bindParam(':agent_id', $agent_id);
    $alerts_stmt->execute();
    
    $alerts = array();
    while($row = $alerts_stmt->fetch(PDO::FETCH_ASSOC)) {
        $alerts[] = array(
            "type" => $row['alert_type'],
            "message" => $row['message'],
            "severity" => $row['severity'],
            "timestamp" => $row['timestamp']
        );
    }

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "active_users" => $active_users,
            "recent_activity" => array(
                "transactions" => $recent_transactions,
                "bets" => $recent_bets
            ),
            "system_status" => array(
                "active_users_today" => (int)$system_status['active_users_today'],
                "total_bets_today" => (int)$system_status['total_bets_today'],
                "total_amount_today" => floatval($system_status['total_amount_today']),
                "bets_last_hour" => (int)$system_status['bets_last_hour']
            ),
            "alerts" => $alerts
        ),
        "timestamp" => date('Y-m-d H:i:s')
    ));

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
