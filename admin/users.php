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
    $query = "
        SELECT 
            u.id,
            u.phone_number,
            u.name,
            u.balance,
            u.created_at,
            COUNT(p.id) as total_bets,
            COALESCE(SUM(CASE WHEN p.result = 'win' THEN 1 ELSE 0 END), 0) as total_wins
        FROM users u
        LEFT JOIN playbets p ON u.id = p.user_id
        GROUP BY u.id
        ORDER BY u.created_at DESC";
    
    // Add pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
    $offset = ($page - 1) * $per_page;
    
    $query .= " LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM users";
    $count_stmt = $db->query($count_query);
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
                "total_wins" => (int)$row['total_wins'],
                "win_rate" => $row['total_bets'] > 0 ? round(($row['total_wins'] / $row['total_bets']) * 100, 2) : 0
            )
        );
        array_push($users, $user_item);
    }
    
    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "users" => $users,
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
}
?>
