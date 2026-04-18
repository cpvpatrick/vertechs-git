<?php
session_start();
require_once "db.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $username = trim($_POST["username"]);
    $password = $_POST["password"];

	    $stmt = $conn->prepare(
	        "SELECT id, password, email_verified, dataset_loaded, pipeline_executed 
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
        } 
        elseif (!password_verify($password, $user["password"])) {
            $error = "Incorrect username or password";
        } 
        else {

            // 🔒 Prevent session fixation
            session_regenerate_id(true);

            // ✅ Required session variables
            $_SESSION["user_id"] = $user["id"];
            $_SESSION["username"] = $username;
            $_SESSION["session_id"] = session_id();

            // ✅ REQUIRED for auth_check.php
            $_SESSION["ip_address"] = $_SERVER["REMOTE_ADDR"];
            $_SESSION["user_agent"] = $_SERVER["HTTP_USER_AGENT"];
            $_SESSION["last_activity"] = time();

	            // ✅ Initialize pipeline flags from database
	            $_SESSION["dataset_loaded"] = $user["dataset_loaded"];
	            $_SESSION["pipeline_executed"] = $user["pipeline_executed"];

            // ✅ Insert login history
            $stmt2 = $conn->prepare(
                "INSERT INTO login_history 
                 (user_id, username, login_time, session_id)
                 VALUES (?, ?, NOW(), ?)"
            );

            // Add this block right here:
if ($stmt2 === false) {
    die("MySQL Prepare Error: " . $conn->error);
}
            $stmt2->bind_param(
                "iss",
                $user["id"],
                $username,
                $_SESSION["session_id"]
            );
            $stmt2->execute();
            $stmt2->close();

            header("Location: /pulsekit/dashboard/pipeline.php");
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
    <!-- Apply saved theme before render to prevent flash -->
    <script>
        (function() {
            if (localStorage.getItem('pulsekit-theme') === 'dark') {
                document.documentElement.classList.add('preload-dark');
            }
        })();
    </script>
    <style>
        html.preload-dark body { background-color: #0f1117; }
        html.preload-dark .right-panel { background-color: #0f1117; }
    </style>
</head>
<body>

<div class="container">

    <div class="left-panel"></div>

    <div class="right-panel">
        <div class="login-wrapper fixed-layout">

            <div class="logo">
                <!-- Animated pulse bars -->
                <div class="pulse-lines">
                    <div class="pulse-line"></div>
                    <div class="pulse-line"></div>
                    <div class="pulse-line"></div>
                    <div class="pulse-line"></div>
                    <div class="pulse-line"></div>
                    <div class="pulse-line"></div>
                    <div class="pulse-line"></div>
                </div>
                <h1>Pulse<span>Kit</span></h1>
            </div>

            <form method="POST" class="login-form">
                <input type="text" name="username" placeholder="Enter username" required>
                <input type="password" name="password" placeholder="Enter password" required>

                <button type="submit" class="login-btn">Login</button>

                <?php if ($error !== ""): ?>
                    <p class="login-error">
                        <?= htmlspecialchars($error) ?>
                    </p>
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

<!-- THEME TOGGLE — BOTTOM RIGHT CORNER -->
<button class="theme-toggle-login" onclick="toggleTheme()" id="themeToggleBtn" title="Toggle dark/light mode">
    <span class="toggle-icon-login" id="themeIcon">🌙</span>
    <span class="toggle-label-login" id="themeLabel">Dark Mode</span>
    <div class="toggle-track-login">
        <div class="toggle-thumb-login"></div>
    </div>
</button>

<script>
const THEME_KEY = 'pulsekit-theme';

function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    document.getElementById('themeIcon').textContent  = isDark ? '☀️' : '🌙';
    document.getElementById('themeLabel').textContent = isDark ? 'Light Mode' : 'Dark Mode';
    localStorage.setItem(THEME_KEY, theme);
}

function toggleTheme() {
    const current = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
}

// Apply saved theme on load and remove preload class
(function() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(saved);
    document.documentElement.classList.remove('preload-dark');
})();
</script>

</body>
</html>