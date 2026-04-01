<?php
session_start();
require_once "db.php";

/* =========================
   CSRF TOKEN VALIDATION
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!isset($_POST['csrf_token']) || 
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Invalid CSRF token.");
    }

    /* =========================
       INPUT SANITIZATION
    ========================= */
    $username = trim($_POST["username"]);
    $password = $_POST["password"];

    if (empty($username) || empty($password)) {
        die("All fields are required.");
    }

    /* =========================
       BRUTE FORCE PROTECTION
    ========================= */
    $limit_stmt = $conn->prepare(
        "SELECT COUNT(*) AS attempts 
         FROM login_attempts 
         WHERE username = ? 
         AND attempt_time > (NOW() - INTERVAL 5 MINUTE)"
    );
    $limit_stmt->bind_param("s", $username);
    $limit_stmt->execute();
    $attempts_result = $limit_stmt->get_result()->fetch_assoc();
    $limit_stmt->close();

    if ($attempts_result["attempts"] >= 5) {
        die("Too many login attempts. Please try again later.");
    }

    /* =========================
       CHECK USER
    ========================= */
    $stmt = $conn->prepare(
        "SELECT id, password, email_verified 
         FROM users 
         WHERE username = ?"
    );
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {

        $user = $result->fetch_assoc();

        if ($user["email_verified"] == 0) {
            echo "<script>alert('Please verify your email address before logging in.');</script>";
        }
        elseif (password_verify($password, $user["password"])) {

            /* =========================
               SECURE SESSION
            ========================= */

            // Prevent session fixation
            session_regenerate_id(true);

            // Store secure session data
            $_SESSION["user_id"]     = $user["id"];
            $_SESSION["username"]    = $username;
            $_SESSION["session_id"]  = session_id(); // real PHP session ID
            $_SESSION["ip_address"]  = $_SERVER["REMOTE_ADDR"];
            $_SESSION["user_agent"]  = $_SERVER["HTTP_USER_AGENT"];
            $_SESSION["last_activity"] = time();

            /* =========================
               INSERT LOGIN HISTORY
            ========================= */
            $insert = $conn->prepare(
                "INSERT INTO login_history 
                 (session_id, user_id, username, login_time, ip_address) 
                 VALUES (?, ?, ?, NOW(), ?)"
            );
            $insert->bind_param(
                "siss",
                $_SESSION["session_id"],
                $user["id"],
                $username,
                $_SERVER["REMOTE_ADDR"]
            );
            $insert->execute();
            $insert->close();

            header("Location: /pulsekit/dashboard/main.php");
            exit();
        }
        else {
            // Record failed attempt
            $fail_stmt = $conn->prepare(
                "INSERT INTO login_attempts (username) VALUES (?)"
            );
            $fail_stmt->bind_param("s", $username);
            $fail_stmt->execute();
            $fail_stmt->close();

            echo "<script>alert('Invalid password');</script>";
        }

    } else {
        echo "<script>alert('User not found');</script>";
    }

    $stmt->close();
}
?>
