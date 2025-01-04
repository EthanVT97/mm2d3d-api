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
    
    // Get latest results
    $latest_query = "
        SELECT 
            r.id,
            r.lottery_type,
            r.winning_numbers,
            r.draw_date,
            r.created_at,
            COUNT(DISTINCT p.user_id) as total_winners,
            COALESCE(SUM(p.winning_amount), 0) as total_payout
        FROM lottery_results r
        LEFT JOIN playbets p ON r.id = p.result_id 
            AND p.result = 'win' 
            AND p.user_id IN (SELECT id FROM users WHERE agent_id = :agent_id)
        WHERE r.draw_date >= CURRENT_DATE - INTERVAL '7 days'
        GROUP BY r.id
        ORDER BY r.draw_date DESC, r.lottery_type";
    
    $latest_stmt = $db->prepare($latest_query);
    $latest_stmt->bindParam(':agent_id', $agent_id);
    $latest_stmt->execute();
    
    $results = array();
    while($row = $latest_stmt->fetch(PDO::FETCH_ASSOC)) {
        $result_item = array(
            "id" => $row['id'],
            "lottery_type" => $row['lottery_type'],
            "winning_numbers" => $row['winning_numbers'],
            "draw_date" => $row['draw_date'],
            "created_at" => $row['created_at'],
            "stats" => array(
                "total_winners" => (int)$row['total_winners'],
                "total_payout" => floatval($row['total_payout'])
            )
        );
        array_push($results, $result_item);
    }
    
    // Get winning statistics
    $stats_query = "
        SELECT 
            COUNT(DISTINCT p.user_id) as total_winners,
            COALESCE(SUM(p.winning_amount), 0) as total_payouts,
            COUNT(DISTINCT CASE WHEN DATE(p.created_at) = CURRENT_DATE THEN p.user_id END) as today_winners,
            COALESCE(SUM(CASE WHEN DATE(p.created_at) = CURRENT_DATE THEN p.winning_amount ELSE 0 END), 0) as today_payouts
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id AND p.result = 'win'";
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':agent_id', $agent_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get top winners
    $winners_query = "
        SELECT 
            u.name,
            u.phone_number,
            COUNT(*) as win_count,
            COALESCE(SUM(p.winning_amount), 0) as total_winnings
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id AND p.result = 'win'
        GROUP BY u.id
        ORDER BY total_winnings DESC
        LIMIT 5";
    
    $winners_stmt = $db->prepare($winners_query);
    $winners_stmt->bindParam(':agent_id', $agent_id);
    $winners_stmt->execute();
    
    $top_winners = array();
    while($row = $winners_stmt->fetch(PDO::FETCH_ASSOC)) {
        $winner_item = array(
            "name" => $row['name'],
            "phone_number" => $row['phone_number'],
            "win_count" => (int)$row['win_count'],
            "total_winnings" => floatval($row['total_winnings'])
        );
        array_push($top_winners, $winner_item);
    }
    
    // Get lottery type statistics
    $lottery_stats_query = "
        SELECT 
            p.lottery_type,
            COUNT(*) as total_bets,
            COUNT(DISTINCT p.user_id) as unique_players,
            COALESCE(SUM(p.amount), 0) as total_amount,
            COUNT(CASE WHEN p.result = 'win' THEN 1 END) as winning_bets,
            COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_payouts
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        GROUP BY p.lottery_type";
    
    $lottery_stats_stmt = $db->prepare($lottery_stats_query);
    $lottery_stats_stmt->bindParam(':agent_id', $agent_id);
    $lottery_stats_stmt->execute();
    
    $lottery_stats = array();
    while($row = $lottery_stats_stmt->fetch(PDO::FETCH_ASSOC)) {
        $stat_item = array(
            "lottery_type" => $row['lottery_type'],
            "total_bets" => (int)$row['total_bets'],
            "unique_players" => (int)$row['unique_players'],
            "total_amount" => floatval($row['total_amount']),
            "winning_bets" => (int)$row['winning_bets'],
            "total_payouts" => floatval($row['total_payouts']),
            "win_rate" => $row['total_bets'] > 0 ? round(($row['winning_bets'] / $row['total_bets']) * 100, 2) : 0
        );
        array_push($lottery_stats, $stat_item);
    }

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "latest_results" => $results,
            "stats" => array(
                "total_winners" => (int)$stats['total_winners'],
                "total_payouts" => floatval($stats['total_payouts']),
                "today_winners" => (int)$stats['today_winners'],
                "today_payouts" => floatval($stats['today_payouts'])
            ),
            "top_winners" => $top_winners,
            "lottery_stats" => $lottery_stats
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
