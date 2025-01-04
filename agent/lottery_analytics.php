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
    $period = isset($_GET['period']) ? $_GET['period'] : '30'; // days
    
    // Analyze lottery performance
    $performance_query = "
        WITH lottery_stats AS (
            SELECT 
                lottery_type,
                COUNT(*) as total_bets,
                COUNT(DISTINCT user_id) as unique_players,
                COALESCE(SUM(bet_amount), 0) as total_bet_amount,
                COUNT(CASE WHEN result = 'win' THEN 1 END) as winning_bets,
                COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as total_payouts,
                COALESCE(SUM(commission_amount), 0) as total_commission
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND p.created_at >= CURRENT_DATE - INTERVAL ':period days'
            GROUP BY lottery_type
        )
        SELECT 
            lottery_type,
            total_bets,
            unique_players,
            total_bet_amount,
            winning_bets,
            total_payouts,
            total_commission,
            (total_bet_amount - total_payouts) as net_profit,
            CASE 
                WHEN total_bets > 0 THEN (winning_bets::float / total_bets) * 100 
                ELSE 0 
            END as win_rate,
            CASE 
                WHEN total_bet_amount > 0 THEN ((total_bet_amount - total_payouts) / total_bet_amount) * 100 
                ELSE 0 
            END as profit_margin
        FROM lottery_stats";
    
    $performance_stmt = $db->prepare($performance_query);
    $performance_stmt->bindParam(':agent_id', $agent_id);
    $performance_stmt->bindParam(':period', $period);
    $performance_stmt->execute();
    
    $lottery_performance = array();
    while($row = $performance_stmt->fetch(PDO::FETCH_ASSOC)) {
        $lottery_performance[$row['lottery_type']] = array(
            "total_bets" => (int)$row['total_bets'],
            "unique_players" => (int)$row['unique_players'],
            "total_bet_amount" => round(floatval($row['total_bet_amount']), 2),
            "winning_bets" => (int)$row['winning_bets'],
            "total_payouts" => round(floatval($row['total_payouts']), 2),
            "total_commission" => round(floatval($row['total_commission']), 2),
            "net_profit" => round(floatval($row['net_profit']), 2),
            "win_rate" => round(floatval($row['win_rate']), 2),
            "profit_margin" => round(floatval($row['profit_margin']), 2)
        );
    }
    
    // Analyze number frequency
    $numbers_query = "
        WITH number_stats AS (
            SELECT 
                lottery_type,
                number_selected as number,
                COUNT(*) as frequency,
                COUNT(CASE WHEN result = 'win' THEN 1 END) as wins,
                COALESCE(SUM(bet_amount), 0) as total_bets,
                COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as total_payouts
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND p.created_at >= CURRENT_DATE - INTERVAL ':period days'
            GROUP BY lottery_type, number_selected
        )
        SELECT 
            lottery_type,
            number,
            frequency,
            wins,
            total_bets,
            total_payouts,
            CASE 
                WHEN frequency > 0 THEN (wins::float / frequency) * 100 
                ELSE 0 
            END as win_rate
        FROM number_stats
        ORDER BY frequency DESC
        LIMIT 100";
    
    $numbers_stmt = $db->prepare($numbers_query);
    $numbers_stmt->bindParam(':agent_id', $agent_id);
    $numbers_stmt->bindParam(':period', $period);
    $numbers_stmt->execute();
    
    $number_frequency = array();
    while($row = $numbers_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($number_frequency[$row['lottery_type']])) {
            $number_frequency[$row['lottery_type']] = array();
        }
        $number_frequency[$row['lottery_type']][] = array(
            "number" => $row['number'],
            "frequency" => (int)$row['frequency'],
            "wins" => (int)$row['wins'],
            "total_bets" => round(floatval($row['total_bets']), 2),
            "total_payouts" => round(floatval($row['total_payouts']), 2),
            "win_rate" => round(floatval($row['win_rate']), 2)
        );
    }
    
    // Analyze daily trends
    $trends_query = "
        WITH daily_stats AS (
            SELECT 
                DATE(created_at) as bet_date,
                COUNT(*) as total_bets,
                COUNT(DISTINCT user_id) as unique_players,
                COALESCE(SUM(bet_amount), 0) as total_bet_amount,
                COUNT(CASE WHEN result = 'win' THEN 1 END) as winning_bets,
                COALESCE(SUM(CASE WHEN result = 'win' THEN winning_amount ELSE 0 END), 0) as total_payouts
            FROM playbets p
            JOIN users u ON p.user_id = u.id
            WHERE u.agent_id = :agent_id
            AND p.created_at >= CURRENT_DATE - INTERVAL ':period days'
            GROUP BY DATE(created_at)
            ORDER BY DATE(created_at)
        )
        SELECT 
            bet_date,
            total_bets,
            unique_players,
            total_bet_amount,
            winning_bets,
            total_payouts,
            (total_bet_amount - total_payouts) as net_profit,
            CASE 
                WHEN total_bets > 0 THEN (winning_bets::float / total_bets) * 100 
                ELSE 0 
            END as win_rate
        FROM daily_stats";
    
    $trends_stmt = $db->prepare($trends_query);
    $trends_stmt->bindParam(':agent_id', $agent_id);
    $trends_stmt->bindParam(':period', $period);
    $trends_stmt->execute();
    
    $daily_trends = array();
    while($row = $trends_stmt->fetch(PDO::FETCH_ASSOC)) {
        $daily_trends[$row['bet_date']] = array(
            "total_bets" => (int)$row['total_bets'],
            "unique_players" => (int)$row['unique_players'],
            "total_bet_amount" => round(floatval($row['total_bet_amount']), 2),
            "winning_bets" => (int)$row['winning_bets'],
            "total_payouts" => round(floatval($row['total_payouts']), 2),
            "net_profit" => round(floatval($row['net_profit']), 2),
            "win_rate" => round(floatval($row['win_rate']), 2)
        );
    }
    
    // Calculate summary statistics
    $summary_query = "
        SELECT 
            COUNT(DISTINCT p.id) as total_bets,
            COUNT(DISTINCT p.user_id) as total_players,
            COALESCE(SUM(p.bet_amount), 0) as total_bet_amount,
            COUNT(DISTINCT CASE WHEN p.result = 'win' THEN p.id END) as total_wins,
            COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_payouts,
            COALESCE(SUM(p.commission_amount), 0) as total_commission,
            COUNT(DISTINCT p.lottery_type) as lottery_types_count,
            AVG(CASE WHEN p.result = 'win' THEN 1 ELSE 0 END) * 100 as overall_win_rate
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        AND p.created_at >= CURRENT_DATE - INTERVAL ':period days'";
    
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->bindParam(':agent_id', $agent_id);
    $summary_stmt->bindParam(':period', $period);
    $summary_stmt->execute();
    
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "period" => array(
                "days" => (int)$period,
                "start_date" => date('Y-m-d', strtotime("-$period days")),
                "end_date" => date('Y-m-d')
            ),
            "summary" => array(
                "total_bets" => (int)$summary['total_bets'],
                "total_players" => (int)$summary['total_players'],
                "total_bet_amount" => round(floatval($summary['total_bet_amount']), 2),
                "total_wins" => (int)$summary['total_wins'],
                "total_payouts" => round(floatval($summary['total_payouts']), 2),
                "total_commission" => round(floatval($summary['total_commission']), 2),
                "net_profit" => round(floatval($summary['total_bet_amount'] - $summary['total_payouts']), 2),
                "lottery_types_count" => (int)$summary['lottery_types_count'],
                "overall_win_rate" => round(floatval($summary['overall_win_rate']), 2)
            ),
            "lottery_performance" => $lottery_performance,
            "number_frequency" => $number_frequency,
            "daily_trends" => $daily_trends
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
