<?php
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    if ($username === "admin" && $password === "1234") {
        echo "<script>alert('Login successful');</script>";
    } else {
        echo "<script>alert('Invalid credentials');</script>";
    }

    echo "<script>window.location='index.php';</script>";
}
?>