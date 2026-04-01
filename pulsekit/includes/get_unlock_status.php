<?php
/**
 * get_unlock_status.php
 * Fetches dataset_loaded for the current user from the DB.
 * Returns a PHP bool $user_dataset_loaded.
 * 
 * Include this AFTER auth_check.php (so $conn and $_SESSION are ready).
 * Place at: /pulsekit/includes/get_unlock_status.php
 */

$user_dataset_loaded = false;

// Use session cache first to avoid extra DB query on every page
if (isset($_SESSION['dataset_loaded'])) {
    $user_dataset_loaded = (bool) $_SESSION['dataset_loaded'];
} else {
    // Fetch from DB and cache in session
    $uid = (int) $_SESSION['user_id'];
    $chk = $conn->prepare("SELECT dataset_loaded FROM users WHERE id = ? LIMIT 1");
    $chk->bind_param("i", $uid);
    $chk->execute();
    $chk->bind_result($db_loaded);
    $chk->fetch();
    $chk->close();
    $user_dataset_loaded = (bool) $db_loaded;
    $_SESSION['dataset_loaded'] = $user_dataset_loaded ? 1 : 0;
}
?>