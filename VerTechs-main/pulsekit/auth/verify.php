<?php
session_start();

require_once __DIR__ . '/../db.php';

if (!isset($_GET['token'])) {
    die("Invalid verification link.");
}

$token = $_GET['token'];

$stmt = $conn->prepare(
    "UPDATE users 
     SET email_verified = 1, verification_token = NULL 
     WHERE verification_token = ?"
);

$stmt->bind_param("s", $token);
$stmt->execute();

if ($stmt->affected_rows === 1) {
    $_SESSION["success"] = "Email verified successfully. You may now log in.";
    header("Location: ../index.php");
    exit;
} else {
    echo "Invalid or expired verification link.";
}
