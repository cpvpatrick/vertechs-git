<?php
session_start();
include "db.php";

$error = ""; // reset every page load

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

        if ($user["email_verified"] == 0) {
            $error = "Please verify your email address first.";
        } elseif (!password_verify($password, $user["password"])) {
            $error = "Incorrect username or password";
        } else {
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $username;
            header("Location: /pulsekit/dashboard/main.php");
            exit();
        }
    } else {
        $error = "Incorrect username or password";
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PulseKit</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="container">

    <div class="left-panel"></div>

    <div class="right-panel">
        <div class="login-wrapper fixed-layout">

            <div class="logo">
                <div class="pulse-line"></div>
                <div class="pulse-line mid"></div>
                <div class="pulse-line"></div>
                <h1>Pulse<span>Kit</span></h1>
            </div>

            <form method="POST" class="login-form">
                <input type="text" name="username" placeholder="Enter username" required>
                <input type="password" name="password" placeholder="Enter password" required>

                <button type="submit" class="login-btn">Login</button>

                <!-- ERROR ONLY AFTER FAILED POST -->
                <?php if ($error !== ""): ?>
                    <p class="login-error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>
            </form>

            <div class="text-links">
                <a href="#">Forgot Password?</a>
                <a href="#">Generate Captcha</a>
                <a href="/pulsekit/auth/register.php">No account yet? Create here</a>
            </div>

        </div>
    </div>

</div>

</body>
</html>
