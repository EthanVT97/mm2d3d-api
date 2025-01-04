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
    $conditions = array();
    $params = array();
    
    // Filter by type
    if(isset($_GET['type']) && $_GET['type'] !== 'all') {
        $conditions[] = "t.type = :type";
        $params[':type'] = $_GET['type'];
    }
    
    // Filter by date
    if(isset($_GET['date'])) {
        $conditions[] = "DATE(t.date) = :date";
        $params[':date'] = $_GET['date'];
    }
    
    // Filter by status
    if(isset($_GET['status'])) {
        $conditions[] = "t.status = :status";
        $params[':status'] = $_GET['status'];
    }
    
    $where_clause = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";
    
    $query = "
        SELECT 
            t.id,
            t.user_or_agent_id,
            t.type,
            t.amount,
            t.date,
            t.status,
            t.reference_number,
            COALESCE(u.phone_number, a.username) as user_identifier,
            CASE 
                WHEN u.id IS NOT NULL THEN 'user'
                WHEN a.id IS NOT NULL THEN 'agent'
            END as user_type
        FROM transactions t
        LEFT JOIN users u ON t.user_or_agent_id = u.id AND t.type IN ('deposit', 'withdraw', 'winning')
        LEFT JOIN agents a ON t.user_or_agent_id = a.id AND t.type IN ('commission', 'adjustment')
        {$where_clause}
        ORDER BY t.date DESC";
    
    // Add pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
    $offset = ($page - 1) * $per_page;
    
    $query .= " LIMIT :limit OFFSET :offset";
    
    $stmt = $db->prepare($query);
    
    // Bind pagination parameters
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    // Bind filter parameters
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    $stmt->execute();
    
    // Get total count for pagination
    $count_query = "SELECT COUNT(*) as total FROM transactions t {$where_clause}";
    $count_stmt = $db->prepare($count_query);
    foreach($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_transactions = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $transactions = array();
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $transaction_item = array(
            "id" => $row['id'],
            "user_identifier" => $row['user_identifier'],
            "user_type" => $row['user_type'],
            "type" => $row['type'],
            "amount" => floatval($row['amount']),
            "date" => $row['date'],
            "status" => $row['status'],
            "reference_number" => $row['reference_number']
        );
        array_push($transactions, $transaction_item);
    }
    
    // Calculate summary statistics
    $stats_query = "
        SELECT 
            COUNT(*) as total_count,
            COALESCE(SUM(CASE WHEN type = 'deposit' THEN amount ELSE 0 END), 0) as total_deposits,
            COALESCE(SUM(CASE WHEN type = 'withdraw' THEN amount ELSE 0 END), 0) as total_withdrawals,
            COALESCE(SUM(CASE WHEN type = 'winning' THEN amount ELSE 0 END), 0) as total_winnings
        FROM transactions t
        {$where_clause}";
    
    $stats_stmt = $db->prepare($stats_query);
    foreach($params as $key => $value) {
        $stats_stmt->bindValue($key, $value);
    }
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "transactions" => $transactions,
            "stats" => array(
                "total_count" => (int)$stats['total_count'],
                "total_deposits" => floatval($stats['total_deposits']),
                "total_withdrawals" => floatval($stats['total_withdrawals']),
                "total_winnings" => floatval($stats['total_winnings']),
                "net_balance" => floatval($stats['total_deposits'] - $stats['total_withdrawals'] - $stats['total_winnings'])
            ),
            "pagination" => array(
                "total" => (int)$total_transactions,
                "per_page" => $per_page,
                "current_page" => $page,
                "total_pages" => ceil($total_transactions / $per_page)
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
