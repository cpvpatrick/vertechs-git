<?php
$host = "mysql.railway.internal";
$user = "root";
$pass = "WQbLBzDhdKECgWNxaZLyHjzpVRICZUia";
$dbname = "railway";
$port = 3306;

$conn = new mysqli($host, $user, $pass, $dbname, $port);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
