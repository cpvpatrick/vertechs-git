<?php
/**
 * reset_dataset.php
 * AJAX endpoint — resets dataset_loaded = 0 for the logged-in user.
 * Place this in: /pulsekit/dashboard/reset_dataset.php
 */

require_once "../includes/auth_check.php";

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

$stmt = $conn->prepare("UPDATE users SET dataset_loaded = 0 WHERE id = ?");
$stmt->bind_param("i", $user_id);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    $_SESSION['dataset_loaded'] = 0;
    echo json_encode(['success' => true]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $conn->error]);
}
?>
