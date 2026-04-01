<?php
session_start();
include "db.php";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = $_POST["password"];

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

        // ❌ Email not verified
        if ($user["email_verified"] == 0) {
            echo "<script>alert('Please verify your email address before logging in.');</script>";
        }
        // ✅ Password correct & email verified
        elseif (password_verify($password, $user["password"])) {

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $username;

            header("Location: /dashboard/main.php");
            exit();
        }
        // ❌ Wrong password
        else {
            echo "<script>alert('Invalid password');</script>";
        }

    } else {
        echo "<script>alert('User not found');</script>";
    }

    $stmt->close();
}
?>
