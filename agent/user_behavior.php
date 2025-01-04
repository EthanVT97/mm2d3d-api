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
    
    // Analyze user engagement patterns
    $engagement_query = "
        WITH user_activity AS (
            SELECT 
                u.id,
                u.name,
                u.phone_number,
                u.created_at as join_date,
                COUNT(DISTINCT DATE(p.created_at)) as active_days,
                COUNT(DISTINCT p.id) as total_bets,
                COALESCE(SUM(p.amount), 0) as total_bet_amount,
                COUNT(DISTINCT t.id) as total_transactions,
                MAX(GREATEST(COALESCE(p.created_at, '1970-01-01'), COALESCE(t.created_at, '1970-01-01'))) as last_activity
            FROM users u
            LEFT JOIN playbets p ON u.id = p.user_id
            LEFT JOIN transactions t ON u.id = t.user_or_agent_id
            WHERE u.agent_id = :agent_id
            GROUP BY u.id, u.name, u.phone_number, u.created_at
        )
        SELECT 
            id,
            name,
            phone_number,
            join_date,
            active_days,
            total_bets,
            total_bet_amount,
            total_transactions,
            last_activity,
            CASE 
                WHEN last_activity >= NOW() - INTERVAL '1 day' THEN 'Very Active'
                WHEN last_activity >= NOW() - INTERVAL '7 days' THEN 'Active'
                WHEN last_activity >= NOW() - INTERVAL '30 days' THEN 'Semi-Active'
                ELSE 'Inactive'
            END as engagement_level,
            EXTRACT(DAY FROM (NOW() - join_date)) as account_age_days,
            EXTRACT(DAY FROM (NOW() - last_activity)) as days_since_last_activity
        FROM user_activity
        ORDER BY last_activity DESC";
    
    $engagement_stmt = $db->prepare($engagement_query);
    $engagement_stmt->bindParam(':agent_id', $agent_id);
    $engagement_stmt->execute();
    
    $user_engagement = array();
    while($row = $engagement_stmt->fetch(PDO::FETCH_ASSOC)) {
        $user_engagement[] = array(
            "user" => array(
                "id" => $row['id'],
                "name" => $row['name'],
                "phone_number" => $row['phone_number']
            ),
            "metrics" => array(
                "join_date" => $row['join_date'],
                "active_days" => (int)$row['active_days'],
                "total_bets" => (int)$row['total_bets'],
                "total_bet_amount" => round(floatval($row['total_bet_amount']), 2),
                "total_transactions" => (int)$row['total_transactions'],
                "last_activity" => $row['last_activity'],
                "account_age_days" => (int)$row['account_age_days'],
                "days_since_last_activity" => (int)$row['days_since_last_activity']
            ),
            "engagement_level" => $row['engagement_level']
        );
    }
    
    // Analyze betting preferences
    $preferences_query = "
        WITH user_preferences AS (
            SELECT 
                u.id,
                u.name,
                p.lottery_type,
                p.number,
                COUNT(*) as frequency,
                AVG(amount) as avg_bet_amount,
                STDDEV(amount) as amount_variation,
                COUNT(CASE WHEN result = 'win' THEN 1 END) as wins
            FROM users u
            JOIN playbets p ON u.id = p.user_id
            WHERE u.agent_id = :agent_id
            GROUP BY u.id, u.name, p.lottery_type, p.number
        )
        SELECT 
            id,
            name,
            lottery_type,
            number,
            frequency,
            avg_bet_amount,
            amount_variation,
            wins,
            (wins::float / frequency * 100) as win_rate
        FROM user_preferences
        WHERE frequency >= 5
        ORDER BY frequency DESC";
    
    $preferences_stmt = $db->prepare($preferences_query);
    $preferences_stmt->bindParam(':agent_id', $agent_id);
    $preferences_stmt->execute();
    
    $betting_preferences = array();
    while($row = $preferences_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($betting_preferences[$row['id']])) {
            $betting_preferences[$row['id']] = array(
                "user" => array(
                    "id" => $row['id'],
                    "name" => $row['name']
                ),
                "favorite_numbers" => array(),
                "lottery_types" => array()
            );
        }
        
        // Add to favorite numbers if not already added
        $number_data = array(
            "number" => $row['number'],
            "frequency" => (int)$row['frequency'],
            "win_rate" => round(floatval($row['win_rate']), 2)
        );
        if (!in_array($number_data, $betting_preferences[$row['id']]['favorite_numbers'])) {
            $betting_preferences[$row['id']]['favorite_numbers'][] = $number_data;
        }
        
        // Add to lottery types if not already added
        if (!isset($betting_preferences[$row['id']]['lottery_types'][$row['lottery_type']])) {
            $betting_preferences[$row['id']]['lottery_types'][$row['lottery_type']] = array(
                "frequency" => (int)$row['frequency'],
                "avg_bet_amount" => round(floatval($row['avg_bet_amount']), 2),
                "amount_variation" => round(floatval($row['amount_variation']), 2),
                "win_rate" => round(floatval($row['win_rate']), 2)
            );
        }
    }
    
    // Analyze time patterns
    $time_patterns_query = "
        WITH hourly_activity AS (
            SELECT 
                u.id,
                u.name,
                EXTRACT(HOUR FROM p.created_at) as hour,
                COUNT(*) as bet_count,
                AVG(amount) as avg_amount
            FROM users u
            JOIN playbets p ON u.id = p.user_id
            WHERE u.agent_id = :agent_id
            GROUP BY u.id, u.name, EXTRACT(HOUR FROM p.created_at)
        )
        SELECT 
            id,
            name,
            hour,
            bet_count,
            avg_amount,
            CASE 
                WHEN hour BETWEEN 6 AND 11 THEN 'Morning'
                WHEN hour BETWEEN 12 AND 17 THEN 'Afternoon'
                WHEN hour BETWEEN 18 AND 23 THEN 'Evening'
                ELSE 'Night'
            END as time_of_day
        FROM hourly_activity
        ORDER BY id, hour";
    
    $time_patterns_stmt = $db->prepare($time_patterns_query);
    $time_patterns_stmt->bindParam(':agent_id', $agent_id);
    $time_patterns_stmt->execute();
    
    $time_patterns = array();
    while($row = $time_patterns_stmt->fetch(PDO::FETCH_ASSOC)) {
        if (!isset($time_patterns[$row['id']])) {
            $time_patterns[$row['id']] = array(
                "user" => array(
                    "id" => $row['id'],
                    "name" => $row['name']
                ),
                "hourly_patterns" => array(),
                "preferred_times" => array(
                    "Morning" => 0,
                    "Afternoon" => 0,
                    "Evening" => 0,
                    "Night" => 0
                )
            );
        }
        
        $time_patterns[$row['id']]['hourly_patterns'][(int)$row['hour']] = array(
            "bet_count" => (int)$row['bet_count'],
            "avg_amount" => round(floatval($row['avg_amount']), 2)
        );
        
        $time_patterns[$row['id']]['preferred_times'][$row['time_of_day']] += (int)$row['bet_count'];
    }

    http_response_code(200);
    echo json_encode(array(
        "status" => "success",
        "data" => array(
            "user_engagement" => $user_engagement,
            "betting_preferences" => array_values($betting_preferences),
            "time_patterns" => array_values($time_patterns),
            "summary" => array(
                "engagement_distribution" => array(
                    "very_active" => count(array_filter($user_engagement, function($user) { 
                        return $user['engagement_level'] === 'Very Active'; 
                    })),
                    "active" => count(array_filter($user_engagement, function($user) { 
                        return $user['engagement_level'] === 'Active'; 
                    })),
                    "semi_active" => count(array_filter($user_engagement, function($user) { 
                        return $user['engagement_level'] === 'Semi-Active'; 
                    })),
                    "inactive" => count(array_filter($user_engagement, function($user) { 
                        return $user['engagement_level'] === 'Inactive'; 
                    }))
                )
            )
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
