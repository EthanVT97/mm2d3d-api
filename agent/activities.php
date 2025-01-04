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
    
    // Get recent activities (deposits, withdrawals, new users, and winning bets)
    $query = "
        (SELECT 
            'deposit' as type,
            CONCAT(u.name, ' မှ ငွေ ', t.amount, ' ကျပ် ဖြည့်သွင်းသည်') as details,
            t.created_at as timestamp
        FROM transactions t
        JOIN users u ON t.user_or_agent_id = u.id
        WHERE u.agent_id = :agent_id AND t.type = 'deposit'
        AND t.created_at >= NOW() - INTERVAL '24 HOURS')
        
        UNION ALL
        
        (SELECT 
            'withdraw' as type,
            CONCAT(u.name, ' မှ ငွေ ', t.amount, ' ကျပ် ထုတ်ယူသည်') as details,
            t.created_at as timestamp
        FROM transactions t
        JOIN users u ON t.user_or_agent_id = u.id
        WHERE u.agent_id = :agent_id AND t.type = 'withdraw'
        AND t.created_at >= NOW() - INTERVAL '24 HOURS')
        
        UNION ALL
        
        (SELECT 
            'new_user' as type,
            CONCAT(name, ' အကောင့်အသစ် ဖွင့်လှစ်သည်') as details,
            created_at as timestamp
        FROM users
        WHERE agent_id = :agent_id
        AND created_at >= NOW() - INTERVAL '24 HOURS')
        
        UNION ALL
        
        (SELECT 
            'winning' as type,
            CONCAT(u.name, ' မှ ငွေ ', p.winning_amount, ' ကျပ် ထီပေါက်သည်') as details,
            p.updated_at as timestamp
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id AND p.result = 'win'
        AND p.updated_at >= NOW() - INTERVAL '24 HOURS')
        
        ORDER BY timestamp DESC
        LIMIT 20";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':agent_id', $agent_id);
    $stmt->execute();
    
    $activities = array();
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $activity = array(
            "type" => $row['type'],
            "details" => $row['details'],
            "timestamp" => $row['timestamp']
        );
        array_push($activities, $activity);
    }

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "activities" => $activities
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
