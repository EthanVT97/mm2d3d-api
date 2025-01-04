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
    
    // Detect suspicious betting patterns
    $suspicious_patterns_query = "
        WITH user_betting_stats AS (
            SELECT 
                u.id as user_id,
                u.name,
                u.phone_number,
                COUNT(*) as bet_count,
                AVG(amount) as avg_bet_amount,
                STDDEV(amount) as bet_amount_stddev,
                COUNT(DISTINCT lottery_type) as unique_lottery_types,
                COUNT(DISTINCT number) as unique_numbers,
                COUNT(CASE WHEN result = 'win' THEN 1 END)::float / NULLIF(COUNT(*), 0) as win_rate
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND p.created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY u.id, u.name, u.phone_number
        )
        SELECT 
            user_id,
            name,
            phone_number,
            bet_count,
            avg_bet_amount,
            bet_amount_stddev,
            unique_lottery_types,
            unique_numbers,
            win_rate,
            ARRAY_TO_STRING(ARRAY[
                CASE WHEN bet_count > 100 THEN 'High betting frequency' END,
                CASE WHEN win_rate > 0.7 THEN 'Unusually high win rate' END,
                CASE WHEN unique_numbers < 3 AND bet_count > 20 THEN 'Limited number selection' END,
                CASE WHEN bet_amount_stddev = 0 AND bet_count > 10 THEN 'Identical bet amounts' END
            ], ', ') as suspicious_indicators
        FROM user_betting_stats
        WHERE 
            bet_count > 100 OR
            win_rate > 0.7 OR
            (unique_numbers < 3 AND bet_count > 20) OR
            (bet_amount_stddev = 0 AND bet_count > 10)
        ORDER BY bet_count DESC";
    
    $suspicious_patterns_stmt = $db->prepare($suspicious_patterns_query);
    $suspicious_patterns_stmt->bindParam(':agent_id', $agent_id);
    $suspicious_patterns_stmt->execute();
    
    $suspicious_patterns = array();
    while($row = $suspicious_patterns_stmt->fetch(PDO::FETCH_ASSOC)) {
        $suspicious_patterns[] = array(
            "user" => array(
                "id" => $row['user_id'],
                "name" => $row['name'],
                "phone_number" => $row['phone_number']
            ),
            "stats" => array(
                "bet_count" => (int)$row['bet_count'],
                "avg_bet_amount" => round(floatval($row['avg_bet_amount']), 2),
                "bet_amount_stddev" => round(floatval($row['bet_amount_stddev']), 2),
                "unique_lottery_types" => (int)$row['unique_lottery_types'],
                "unique_numbers" => (int)$row['unique_numbers'],
                "win_rate" => round(floatval($row['win_rate']), 2)
            ),
            "suspicious_indicators" => array_filter(explode(', ', $row['suspicious_indicators']))
        );
    }
    
    // Detect rapid multiple accounts
    $multiple_accounts_query = "
        WITH user_devices AS (
            SELECT 
                device_id,
                COUNT(DISTINCT user_id) as user_count,
                STRING_AGG(DISTINCT name, ', ') as user_names,
                STRING_AGG(DISTINCT phone_number, ', ') as phone_numbers,
                MAX(created_at) as latest_activity
            FROM user_sessions s
            JOIN users u ON s.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND s.created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY device_id
            HAVING COUNT(DISTINCT user_id) > 1
        )
        SELECT *,
        CASE 
            WHEN user_count >= 5 THEN 'High'
            WHEN user_count >= 3 THEN 'Medium'
            ELSE 'Low'
        END as risk_level
        FROM user_devices
        ORDER BY user_count DESC";
    
    $multiple_accounts_stmt = $db->prepare($multiple_accounts_query);
    $multiple_accounts_stmt->bindParam(':agent_id', $agent_id);
    $multiple_accounts_stmt->execute();
    
    $multiple_accounts = array();
    while($row = $multiple_accounts_stmt->fetch(PDO::FETCH_ASSOC)) {
        $multiple_accounts[] = array(
            "device_id" => $row['device_id'],
            "user_count" => (int)$row['user_count'],
            "user_names" => explode(', ', $row['user_names']),
            "phone_numbers" => explode(', ', $row['phone_numbers']),
            "latest_activity" => $row['latest_activity'],
            "risk_level" => $row['risk_level']
        );
    }
    
    // Detect unusual transaction patterns
    $unusual_transactions_query = "
        WITH user_transactions AS (
            SELECT 
                u.id as user_id,
                u.name,
                u.phone_number,
                t.type,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                AVG(amount) as avg_amount,
                STDDEV(amount) as amount_stddev,
                MAX(amount) as max_amount,
                MIN(EXTRACT(EPOCH FROM (LEAD(created_at) OVER (PARTITION BY user_or_agent_id ORDER BY created_at) - created_at))) as min_interval
            FROM transactions t
            JOIN users u ON t.user_or_agent_id = u.id
            WHERE u.agent_id = :agent_id
            AND t.created_at >= NOW() - INTERVAL '24 hours'
            GROUP BY u.id, u.name, u.phone_number, t.type
        )
        SELECT *
        FROM user_transactions
        WHERE 
            (transaction_count > 20) OR
            (total_amount > 10000000) OR
            (min_interval < 60) OR
            (amount_stddev = 0 AND transaction_count > 5)
        ORDER BY transaction_count DESC";
    
    $unusual_transactions_stmt = $db->prepare($unusual_transactions_query);
    $unusual_transactions_stmt->bindParam(':agent_id', $agent_id);
    $unusual_transactions_stmt->execute();
    
    $unusual_transactions = array();
    while($row = $unusual_transactions_stmt->fetch(PDO::FETCH_ASSOC)) {
        $unusual_transactions[] = array(
            "user" => array(
                "id" => $row['user_id'],
                "name" => $row['name'],
                "phone_number" => $row['phone_number']
            ),
            "transaction_type" => $row['type'],
            "stats" => array(
                "transaction_count" => (int)$row['transaction_count'],
                "total_amount" => round(floatval($row['total_amount']), 2),
                "avg_amount" => round(floatval($row['avg_amount']), 2),
                "amount_stddev" => round(floatval($row['amount_stddev']), 2),
                "max_amount" => round(floatval($row['max_amount']), 2),
                "min_interval_seconds" => round(floatval($row['min_interval']), 2)
            ),
            "risk_indicators" => array(
                "high_frequency" => (int)$row['transaction_count'] > 20,
                "large_volume" => floatval($row['total_amount']) > 10000000,
                "rapid_succession" => floatval($row['min_interval']) < 60,
                "identical_amounts" => floatval($row['amount_stddev']) == 0 && (int)$row['transaction_count'] > 5
            )
        );
    }
    
    // Calculate risk scores
    $risk_scores_query = "
        WITH user_metrics AS (
            SELECT 
                u.id,
                u.name,
                u.phone_number,
                COUNT(DISTINCT p.id) as total_bets,
                COALESCE(SUM(p.amount), 0) as total_bet_amount,
                COUNT(DISTINCT CASE WHEN p.result = 'win' THEN p.id END) as winning_bets,
                COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_winnings,
                COUNT(DISTINCT t.id) as total_transactions,
                COALESCE(SUM(t.amount), 0) as total_transaction_amount
            FROM users u
            LEFT JOIN playbets p ON u.id = p.user_id
            LEFT JOIN transactions t ON u.id = t.user_or_agent_id
            WHERE u.agent_id = :agent_id
            AND (p.created_at >= NOW() - INTERVAL '24 hours' OR t.created_at >= NOW() - INTERVAL '24 hours')
            GROUP BY u.id, u.name, u.phone_number
        )
        SELECT 
            id,
            name,
            phone_number,
            total_bets,
            total_bet_amount,
            winning_bets,
            total_winnings,
            total_transactions,
            total_transaction_amount,
            CASE
                WHEN (winning_bets::float / NULLIF(total_bets, 0) > 0.7) OR
                     (total_bet_amount > 5000000) OR
                     (total_transaction_amount > 10000000) OR
                     (total_transactions > 50) THEN 'High'
                WHEN (winning_bets::float / NULLIF(total_bets, 0) > 0.5) OR
                     (total_bet_amount > 1000000) OR
                     (total_transaction_amount > 5000000) OR
                     (total_transactions > 20) THEN 'Medium'
                ELSE 'Low'
            END as risk_level
        FROM user_metrics
        WHERE total_bets > 0 OR total_transactions > 0
        ORDER BY 
            CASE 
                WHEN risk_level = 'High' THEN 1
                WHEN risk_level = 'Medium' THEN 2
                ELSE 3
            END";
    
    $risk_scores_stmt = $db->prepare($risk_scores_query);
    $risk_scores_stmt->bindParam(':agent_id', $agent_id);
    $risk_scores_stmt->execute();
    
    $risk_scores = array();
    while($row = $risk_scores_stmt->fetch(PDO::FETCH_ASSOC)) {
        $risk_scores[] = array(
            "user" => array(
                "id" => $row['id'],
                "name" => $row['name'],
                "phone_number" => $row['phone_number']
            ),
            "metrics" => array(
                "total_bets" => (int)$row['total_bets'],
                "total_bet_amount" => round(floatval($row['total_bet_amount']), 2),
                "winning_bets" => (int)$row['winning_bets'],
                "total_winnings" => round(floatval($row['total_winnings']), 2),
                "total_transactions" => (int)$row['total_transactions'],
                "total_transaction_amount" => round(floatval($row['total_transaction_amount']), 2)
            ),
            "risk_level" => $row['risk_level']
        );
    }

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "suspicious_patterns" => $suspicious_patterns,
            "multiple_accounts" => $multiple_accounts,
            "unusual_transactions" => $unusual_transactions,
            "risk_scores" => $risk_scores,
            "summary" => array(
                "total_suspicious_users" => count($suspicious_patterns),
                "total_multiple_accounts" => count($multiple_accounts),
                "total_unusual_transactions" => count($unusual_transactions),
                "risk_distribution" => array(
                    "high" => count(array_filter($risk_scores, function($user) { return $user['risk_level'] === 'High'; })),
                    "medium" => count(array_filter($risk_scores, function($user) { return $user['risk_level'] === 'Medium'; })),
                    "low" => count(array_filter($risk_scores, function($user) { return $user['risk_level'] === 'Low'; }))
                )
            )
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
