<?php
/**
 * db_analytics.php
 * PostgreSQL PDO connection for analytics data produced by the ichan-backend pipeline.
 *
 * Configure via environment variables (set in Railway or your hosting panel):
 *   PG_HOST    – PostgreSQL host           (default: 127.0.0.1)
 *   PG_PORT    – PostgreSQL port           (default: 5432)
 *   PG_USER    – PostgreSQL user           (default: postgres)
 *   PG_PASS    – PostgreSQL password       (default: Nestle123)
 *   PG_DB      – PostgreSQL database name  (default: nestle_forecasting)
 *   PG_SCHEMA  – Schema that holds api_*   (default: retail)
 *
 * On success, provides $pdo (PDO instance) and $pg_schema (string).
 * On failure, $pdo is null and an error is written to the PHP error log.
 */

$_pg_host   = getenv('PG_HOST')   ?: '127.0.0.1';
$_pg_port   = getenv('PG_PORT')   ?: '5432';
$_pg_user   = getenv('PG_USER')   ?: 'postgres';
$_pg_pass   = getenv('PG_PASS')   ?: 'Nestle123';
$_pg_db     = getenv('PG_DB')     ?: 'nestle_forecasting';
$pg_schema  = getenv('PG_SCHEMA') ?: 'retail';

$pdo = null;
try {
    $pdo = new PDO(
        "pgsql:host={$_pg_host};port={$_pg_port};dbname={$_pg_db}",
        $_pg_user,
        $_pg_pass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    // Set search_path so queries can reference api_* tables without schema prefix
    $pdo->exec("SET search_path TO {$pg_schema}, public");
} catch (PDOException $e) {
    error_log("Analytics DB (PostgreSQL) connection failed: " . $e->getMessage());
    $pdo = null;
}

unset($_pg_host, $_pg_port, $_pg_user, $_pg_pass, $_pg_db);
?>
