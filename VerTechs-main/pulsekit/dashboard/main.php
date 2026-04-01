<?php
session_start();

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PulseKit Dashboard</title>
    <link rel="stylesheet" href="/pulsekit/assets/main.css">
</head>
<body>

<div class="dashboard-container">

    <aside class="sidebar">
        <div class="sidebar-overlay"></div>

        <nav class="menu">

            <!-- LOGO -->
            <div class="menu-logo">
                <img src="/pulsekit/assets/pulsekit.png" alt="PulseKit Logo">
            </div>

            <a href="/pulsekit/dashboard/main.php" class="active">Home Page</a>
            <a href="/pulsekit/dashboard/seasonality.php">Seasonality<br>Profiles</a>
            <a href="/pulsekit/dashboard/trend.php">Trend Lines</a>
            <a href="/pulsekit/dashboard/comparison.php">Comparison</a>
            <a href="/pulsekit/dashboard/region.php">Region</a>
            <a href="/pulsekit/dashboard/cluster.php">Cluster</a>
            <a href="/pulsekit/dashboard/stock.php">Stock<br>Allocations</a>
            <a href="/pulsekit/dashboard/history.php">Login History</a>
            <a href="/pulsekit/auth/logout.php" class="logout">Logout</a>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="content">
        <!-- Dashboard content -->
    </main>

</div>

</body>
</html>
