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
    
    // Get commission summary
    $summary_query = "
        SELECT 
            COALESCE(SUM(CASE WHEN DATE(created_at) = CURRENT_DATE THEN amount ELSE 0 END), 0) as today,
            COALESCE(SUM(CASE WHEN DATE(created_at) >= DATE_TRUNC('month', CURRENT_DATE) THEN amount ELSE 0 END), 0) as month,
            COALESCE(SUM(amount), 0) as total
        FROM agent_commission
        WHERE agent_id = :agent_id";
    
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->bindParam(':agent_id', $agent_id);
    $summary_stmt->execute();
    $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get commission history
    $history_query = "
        SELECT 
            DATE(created_at) as date,
            SUM(amount) as amount,
            COUNT(*) as transactions,
            commission_type
        FROM agent_commission
        WHERE agent_id = :agent_id
        GROUP BY DATE(created_at), commission_type
        ORDER BY date DESC
        LIMIT 30";
    
    $history_stmt = $db->prepare($history_query);
    $history_stmt->bindParam(':agent_id', $agent_id);
    $history_stmt->execute();
    
    $history = array();
    while($row = $history_stmt->fetch(PDO::FETCH_ASSOC)) {
        $history_item = array(
            "date" => $row['date'],
            "amount" => floatval($row['amount']),
            "transactions" => (int)$row['transactions'],
            "type" => $row['commission_type']
        );
        array_push($history, $history_item);
    }
    
    // Get commission by type
    $types_query = "
        SELECT 
            commission_type,
            COUNT(*) as count,
            SUM(amount) as total_amount
        FROM agent_commission
        WHERE agent_id = :agent_id
        AND DATE(created_at) >= DATE_TRUNC('month', CURRENT_DATE)
        GROUP BY commission_type";
    
    $types_stmt = $db->prepare($types_query);
    $types_stmt->bindParam(':agent_id', $agent_id);
    $types_stmt->execute();
    
    $commission_types = array();
    while($row = $types_stmt->fetch(PDO::FETCH_ASSOC)) {
        $type_item = array(
            "type" => $row['commission_type'],
            "count" => (int)$row['count'],
            "total_amount" => floatval($row['total_amount'])
        );
        array_push($commission_types, $type_item);
    }
    
    // Get commission rates
    $rates_query = "
        SELECT commission_rates
        FROM agents
        WHERE id = :agent_id";
    
    $rates_stmt = $db->prepare($rates_query);
    $rates_stmt->bindParam(':agent_id', $agent_id);
    $rates_stmt->execute();
    $rates = json_decode($rates_stmt->fetch(PDO::FETCH_ASSOC)['commission_rates'], true);

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "commission" => array(
                "today" => floatval($summary['today']),
                "month" => floatval($summary['month']),
                "total" => floatval($summary['total'])
            ),
            "history" => $history,
            "commission_types" => $commission_types,
            "rates" => $rates
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
