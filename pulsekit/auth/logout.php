<?php
session_start();
require_once __DIR__ . "/../db.php";

if (isset($_SESSION["session_id"])) {

    $stmt = $conn->prepare(
        "UPDATE login_history 
         SET logout_time = NOW()
         WHERE session_id = ?"
    );
    $stmt->bind_param("s", $_SESSION["session_id"]);
    $stmt->execute();
    $stmt->close();
}

$_SESSION = [];
session_unset();
session_destroy();

header("Location: ../index.php");
exit();