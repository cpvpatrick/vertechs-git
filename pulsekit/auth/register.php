<?php
session_start();

require_once __DIR__ . '/../db.php';

require_once __DIR__ . '/../vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/src/SMTP.php';
require_once __DIR__ . '/../smtp.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* =========================
   HANDLE FORM SUBMIT
========================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $email    = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm  = $_POST["confirm_password"];

    if (
        strlen($password) < 8 ||
        !preg_match('/[A-Z]/', $password) ||
        !preg_match('/[0-9]/', $password) ||
        !preg_match('/[\W]/', $password)
    ) {
        $_SESSION["error"] =
            "Password must be at least 8 characters, contain uppercase, number, and special character.";
        header("Location: register.php");
        exit;
    }

    if ($password !== $confirm) {
        $_SESSION["error"] = "Passwords do not match.";
        header("Location: register.php");
        exit;
    }

    $check = $conn->prepare(
    "SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1"
    );

    $check->bind_param("ss", $username, $email);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        $_SESSION["error"] = "Username or email already exists.";
        $check->close();
        header("Location: register.php");
        exit();
    }

$check->close();


    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $token  = bin2hex(random_bytes(32));

    $stmt = $conn->prepare(
        "INSERT INTO users (username, email, password, verification_token, email_verified)
         VALUES (?, ?, ?, ?, 0)"
    );
    $stmt->bind_param("ssss", $username, $email, $hashed, $token);
    $stmt->execute();

    $verifyLink = "http://localhost/auth/verify.php?token=$token";

    $mail = new PHPMailer(true);
    configureSMTP($mail);

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email, $username);
    $mail->isHTML(true);
    $mail->Subject = "Verify your PulseKit account";
    $mail->Body = "
        <h2>Welcome to PulseKit</h2>
        <p>Click below to verify your email:</p>
        <a href='$verifyLink'>Verify Email</a>
    ";

    $mail->send();

    echo "
<!DOCTYPE html>
<html>
<head>
    <title>Registration Successful</title>
    <link rel='stylesheet' href='/assets/style.css'>
    <meta http-equiv='refresh' content='3;url=../index.php'>
    <style>
        body {
            display:flex;
            justify-content:center;
            align-items:center;
            height:100vh;
            background:#f2f0ed;
            font-family:Segoe UI, Arial, sans-serif;
        }
        .success-box {
            background:white;
            padding:40px;
            border-radius:10px;
            box-shadow:0 10px 25px rgba(0,0,0,0.1);
            text-align:center;
        }
        .success-box h2 {
            color:#1c4aa0;
            margin-bottom:15px;
        }
        .success-box p {
            color:#444;
        }
    </style>
</head>
<body>
    <div class='success-box'>
        <h2>Registration Successful</h2>
        <p>Check your email for confirmation.</p>
        <p>Redirecting to login page...</p>
    </div>
</body>
</html>
";
exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | PulseKit</title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<div class="container">

    <!-- LEFT IMAGE PANEL -->
    <div class="left-panel"></div>

    <!-- RIGHT FORM PANEL -->
    <div class="right-panel">
        <div class="login-wrapper">

            <div class="logo">
                <h1>Pulse<span>Kit</span></h1>
            </div>

            <?php if (!empty($_SESSION["error"])): ?>
                <div class="login-error">
                    <?= $_SESSION["error"]; unset($_SESSION["error"]); ?>
                </div>
            <?php endif; ?>

            <form method="POST" class="login-form">
                <input type="text" name="username" placeholder="Username" required>
                <input type="email" name="email" placeholder="Email" required>
                <input type="password" name="password" placeholder="Password" required>
                <input type="password" name="confirm_password" placeholder="Confirm Password" required>

                <button type="submit" class="login-btn">Register</button>
            </form>

            <div class="text-links">
                <a href="../index.php" class="create-account">Back to Login</a>
            </div>

        </div>
    </div>

</div>

</body>
</html>
