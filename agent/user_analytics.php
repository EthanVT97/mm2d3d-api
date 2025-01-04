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
    $user_id = isset($_GET['user_id']) ? $_GET['user_id'] : null;
    
    if ($user_id) {
        // Get detailed user analytics
        $user_query = "
            SELECT 
                u.id,
                u.name,
                u.phone_number,
                u.balance,
                u.created_at,
                COUNT(DISTINCT p.id) as total_bets,
                COALESCE(SUM(p.amount), 0) as total_bet_amount,
                COUNT(DISTINCT CASE WHEN p.result = 'win' THEN p.id END) as winning_bets,
                COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_winnings,
                COUNT(DISTINCT CASE WHEN DATE(p.created_at) = CURRENT_DATE THEN p.id END) as today_bets,
                COALESCE(SUM(CASE WHEN DATE(p.created_at) = CURRENT_DATE THEN p.amount ELSE 0 END), 0) as today_bet_amount
            FROM users u
            LEFT JOIN playbets p ON u.id = p.user_id
            WHERE u.id = :user_id AND u.agent_id = :agent_id
            GROUP BY u.id";
        
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':user_id', $user_id);
        $user_stmt->bindParam(':agent_id', $agent_id);
        $user_stmt->execute();
        
        if ($user_stmt->rowCount() === 0) {
            throw new Exception("User not found or doesn't belong to this agent");
        }
        
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get betting patterns
        $patterns_query = "
            SELECT 
                lottery_type,
                COUNT(*) as bet_count,
                COALESCE(SUM(amount), 0) as total_amount,
                COUNT(CASE WHEN result = 'win' THEN 1 END) as wins,
                COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as total_winnings
            FROM playbets
            WHERE user_id = :user_id
            GROUP BY lottery_type";
        
        $patterns_stmt = $db->prepare($patterns_query);
        $patterns_stmt->bindParam(':user_id', $user_id);
        $patterns_stmt->execute();
        
        $betting_patterns = array();
        while($row = $patterns_stmt->fetch(PDO::FETCH_ASSOC)) {
            $pattern_item = array(
                "lottery_type" => $row['lottery_type'],
                "bet_count" => (int)$row['bet_count'],
                "total_amount" => floatval($row['total_amount']),
                "wins" => (int)$row['wins'],
                "total_winnings" => floatval($row['total_winnings']),
                "win_rate" => $row['bet_count'] > 0 ? round(($row['wins'] / $row['bet_count']) * 100, 2) : 0
            );
            array_push($betting_patterns, $pattern_item);
        }
        
        // Get transaction history
        $transactions_query = "
            SELECT 
                type,
                COUNT(*) as count,
                COALESCE(SUM(amount), 0) as total_amount,
                MIN(amount) as min_amount,
                MAX(amount) as max_amount,
                AVG(amount) as avg_amount
            FROM transactions
            WHERE user_or_agent_id = :user_id
            GROUP BY type";
        
        $transactions_stmt = $db->prepare($transactions_query);
        $transactions_stmt->bindParam(':user_id', $user_id);
        $transactions_stmt->execute();
        
        $transaction_stats = array();
        while($row = $transactions_stmt->fetch(PDO::FETCH_ASSOC)) {
            $transaction_stats[$row['type']] = array(
                "count" => (int)$row['count'],
                "total_amount" => floatval($row['total_amount']),
                "min_amount" => floatval($row['min_amount']),
                "max_amount" => floatval($row['max_amount']),
                "avg_amount" => floatval($row['avg_amount'])
            );
        }
        
        // Get activity timeline
        $timeline_query = "
            (SELECT 
                'bet' as activity_type,
                amount as value,
                created_at as timestamp,
                lottery_type as details
            FROM playbets
            WHERE user_id = :user_id)
            
            UNION ALL
            
            (SELECT 
                type as activity_type,
                amount as value,
                created_at as timestamp,
                reference_number as details
            FROM transactions
            WHERE user_or_agent_id = :user_id)
            
            ORDER BY timestamp DESC
            LIMIT 50";
        
        $timeline_stmt = $db->prepare($timeline_query);
        $timeline_stmt->bindParam(':user_id', $user_id);
        $timeline_stmt->execute();
        
        $activity_timeline = array();
        while($row = $timeline_stmt->fetch(PDO::FETCH_ASSOC)) {
            $timeline_item = array(
                "type" => $row['activity_type'],
                "value" => floatval($row['value']),
                "timestamp" => $row['timestamp'],
                "details" => $row['details']
            );
            array_push($activity_timeline, $timeline_item);
        }
        
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "data" => array(
                "user" => array(
                    "id" => $user_data['id'],
                    "name" => $user_data['name'],
                    "phone_number" => $user_data['phone_number'],
                    "balance" => floatval($user_data['balance']),
                    "created_at" => $user_data['created_at'],
                    "betting_stats" => array(
                        "total_bets" => (int)$user_data['total_bets'],
                        "total_bet_amount" => floatval($user_data['total_bet_amount']),
                        "winning_bets" => (int)$user_data['winning_bets'],
                        "total_winnings" => floatval($user_data['total_winnings']),
                        "today_bets" => (int)$user_data['today_bets'],
                        "today_bet_amount" => floatval($user_data['today_bet_amount']),
                        "win_rate" => $user_data['total_bets'] > 0 ? 
                            round(($user_data['winning_bets'] / $user_data['total_bets']) * 100, 2) : 0
                    )
                ),
                "betting_patterns" => $betting_patterns,
                "transaction_stats" => $transaction_stats,
                "activity_timeline" => $activity_timeline
            )
        ));
        
    } else {
        // Get overall user analytics
        $analytics_query = "
            SELECT 
                COUNT(*) as total_users,
                COUNT(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 END) as new_users_today,
                COUNT(CASE WHEN DATE(created_at) >= DATE_TRUNC('month', CURRENT_DATE) THEN 1 END) as new_users_this_month,
                COALESCE(SUM(balance), 0) as total_balance,
                AVG(balance) as avg_balance
            FROM users
            WHERE agent_id = :agent_id";
        
        $analytics_stmt = $db->prepare($analytics_query);
        $analytics_stmt->bindParam(':agent_id', $agent_id);
        $analytics_stmt->execute();
        $analytics = $analytics_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get user segments
        $segments_query = "
            SELECT 
                CASE 
                    WHEN total_bets >= 100 THEN 'VIP'
                    WHEN total_bets >= 50 THEN 'Regular'
                    WHEN total_bets >= 10 THEN 'Active'
                    ELSE 'New'
                END as segment,
                COUNT(*) as user_count
            FROM (
                SELECT 
                    u.id,
                    COUNT(p.id) as total_bets
                FROM users u
                LEFT JOIN playbets p ON u.id = p.user_id
                WHERE u.agent_id = :agent_id
                GROUP BY u.id
            ) user_stats
            GROUP BY segment";
        
        $segments_stmt = $db->prepare($segments_query);
        $segments_stmt->bindParam(':agent_id', $agent_id);
        $segments_stmt->execute();
        
        $user_segments = array();
        while($row = $segments_stmt->fetch(PDO::FETCH_ASSOC)) {
            $user_segments[$row['segment']] = (int)$row['user_count'];
        }
        
        // Get retention metrics
        $retention_query = "
            WITH user_activity AS (
                SELECT 
                    u.id,
                    DATE(u.created_at) as join_date,
                    MAX(DATE(COALESCE(p.created_at, t.created_at))) as last_activity
                FROM users u
                LEFT JOIN playbets p ON u.id = p.user_id
                LEFT JOIN transactions t ON u.id = t.user_or_agent_id
                WHERE u.agent_id = :agent_id
                GROUP BY u.id, DATE(u.created_at)
            )
            SELECT 
                COUNT(CASE WHEN last_activity >= CURRENT_DATE - INTERVAL '1 day' THEN 1 END) as active_24h,
                COUNT(CASE WHEN last_activity >= CURRENT_DATE - INTERVAL '7 days' THEN 1 END) as active_7d,
                COUNT(CASE WHEN last_activity >= CURRENT_DATE - INTERVAL '30 days' THEN 1 END) as active_30d
            FROM user_activity";
        
        $retention_stmt = $db->prepare($retention_query);
        $retention_stmt->bindParam(':agent_id', $agent_id);
        $retention_stmt->execute();
        $retention = $retention_stmt->fetch(PDO::FETCH_ASSOC);
        
        http_response_code(200);
        echo json_encode(array(
            "status" => "success",
            "data" => array(
                "overview" => array(
                    "total_users" => (int)$analytics['total_users'],
                    "new_users_today" => (int)$analytics['new_users_today'],
                    "new_users_this_month" => (int)$analytics['new_users_this_month'],
                    "total_balance" => floatval($analytics['total_balance']),
                    "avg_balance" => floatval($analytics['avg_balance'])
                ),
                "segments" => $user_segments,
                "retention" => array(
                    "active_24h" => (int)$retention['active_24h'],
                    "active_7d" => (int)$retention['active_7d'],
                    "active_30d" => (int)$retention['active_30d']
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
