<?php
$host = "roundhouse.proxy.rlwy.net";
$user = "root";
$pass = "WQbLBzDhdKECgWNxaZLyHjzpVRICZUia";
$dbname = "railway";
$port = 50252;

$conn = new mysqli($host, $user, $pass, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
