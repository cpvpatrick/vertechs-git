<?php
$host = "localhost";
$user = "root";        // change if needed
$pass = "";            // change if needed
$dbname = "pulsekit";

$conn = new mysqli("localhost", "root", "", "pulsekit");

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
