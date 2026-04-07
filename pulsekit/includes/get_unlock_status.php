<?php
/**
 * get_unlock_status.php
 * Fetches dataset_loaded AND pipeline_executed for the current user.
 *
 * $user_dataset_loaded    → true if sample dataset loaded (shows post-load pipeline UI)
 * $user_pipeline_executed → true if Run Pipeline was completed (unlocks all analytics modules)
 *
 * Place at: /pulsekit/includes/get_unlock_status.php
 */

$user_dataset_loaded    = false;
$user_pipeline_executed = false;

$uid = (int) $_SESSION['user_id'];

// Always re-query if pipeline_executed is not yet in session
// (handles migration period and session staleness from old code)
if (isset($_SESSION['dataset_loaded']) && array_key_exists('pipeline_executed', $_SESSION)) {
    $user_dataset_loaded    = (bool) $_SESSION['dataset_loaded'];
    $user_pipeline_executed = (bool) $_SESSION['pipeline_executed'];
} else {
    // Try new query with both columns
    $chk = $conn->prepare("SELECT dataset_loaded, pipeline_executed FROM users WHERE id = ? LIMIT 1");
    if ($chk) {
        $chk->bind_param("i", $uid);
        $chk->execute();
        $chk->bind_result($db_dataset, $db_pipeline);
        $chk->fetch();
        $chk->close();

        $user_dataset_loaded    = (bool) $db_dataset;
        $user_pipeline_executed = (bool) $db_pipeline;
    } else {
        // Column doesn't exist yet (migration not run) — fall back to dataset_loaded only
        $chk2 = $conn->prepare("SELECT dataset_loaded FROM users WHERE id = ? LIMIT 1");
        if ($chk2) {
            $chk2->bind_param("i", $uid);
            $chk2->execute();
            $chk2->bind_result($db_dataset2);
            $chk2->fetch();
            $chk2->close();
            $user_dataset_loaded = (bool) $db_dataset2;
        }
        // pipeline_executed stays false until migration is run
    }

    $_SESSION['dataset_loaded']    = $user_dataset_loaded    ? true : false;
    $_SESSION['pipeline_executed'] = $user_pipeline_executed ? true : false;
    $_SESSION['pipeline_ran']      = $_SESSION['pipeline_executed'];
}
?>