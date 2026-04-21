<?php
// =========================================
// MySQLi Connection (for user auth and app state)
// =========================================
$mysql_host = "roundhouse.proxy.rlwy.net"; // Replace with your MySQL host
$mysql_user = "root"; // Replace with your MySQL user
$mysql_pass = "WQbLBzDhdKECgWNxaZLyHjzpVRICZUia"; // Replace with your MySQL password
$mysql_dbname = "railway"; // Replace with your MySQL database name
$mysql_port = 50252; // Replace with your MySQL port

$conn = new mysqli($mysql_host, $mysql_user, $mysql_pass, $mysql_dbname, $mysql_port);

if ($conn->connect_error) {
    die("MySQL Connection failed: " . $conn->connect_error);
}

// =========================================
// PostgreSQL PDO Connection (for analytics data)
// =========================================
$pg_host = "monorail.proxy.rlwy.net"; // Replace with your PostgreSQL host
$pg_user = "postgres"; // Replace with your PostgreSQL user
$pg_pass = "bnTkDzlnRZDpzvzVMYWMglKCGcBlodMe"; // Replace with your PostgreSQL password
$pg_dbname = "railway"; // Replace with your PostgreSQL database name
$pg_port = "17050"; // Replace with your PostgreSQL port
$pg_schema = "retail"; // The schema where your API tables reside

try {
    $pdo_pg = new PDO(
        "pgsql:host=$pg_host;port=$pg_port;dbname=$pg_dbname;options='-c search_path=$pg_schema'",
        $pg_user,
        $pg_pass
    );
    // Set the PDO error mode to exception
    $pdo_pg->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("PostgreSQL Connection failed: " . $e->getMessage());
}

// Helper function to fetch data from PostgreSQL
function fetch_pg_data($pdo, $query, $params = []) {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Helper function to execute non-select queries on PostgreSQL
function execute_pg_query($pdo, $query, $params = []) {
    $stmt = $pdo->prepare($query);
    return $stmt->execute($params);
}

// You can now use $conn for MySQLi operations and $pdo_pg for PostgreSQL PDO operations

?>
