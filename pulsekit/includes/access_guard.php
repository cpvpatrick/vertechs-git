<?php
// includes/access_guard.php

function check_access() {
    $current_page = basename($_SERVER['PHP_SELF']);

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
