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
    $period = isset($_GET['period']) ? $_GET['period'] : 'today';
    
    // Set time period
    switch($period) {
        case 'week':
            $start_date = date('Y-m-d', strtotime('-7 days'));
            break;
        case 'month':
            $start_date = date('Y-m-d', strtotime('-30 days'));
            break;
        case 'year':
            $start_date = date('Y-m-d', strtotime('-365 days'));
            break;
        default:
            $start_date = date('Y-m-d');
    }
    
    // Get hourly betting patterns
    $hourly_query = "
        SELECT 
            EXTRACT(HOUR FROM created_at) as hour,
            COUNT(*) as bet_count,
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(DISTINCT user_id) as unique_users
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        AND DATE(p.created_at) >= :start_date
        GROUP BY EXTRACT(HOUR FROM created_at)
        ORDER BY hour";
    
    $hourly_stmt = $db->prepare($hourly_query);
    $hourly_stmt->bindParam(':agent_id', $agent_id);
    $hourly_stmt->bindParam(':start_date', $start_date);
    $hourly_stmt->execute();
    
    $hourly_patterns = array();
    while($row = $hourly_stmt->fetch(PDO::FETCH_ASSOC)) {
        $hourly_patterns[(int)$row['hour']] = array(
            "bet_count" => (int)$row['bet_count'],
            "total_amount" => floatval($row['total_amount']),
            "unique_users" => (int)$row['unique_users']
        );
    }
    
    // Get number pattern analysis
    $numbers_query = "
        SELECT 
            number,
            COUNT(*) as frequency,
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(CASE WHEN result = 'win' THEN 1 END) as wins
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        AND DATE(p.created_at) >= :start_date
        GROUP BY number
        ORDER BY frequency DESC
        LIMIT 20";
    
    $numbers_stmt = $db->prepare($numbers_query);
    $numbers_stmt->bindParam(':agent_id', $agent_id);
    $numbers_stmt->bindParam(':start_date', $start_date);
    $numbers_stmt->execute();
    
    $number_patterns = array();
    while($row = $numbers_stmt->fetch(PDO::FETCH_ASSOC)) {
        $number_patterns[$row['number']] = array(
            "frequency" => (int)$row['frequency'],
            "total_amount" => floatval($row['total_amount']),
            "wins" => (int)$row['wins']
        );
    }
    
    // Get lottery type performance
    $lottery_query = "
        SELECT 
            lottery_type,
            COUNT(*) as total_bets,
            COUNT(DISTINCT user_id) as unique_players,
            COALESCE(SUM(amount), 0) as total_amount,
            COUNT(CASE WHEN result = 'win' THEN 1 END) as wins,
            COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as total_payouts,
            COUNT(DISTINCT CASE WHEN DATE(created_at) = CURRENT_DATE THEN user_id END) as active_users_today
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        AND DATE(p.created_at) >= :start_date
        GROUP BY lottery_type";
    
    $lottery_stmt = $db->prepare($lottery_query);
    $lottery_stmt->bindParam(':agent_id', $agent_id);
    $lottery_stmt->bindParam(':start_date', $start_date);
    $lottery_stmt->execute();
    
    $lottery_performance = array();
    while($row = $lottery_stmt->fetch(PDO::FETCH_ASSOC)) {
        $lottery_performance[$row['lottery_type']] = array(
            "total_bets" => (int)$row['total_bets'],
            "unique_players" => (int)$row['unique_players'],
            "total_amount" => floatval($row['total_amount']),
            "wins" => (int)$row['wins'],
            "total_payouts" => floatval($row['total_payouts']),
            "active_users_today" => (int)$row['active_users_today'],
            "profit_margin" => $row['total_amount'] > 0 ? 
                round((($row['total_amount'] - $row['total_payouts']) / $row['total_amount']) * 100, 2) : 0
        );
    }
    
    // Get user betting behavior
    $behavior_query = "
        WITH user_stats AS (
            SELECT 
                user_id,
                COUNT(*) as bet_count,
                COALESCE(SUM(amount), 0) as total_amount,
                COUNT(CASE WHEN result = 'win' THEN 1 END) as wins,
                COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as total_winnings
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND DATE(p.created_at) >= :start_date
            GROUP BY user_id
        )
        SELECT 
            CASE 
                WHEN bet_count >= 100 THEN 'High Volume'
                WHEN bet_count >= 50 THEN 'Regular'
                WHEN bet_count >= 10 THEN 'Casual'
                ELSE 'Low Volume'
            END as player_type,
            COUNT(*) as user_count,
            AVG(bet_count) as avg_bets,
            AVG(total_amount) as avg_amount,
            AVG(CASE WHEN bet_count > 0 THEN (wins::float / bet_count) * 100 ELSE 0 END) as avg_win_rate
        FROM user_stats
        GROUP BY player_type";
    
    $behavior_stmt = $db->prepare($behavior_query);
    $behavior_stmt->bindParam(':agent_id', $agent_id);
    $behavior_stmt->bindParam(':start_date', $start_date);
    $behavior_stmt->execute();
    
    $betting_behavior = array();
    while($row = $behavior_stmt->fetch(PDO::FETCH_ASSOC)) {
        $betting_behavior[$row['player_type']] = array(
            "user_count" => (int)$row['user_count'],
            "avg_bets" => round(floatval($row['avg_bets']), 2),
            "avg_amount" => round(floatval($row['avg_amount']), 2),
            "avg_win_rate" => round(floatval($row['avg_win_rate']), 2)
        );
    }
    
    // Get risk analysis
    $risk_query = "
        WITH user_profits AS (
            SELECT 
                u.id,
                COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE -amount END), 0) as net_profit
            FROM users u
            LEFT JOIN playbets p ON u.id = p.user_id
            WHERE u.agent_id = :agent_id
            AND DATE(COALESCE(p.created_at, u.created_at)) >= :start_date
            GROUP BY u.id
        )
        SELECT 
            COUNT(CASE WHEN net_profit > 1000000 THEN 1 END) as high_risk_users,
            COUNT(CASE WHEN net_profit BETWEEN 100000 AND 1000000 THEN 1 END) as medium_risk_users,
            COUNT(CASE WHEN net_profit BETWEEN 0 AND 100000 THEN 1 END) as low_risk_users,
            COUNT(CASE WHEN net_profit < 0 THEN 1 END) as profitable_users
        FROM user_profits";
    
    $risk_stmt = $db->prepare($risk_query);
    $risk_stmt->bindParam(':agent_id', $agent_id);
    $risk_stmt->bindParam(':start_date', $start_date);
    $risk_stmt->execute();
    
    $risk_analysis = $risk_stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "period" => array(
                "type" => $period,
                "start_date" => $start_date,
                "end_date" => date('Y-m-d')
            ),
            "hourly_patterns" => $hourly_patterns,
            "number_patterns" => $number_patterns,
            "lottery_performance" => $lottery_performance,
            "betting_behavior" => $betting_behavior,
            "risk_analysis" => array(
                "high_risk_users" => (int)$risk_analysis['high_risk_users'],
                "medium_risk_users" => (int)$risk_analysis['medium_risk_users'],
                "low_risk_users" => (int)$risk_analysis['low_risk_users'],
                "profitable_users" => (int)$risk_analysis['profitable_users']
            )
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
