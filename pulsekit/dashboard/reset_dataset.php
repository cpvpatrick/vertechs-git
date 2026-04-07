<?php
/**
 * reset_dataset.php
 * AJAX endpoint — resets BOTH dataset_loaded and pipeline_executed to 0.
 * This re-locks all analytics modules and resets the pipeline UI.
 * Place at: /pulsekit/dashboard/reset_dataset.php
 */

require_once "../includes/auth_check.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE users SET dataset_loaded = 0, pipeline_executed = 0 WHERE id = ?");
$stmt->bind_param("i", $user_id);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    $_SESSION['dataset_loaded']    = 0;
    $_SESSION['pipeline_executed'] = 0;
    $_SESSION['pipeline_ran']      = false;
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>