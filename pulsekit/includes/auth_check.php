<?php
session_start();
require_once __DIR__ . "/../db.php";

/* AUTH CHECK */
if (!isset($_SESSION["user_id"])) {
    header("Location: ../index.php");
    exit();
}

/* SESSION TIMEOUT (30 minutes) */
if (isset($_SESSION["last_activity"]) &&
    (time() - $_SESSION["last_activity"] > 1800)) {

    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit();
}

$_SESSION["last_activity"] = time();

/* SESSION HIJACKING PROTECTION */
if ($_SESSION["ip_address"] !== $_SERVER["REMOTE_ADDR"] ||
    $_SESSION["user_agent"] !== $_SERVER["HTTP_USER_AGENT"]) {

    session_unset();
    session_destroy();
    header("Location: ../index.php");
    exit();
}
?>
