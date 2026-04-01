<?php
// includes/security.php

session_start();
require_once __DIR__ . "/../db.php";

/* =========================
   SECURE SESSION SETTINGS
========================= */
ini_set('session.use_strict_mode', 1);
ini_set('session.cookie_httponly', 1);

/* =========================
   AUTH CHECK
========================= */
if (!isset($_SESSION["user_id"])) {
    header("Location: /pulsekit/index.php");
    exit();
}

/* =========================
   SESSION HIJACK PROTECTION
========================= */
if (!isset($_SESSION["ip_address"])) {
    $_SESSION["ip_address"] = $_SERVER["REMOTE_ADDR"];
}

if (!isset($_SESSION["user_agent"])) {
    $_SESSION["user_agent"] = $_SERVER["HTTP_USER_AGENT"];
}

if ($_SESSION["ip_address"] !== $_SERVER["REMOTE_ADDR"] ||
    $_SESSION["user_agent"] !== $_SERVER["HTTP_USER_AGENT"]) {
    session_destroy();
    header("Location: /pulsekit/index.php");
    exit();
}

/* =========================
   AUTO LOGOUT (30 mins)
========================= */
$timeout_duration = 1800; // 30 minutes

if (isset($_SESSION["last_activity"]) &&
    (time() - $_SESSION["last_activity"]) > $timeout_duration) {

    session_destroy();
    header("Location: /pulsekit/index.php");
    exit();
}

$_SESSION["last_activity"] = time();

/* =========================
   LOG PAGE ACTIVITY
========================= */
if (isset($_SESSION["session_id"])) {

    $page = basename($_SERVER["PHP_SELF"]);

    $stmt = $conn->prepare(
        "INSERT INTO user_activity (session_id, page_visited, visited_at)
         VALUES (?, ?, NOW())"
    );
    $stmt->bind_param("ss", $_SESSION["session_id"], $page);
    $stmt->execute();
    $stmt->close();
}
?>