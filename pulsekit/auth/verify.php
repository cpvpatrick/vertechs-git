<?php
session_start();
require_once __DIR__ . '/../db.php';

if (!isset($_GET['token']) || empty($_GET['token'])) {
    die("Invalid verification link.");
}

$token = $_GET['token'];

/* =========================
   VERIFY TOKEN
========================= */
$stmt = $conn->prepare(
    "SELECT id FROM users 
     WHERE verification_token = ? 
     AND email_verified = 0"
);
$stmt->bind_param("s", $token);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {

    $user = $result->fetch_assoc();

    // Mark email as verified + remove token
    $update = $conn->prepare(
        "UPDATE users 
         SET email_verified = 1, 
             verification_token = NULL 
         WHERE id = ?"
    );
    $update->bind_param("i", $user["id"]);
    $update->execute();
    $update->close();

    $stmt->close();

    echo "<script>
        alert('Email successfully verified! You may now log in.');
        window.location.href = '../index.php';
    </script>";
    exit();

} else {
    $stmt->close();
    die("Invalid or expired verification link.");
}
?>
