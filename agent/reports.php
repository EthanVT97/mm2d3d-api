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
    $report_type = isset($_GET['type']) ? $_GET['type'] : 'daily';
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
    
    // Adjust dates based on report type
    switch($report_type) {
        case 'weekly':
            $start_date = date('Y-m-d', strtotime('monday this week'));
            $end_date = date('Y-m-d', strtotime('sunday this week'));
            break;
        case 'monthly':
            $start_date = date('Y-m-01');
            $end_date = date('Y-m-t');
            break;
    }
    
    // Get transaction summary
    $transactions_query = "
        SELECT 
            DATE(t.created_at) as date,
            t.type,
            COUNT(*) as count,
            COALESCE(SUM(t.amount), 0) as total_amount
        FROM transactions t
        JOIN users u ON t.user_or_agent_id = u.id
        WHERE u.agent_id = :agent_id
        AND DATE(t.created_at) BETWEEN :start_date AND :end_date
        GROUP BY DATE(t.created_at), t.type
        ORDER BY date DESC";
    
    $transactions_stmt = $db->prepare($transactions_query);
    $transactions_stmt->bindParam(':agent_id', $agent_id);
    $transactions_stmt->bindParam(':start_date', $start_date);
    $transactions_stmt->bindParam(':end_date', $end_date);
    $transactions_stmt->execute();
    
    $transactions = array();
    while($row = $transactions_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($transactions[$row['date']])) {
            $transactions[$row['date']] = array(
                'date' => $row['date'],
                'deposits' => 0,
                'deposit_count' => 0,
                'withdrawals' => 0,
                'withdrawal_count' => 0
            );
        }
        
        if ($row['type'] === 'deposit') {
            $transactions[$row['date']]['deposits'] = floatval($row['total_amount']);
            $transactions[$row['date']]['deposit_count'] = (int)$row['count'];
        } else {
            $transactions[$row['date']]['withdrawals'] = floatval($row['total_amount']);
            $transactions[$row['date']]['withdrawal_count'] = (int)$row['count'];
        }
    }
    
    // Get betting summary
    $bets_query = "
        SELECT 
            DATE(p.created_at) as date,
            COUNT(*) as total_bets,
            COUNT(DISTINCT p.user_id) as unique_players,
            COALESCE(SUM(p.amount), 0) as total_amount,
            COUNT(CASE WHEN p.result = 'win' THEN 1 END) as winning_bets,
            COALESCE(SUM(CASE WHEN p.result = 'win' THEN p.winning_amount ELSE 0 END), 0) as total_payouts
        FROM playbets p
        JOIN users u ON p.user_id = u.id
        WHERE u.agent_id = :agent_id
        AND DATE(p.created_at) BETWEEN :start_date AND :end_date
        GROUP BY DATE(p.created_at)
        ORDER BY date DESC";
    
    $bets_stmt = $db->prepare($bets_query);
    $bets_stmt->bindParam(':agent_id', $agent_id);
    $bets_stmt->bindParam(':start_date', $start_date);
    $bets_stmt->bindParam(':end_date', $end_date);
    $bets_stmt->execute();
    
    $bets = array();
    while($row = $bets_stmt->fetch(PDO::FETCH_ASSOC)) {
        $bet_item = array(
            "date" => $row['date'],
            "total_bets" => (int)$row['total_bets'],
            "unique_players" => (int)$row['unique_players'],
            "total_amount" => floatval($row['total_amount']),
            "winning_bets" => (int)$row['winning_bets'],
            "total_payouts" => floatval($row['total_payouts']),
            "net_profit" => floatval($row['total_amount']) - floatval($row['total_payouts'])
        );
        array_push($bets, $bet_item);
    }
    
    // Get commission summary
    $commission_query = "
        SELECT 
            DATE(created_at) as date,
            commission_type,
            COUNT(*) as count,
            COALESCE(SUM(amount), 0) as total_amount
        FROM agent_commission
        WHERE agent_id = :agent_id
        AND DATE(created_at) BETWEEN :start_date AND :end_date
        GROUP BY DATE(created_at), commission_type
        ORDER BY date DESC";
    
    $commission_stmt = $db->prepare($commission_query);
    $commission_stmt->bindParam(':agent_id', $agent_id);
    $commission_stmt->bindParam(':start_date', $start_date);
    $commission_stmt->bindParam(':end_date', $end_date);
    $commission_stmt->execute();
    
    $commissions = array();
    while($row = $commission_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($commissions[$row['date']])) {
            $commissions[$row['date']] = array(
                'date' => $row['date'],
                'bet_commission' => 0,
                'bet_count' => 0,
                'referral_commission' => 0,
                'referral_count' => 0
            );
        }
        
        if ($row['commission_type'] === 'bet') {
            $commissions[$row['date']]['bet_commission'] = floatval($row['total_amount']);
            $commissions[$row['date']]['bet_count'] = (int)$row['count'];
        } else {
            $commissions[$row['date']]['referral_commission'] = floatval($row['total_amount']);
            $commissions[$row['date']]['referral_count'] = (int)$row['count'];
        }
    }
    
    // Get user growth
    $users_query = "
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_users
        FROM users
        WHERE agent_id = :agent_id
        AND DATE(created_at) BETWEEN :start_date AND :end_date
        GROUP BY DATE(created_at)
        ORDER BY date DESC";
    
    $users_stmt = $db->prepare($users_query);
    $users_stmt->bindParam(':agent_id', $agent_id);
    $users_stmt->bindParam(':start_date', $start_date);
    $users_stmt->bindParam(':end_date', $end_date);
    $users_stmt->execute();
    
    $users = array();
    while($row = $users_stmt->fetch(PDO::FETCH_ASSOC)) {
        $user_item = array(
            "date" => $row['date'],
            "new_users" => (int)$row['new_users']
        );
        array_push($users, $user_item);
    }
    
    // Calculate totals
    $totals = array(
        "total_deposits" => array_sum(array_column($transactions, 'deposits')),
        "total_withdrawals" => array_sum(array_column($transactions, 'withdrawals')),
        "total_bets" => array_sum(array_column($bets, 'total_bets')),
        "total_payouts" => array_sum(array_column($bets, 'total_payouts')),
        "total_commission" => array_sum(array_map(function($item) {
            return $item['bet_commission'] + $item['referral_commission'];
        }, $commissions)),
        "total_new_users" => array_sum(array_column($users, 'new_users'))
    );

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "report_type" => $report_type,
            "period" => array(
                "start_date" => $start_date,
                "end_date" => $end_date
            ),
            "transactions" => array_values($transactions),
            "bets" => $bets,
            "commissions" => array_values($commissions),
            "user_growth" => $users,
            "totals" => $totals
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
