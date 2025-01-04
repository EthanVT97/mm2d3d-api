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
    // Get total users
    $users_query = "SELECT COUNT(*) as total FROM users";
    $users_stmt = $db->query($users_query);
    $total_users = $users_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total agents
    $agents_query = "SELECT COUNT(*) as total FROM agents";
    $agents_stmt = $db->query($agents_query);
    $total_agents = $agents_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get today's bets
    $today_bets_query = "SELECT COALESCE(SUM(bet_amount), 0) as total FROM playbets WHERE DATE(created_at) = CURRENT_DATE";
    $bets_stmt = $db->query($today_bets_query);
    $today_bets = $bets_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get today's profit (simplified calculation)
    $today_profit_query = "
        SELECT 
            COALESCE(SUM(CASE 
                WHEN result = 'lose' THEN bet_amount
                WHEN result = 'win' THEN -bet_amount
                ELSE 0 
            END), 0) as profit
        FROM playbets 
        WHERE DATE(created_at) = CURRENT_DATE";
    $profit_stmt = $db->query($today_profit_query);
    $today_profit = $profit_stmt->fetch(PDO::FETCH_ASSOC)['profit'];

    // Get today's new users
    $new_users_query = "SELECT COUNT(*) as total FROM users WHERE DATE(created_at) = CURRENT_DATE";
    $new_users_stmt = $db->query($new_users_query);
    $new_users = $new_users_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get today's new agents
    $new_agents_query = "SELECT COUNT(*) as total FROM agents WHERE DATE(created_at) = CURRENT_DATE";
    $new_agents_stmt = $db->query($new_agents_query);
    $new_agents = $new_agents_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "stats" => array(
            "total_users" => intval($total_users),
            "total_agents" => intval($total_agents),
            "today_bets" => floatval($today_bets),
            "today_profit" => floatval($today_profit),
            "new_users_today" => intval($new_users),
            "new_agents_today" => intval($new_agents)
        )
    ));

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(array(
        "status" => "error",
        "message" => "Database error: " . $e->getMessage()
    ));
}
?>
