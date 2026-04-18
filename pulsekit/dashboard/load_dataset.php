<?php
/**
 * load_dataset.php
 * AJAX endpoint — marks dataset_loaded = 1 for the logged-in user.
 * Place this in: /pulsekit/dashboard/load_dataset.php
 */

require_once "../includes/auth_check.php";

header('Content-Type: application/json');

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// Update the user's dataset_loaded flag in the database
$stmt = $conn->prepare("UPDATE users SET dataset_loaded = 1 WHERE id = ?");
$stmt->bind_param("i", $user_id);
$success = $stmt->execute();
$stmt->close();

if ($success) {
    // Also update the session so the current page-load knows immediately
    $_SESSION['dataset_loaded'] = 1;

    // Log the activity
    $page_name = "Dataset Loaded";
    $log_stmt = $conn->prepare(
        "INSERT INTO user_activity (session_id, user_id, page_name, visited_at)
         VALUES (?, ?, ?, NOW())"
    );
    $log_stmt->bind_param("sis", $_SESSION['session_id'], $user_id, $page_name);
    $log_stmt->execute();
    $log_stmt->close();

    echo json_encode(['success' => true, 'message' => 'Dataset loaded successfully']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>