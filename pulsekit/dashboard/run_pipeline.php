<?php
/**
 * run_pipeline.php
 * AJAX endpoint — sets pipeline_executed = 1 for the logged-in user.
 * This is what actually unlocks all analytics modules.
 * Place at: /pulsekit/dashboard/run_pipeline.php
 */

require_once "../includes/auth_check.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE users SET pipeline_executed = 1 WHERE id = ?");
$stmt->bind_param("i", $user_id);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    $_SESSION['pipeline_executed'] = 1;

    $log_stmt = $conn->prepare(
        "INSERT INTO user_activity (session_id, user_id, page_name, visited_at)
         VALUES (?, ?, ?, NOW())"
    );
    $page = "Pipeline Executed";
    $log_stmt->bind_param("sis", $_SESSION['session_id'], $user_id, $page);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>