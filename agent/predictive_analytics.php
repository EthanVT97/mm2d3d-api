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
    
    // Predict user churn risk
    $churn_query = "
        WITH user_activity AS (
            SELECT 
                u.id,
                u.name,
                u.phone_number,
                MAX(COALESCE(p.created_at, t.created_at)) as last_activity,
                COUNT(DISTINCT p.id) as total_bets,
                COALESCE(SUM(p.amount), 0) as total_bet_amount,
                COUNT(DISTINCT CASE WHEN p.result = 'win' THEN p.id END) as winning_bets,
                COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_winnings
            FROM users u
            LEFT JOIN playbets p ON u.id = p.user_id
            LEFT JOIN transactions t ON u.id = t.user_or_agent_id
            WHERE u.agent_id = :agent_id
            GROUP BY u.id, u.name, u.phone_number
        )
        SELECT 
            id,
            name,
            phone_number,
            CASE 
                WHEN last_activity < CURRENT_TIMESTAMP - INTERVAL '30 days' THEN 'High'
                WHEN last_activity < CURRENT_TIMESTAMP - INTERVAL '14 days' THEN 'Medium'
                WHEN last_activity < CURRENT_TIMESTAMP - INTERVAL '7 days' THEN 'Low'
                ELSE 'None'
            END as churn_risk,
            EXTRACT(DAY FROM (CURRENT_TIMESTAMP - last_activity)) as days_inactive,
            total_bets,
            total_bet_amount,
            winning_bets,
            total_winnings
        FROM user_activity
        WHERE last_activity < CURRENT_TIMESTAMP - INTERVAL '7 days'
        ORDER BY days_inactive DESC";
    
    $churn_stmt = $db->prepare($churn_query);
    $churn_stmt->bindParam(':agent_id', $agent_id);
    $churn_stmt->execute();
    
    $churn_risk = array();
    while($row = $churn_stmt->fetch(PDO::FETCH_ASSOC)) {
        $risk_item = array(
            "user" => array(
                "id" => $row['id'],
                "name" => $row['name'],
                "phone_number" => $row['phone_number']
            ),
            "risk_level" => $row['churn_risk'],
            "days_inactive" => (int)$row['days_inactive'],
            "stats" => array(
                "total_bets" => (int)$row['total_bets'],
                "total_bet_amount" => floatval($row['total_bet_amount']),
                "winning_bets" => (int)$row['winning_bets'],
                "total_winnings" => floatval($row['total_winnings'])
            )
        );
        array_push($churn_risk, $risk_item);
    }
    
    // Predict revenue trends
    $revenue_query = "
        WITH daily_revenue AS (
            SELECT 
                DATE(created_at) as date,
                COALESCE(SUM(amount), 0) - COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as net_revenue
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND DATE(created_at) >= CURRENT_DATE - INTERVAL '30 days'
            GROUP BY DATE(created_at)
        )
        SELECT 
            AVG(net_revenue) as avg_daily_revenue,
            PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY net_revenue) as median_daily_revenue,
            STDDEV(net_revenue) as revenue_volatility,
            REGR_SLOPE(net_revenue, EXTRACT(EPOCH FROM date::timestamp)) as trend_slope
        FROM daily_revenue";
    
    $revenue_stmt = $db->prepare($revenue_query);
    $revenue_stmt->bindParam(':agent_id', $agent_id);
    $revenue_stmt->execute();
    
    $revenue_trends = $revenue_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Predict user betting patterns
    $patterns_query = "
        WITH user_patterns AS (
            SELECT 
                user_id,
                lottery_type,
                number,
                COUNT(*) as frequency
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND DATE(p.created_at) >= CURRENT_DATE - INTERVAL '30 days'
            GROUP BY user_id, lottery_type, number
            HAVING COUNT(*) >= 3
        )
        SELECT 
            u.name,
            u.phone_number,
            p.lottery_type,
            p.number,
            p.frequency
        FROM user_patterns p
        JOIN users u ON p.user_id = u.id
        ORDER BY p.frequency DESC
        LIMIT 20";
    
    $patterns_stmt = $db->prepare($patterns_query);
    $patterns_stmt->bindParam(':agent_id', $agent_id);
    $patterns_stmt->execute();
    
    $betting_patterns = array();
    while($row = $patterns_stmt->fetch(PDO::FETCH_ASSOC)) {
        $pattern_item = array(
            "user" => array(
                "name" => $row['name'],
                "phone_number" => $row['phone_number']
            ),
            "lottery_type" => $row['lottery_type'],
            "number" => $row['number'],
            "frequency" => (int)$row['frequency']
        );
        array_push($betting_patterns, $pattern_item);
    }
    
    // Predict peak betting hours
    $peak_hours_query = "
        WITH hourly_bets AS (
            SELECT 
                EXTRACT(HOUR FROM created_at) as hour,
                COUNT(*) as bet_count,
                COUNT(DISTINCT user_id) as unique_users
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND DATE(created_at) >= CURRENT_DATE - INTERVAL '30 days'
            GROUP BY EXTRACT(HOUR FROM created_at)
        )
        SELECT 
            hour,
            bet_count,
            unique_users,
            CASE 
                WHEN bet_count > (SELECT AVG(bet_count) * 1.5 FROM hourly_bets) THEN 'Peak'
                WHEN bet_count > (SELECT AVG(bet_count) FROM hourly_bets) THEN 'High'
                ELSE 'Normal'
            END as traffic_level
        FROM hourly_bets
        ORDER BY bet_count DESC";
    
    $peak_hours_stmt = $db->prepare($peak_hours_query);
    $peak_hours_stmt->bindParam(':agent_id', $agent_id);
    $peak_hours_stmt->execute();
    
    $peak_hours = array();
    while($row = $peak_hours_stmt->fetch(PDO::FETCH_ASSOC)) {
        $peak_hours[(int)$row['hour']] = array(
            "bet_count" => (int)$row['bet_count'],
            "unique_users" => (int)$row['unique_users'],
            "traffic_level" => $row['traffic_level']
        );
    }

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "churn_prediction" => array(
                "at_risk_users" => $churn_risk,
                "summary" => array(
                    "high_risk" => count(array_filter($churn_risk, function($user) { return $user['risk_level'] === 'High'; })),
                    "medium_risk" => count(array_filter($churn_risk, function($user) { return $user['risk_level'] === 'Medium'; })),
                    "low_risk" => count(array_filter($churn_risk, function($user) { return $user['risk_level'] === 'Low'; }))
                )
            ),
            "revenue_prediction" => array(
                "avg_daily_revenue" => round(floatval($revenue_trends['avg_daily_revenue']), 2),
                "median_daily_revenue" => round(floatval($revenue_trends['median_daily_revenue']), 2),
                "revenue_volatility" => round(floatval($revenue_trends['revenue_volatility']), 2),
                "trend" => floatval($revenue_trends['trend_slope']) > 0 ? "Upward" : "Downward"
            ),
            "betting_patterns" => array(
                "frequent_combinations" => $betting_patterns
            ),
            "peak_hours" => $peak_hours
        )
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
