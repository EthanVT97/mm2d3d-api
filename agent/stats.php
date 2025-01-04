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
    $today = date('Y-m-d');

    // Get total users
    $users_query = "SELECT COUNT(*) as total FROM users WHERE agent_id = :agent_id";
    $users_stmt = $db->prepare($users_query);
    $users_stmt->bindParam(':agent_id', $agent_id);
    $users_stmt->execute();
    $total_users = $users_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get today's new users
    $new_users_query = "SELECT COUNT(*) as total FROM users WHERE agent_id = :agent_id AND DATE(created_at) = :today";
    $new_users_stmt = $db->prepare($new_users_query);
    $new_users_stmt->bindParam(':agent_id', $agent_id);
    $new_users_stmt->bindParam(':today', $today);
    $new_users_stmt->execute();
    $new_users = $new_users_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get total bets and today's bets
    $bets_query = "
        SELECT 
            COALESCE(SUM(amount), 0) as total_amount,
            COALESCE(SUM(CASE WHEN DATE(created_at) = :today THEN amount ELSE 0 END), 0) as today_amount
        FROM playbets 
        WHERE user_id IN (SELECT id FROM users WHERE agent_id = :agent_id)";
    $bets_stmt = $db->prepare($bets_query);
    $bets_stmt->bindParam(':agent_id', $agent_id);
    $bets_stmt->bindParam(':today', $today);
    $bets_stmt->execute();
    $bets_data = $bets_stmt->fetch(PDO::FETCH_ASSOC);

    // Get commission data
    $commission_query = "
        SELECT 
            COALESCE(SUM(amount), 0) as total_commission,
            COALESCE(SUM(CASE WHEN DATE(created_at) = :today THEN amount ELSE 0 END), 0) as today_commission
        FROM agent_commission 
        WHERE agent_id = :agent_id";
    $commission_stmt = $db->prepare($commission_query);
    $commission_stmt->bindParam(':agent_id', $agent_id);
    $commission_stmt->bindParam(':today', $today);
    $commission_stmt->execute();
    $commission_data = $commission_stmt->fetch(PDO::FETCH_ASSOC);

    // Get winners data
    $winners_query = "
        SELECT 
            COUNT(DISTINCT user_id) as total_winners,
            COUNT(DISTINCT CASE WHEN DATE(created_at) = :today THEN user_id END) as today_winners
        FROM playbets 
        WHERE user_id IN (SELECT id FROM users WHERE agent_id = :agent_id)
        AND result = 'win'";
    $winners_stmt = $db->prepare($winners_query);
    $winners_stmt->bindParam(':agent_id', $agent_id);
    $winners_stmt->bindParam(':today', $today);
    $winners_stmt->execute();
    $winners_data = $winners_stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "stats" => array(
            "total_users" => (int)$total_users,
            "new_users_today" => (int)$new_users,
            "total_bets" => floatval($bets_data['total_amount']),
            "today_bets" => floatval($bets_data['today_amount']),
            "total_commission" => floatval($commission_data['total_commission']),
            "today_commission" => floatval($commission_data['today_commission']),
            "total_winners" => (int)$winners_data['total_winners'],
            "today_winners" => (int)$winners_data['today_winners']
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
