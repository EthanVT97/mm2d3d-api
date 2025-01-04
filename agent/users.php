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
    if (!isset($_GET['agent_id'])) {
        throw new Exception("Agent ID is required");
    }

    $agent_id = $_GET['agent_id'];
    
    // Search functionality
    $search_condition = "";
    $params = array(':agent_id' => $agent_id);
    
    if (isset($_GET['search'])) {
        $search = $_GET['search'];
        $search_condition = "AND (u.phone_number LIKE :search OR u.name LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    // Get users with their betting statistics
    $query = "
        SELECT 
            u.id,
            u.phone_number,
            u.name,
            u.balance,
            u.created_at,
            COUNT(p.id) as total_bets,
            COALESCE(SUM(p.amount), 0) as total_bet_amount,
            COUNT(CASE WHEN p.result = 'win' THEN 1 END) as total_wins,
            COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_winnings
        FROM users u
        LEFT JOIN playbets p ON u.id = p.user_id
        WHERE u.agent_id = :agent_id
        $search_condition
        GROUP BY u.id
        ORDER BY u.created_at DESC";

    // Add pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
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
    $count_query = "SELECT COUNT(*) as total FROM users u WHERE agent_id = :agent_id $search_condition";
    $count_stmt = $db->prepare($count_query);
    foreach($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_users = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $users = array();
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user_item = array(
            "id" => $row['id'],
            "phone_number" => $row['phone_number'],
            "name" => $row['name'],
            "balance" => floatval($row['balance']),
            "created_at" => $row['created_at'],
            "stats" => array(
                "total_bets" => (int)$row['total_bets'],
                "total_bet_amount" => floatval($row['total_bet_amount']),
                "total_wins" => (int)$row['total_wins'],
                "total_winnings" => floatval($row['total_winnings']),
                "win_rate" => $row['total_bets'] > 0 ? round(($row['total_wins'] / $row['total_bets']) * 100, 2) : 0
            )
        );
        array_push($users, $user_item);
    }
    
    // Get summary statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_users,
            COALESCE(SUM(balance), 0) as total_balance,
            COUNT(CASE WHEN DATE(created_at) = CURRENT_DATE THEN 1 END) as new_users_today
        FROM users 
        WHERE agent_id = :agent_id";
    
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':agent_id', $agent_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "users" => $users,
            "stats" => array(
                "total_users" => (int)$stats['total_users'],
                "total_balance" => floatval($stats['total_balance']),
                "new_users_today" => (int)$stats['new_users_today']
            ),
            "pagination" => array(
                "total" => (int)$total_users,
                "per_page" => $per_page,
                "current_page" => $page,
                "total_pages" => ceil($total_users / $per_page)
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
