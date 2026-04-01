<?php
$host = "localhost";
$user = "root";        // change if needed
$pass = "";            // change if needed
$dbname = "pulsekit";

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}
?>