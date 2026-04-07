<?php
// includes/access_guard.php

function check_access() {
    $current_page = basename($_SERVER['PHP_SELF']);

    // AJAX endpoints that must always be reachable (they handle their own auth)
    $ajax_endpoints = ['load_dataset.php', 'run_pipeline.php', 'reset_dataset.php'];
    if (in_array($current_page, $ajax_endpoints)) {
        return;
    }

    // Only pipeline.php is allowed before pipeline is run (strict 3-stage flow)
    if (!isset($_SESSION['pipeline_ran']) || $_SESSION['pipeline_ran'] !== true) {
        if ($current_page !== 'pipeline.php') {
            header("Location: pipeline.php");
            exit();
        }
    }
}

// Call the function immediately when included
check_access();
?>
