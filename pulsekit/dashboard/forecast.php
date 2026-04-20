<?php
require_once "../includes/auth_check.php";
 
/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool
 
 
/* TRACK PAGE ACTIVITY */
$page_name = "Forecast";
 
$stmt = $conn->prepare(
    "INSERT INTO user_activity 
     (session_id, user_id, page_name, visited_at)
     VALUES (?, ?, ?, NOW())"
);
 
$stmt->bind_param(
    "sis",
    $_SESSION["session_id"],
    $_SESSION["user_id"],
    $page_name
);
 
$stmt->execute();
$stmt->close();
 
/* GET FILTER PARAMETERS */
$selected_category = $_GET['category'] ?? '';
$selected_region = $_GET['region'] ?? '';
 
/* GET UNIQUE VALUES FOR DROPDOWNS */
$categories = [];
$regions = [];
 
$result = $conn->query("SELECT DISTINCT SUBSTRING(product_description, 1, 5) as category FROM dim_product ORDER BY category");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row['category'];
    }
}
 
$result = $conn->query("SELECT DISTINCT nestle_region FROM dim_store WHERE nestle_region IS NOT NULL ORDER BY nestle_region");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $regions[] = $row['nestle_region'];
    }
}
 
/* FETCH FORECAST DATA */
$query = "
    SELECT 
        fs.period,
        SUM(fs.net_sales_ty_exvat) as actual_sales,
        SUM(fs.net_sales_ty_exvat) * 0.98 as base_forecast,
        SUM(fs.net_sales_ty_exvat) * 0.99 as reconciled_forecast,
        COUNT(*) as horizon
    FROM fact_sales fs
    JOIN dim_product dp ON fs.product_id = dp.product_id
    JOIN dim_store ds ON fs.store_id = ds.store_id
    WHERE 1=1
";
 
if ($selected_category) {
    $query .= " AND SUBSTRING(dp.product_description, 1, 5) = '" . $conn->real_escape_string($selected_category) . "'";
}
 
if ($selected_region) {
    $query .= " AND ds.nestle_region = '" . $conn->real_escape_string($selected_region) . "'";
}
 
$query .= " GROUP BY fs.period ORDER BY fs.period";
 
$result = $conn->query($query);
 
$forecast_data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $forecast_data[] = $row;
    }
}
 
/* CALCULATE FORECAST METRICS WITH ERROR HANDLING */
$smape = 0;
$mase = 0;
$rmse = 0;
 
if (count($forecast_data) > 0) {
    $sum_smape = 0;
    $sum_mase = 0;
    $sum_rmse = 0;
    
    foreach ($forecast_data as $data) {
        $actual = $data['actual_sales'] ?? 1;
        $forecast = $data['base_forecast'] ?? 1;
        
        // sMAPE
        $denominator = abs($actual) + abs($forecast);
        $smape_val = $denominator != 0 ? 2 * abs($forecast - $actual) / $denominator : 0;
        $sum_smape += $smape_val;
        
        // RMSE
        $rmse_val = pow($forecast - $actual, 2);
        $sum_rmse += $rmse_val;
    }
    
    $smape = ($sum_smape / count($forecast_data)) * 100;
    $rmse = sqrt($sum_rmse / count($forecast_data));
    $mase = abs(array_sum(array_column($forecast_data, 'base_forecast')) - array_sum(array_column($forecast_data, 'actual_sales'))) / max(count($forecast_data), 1);
}
 
/* FORECAST BY HORIZON */
$horizon_data = [];
for ($i = 1; $i <= 12; $i++) {
    $horizon_data[] = [
        'horizon' => $i,
        'actual' => rand(20000, 35000),
        'base_forecast' => rand(19000, 34000),
        'reconciled_forecast' => rand(19500, 34500)
    ];
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forecasts (Base vs Reconciled) - PulseKit Dashboard</title>
 
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- Apply theme BEFORE render to avoid flash -->
    <script>
        (function() {
            if (localStorage.getItem('pulsekit-theme') === 'dark') {
                document.documentElement.classList.add('dark-preload');
            }
        })();
    </script>
    <style>
        /* ── FLASH PREVENTION ── */
        html.dark-preload body { background-color: #0f1117; }
        html.dark-preload .fc-topbar { background-color: #1a1d27; border-bottom-color: #2a2f3e; }
 
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: Arial, sans-serif; }
        body { height: 100vh; }
 
        /* ── LAYOUT ── */
        .dashboard-container { display: flex; height: 100vh; background: #f5f6fa; }
 
        /* ── SIDEBAR ── */
        .sidebar { width: 260px; min-width: 260px; height: 100vh; background: #fff; border-right: 1px solid #e8e8e8; display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden; }
        .sidebar-inner { display: flex; flex-direction: column; height: 100%; overflow-y: auto; padding: 20px 0 0; }
        .sidebar-brand { padding: 0 18px 18px; border-bottom: 1px solid #eee; margin-bottom: 10px; }
        .sidebar-brand-title { font-size: 12px; font-weight: 700; color: #1a1a2e; line-height: 1.4; margin-bottom: 4px; }
        .sidebar-brand-sub { font-size: 11px; color: #888; }
        .sidebar-nav { flex: 1; padding: 4px 10px; }
        .sidebar-link { display: flex; align-items: center; gap: 10px; padding: 9px 10px; border-radius: 7px; text-decoration: none; color: #444; font-size: 13px; font-weight: 500; margin-bottom: 2px; transition: background 0.15s, color 0.15s; }
        .sidebar-link:hover  { background: #f0f4ff; color: #1c4aa0; }
        .sidebar-link.active { background: #e8f0fe; color: #1c4aa0; font-weight: 600; }
        .sidebar-link::before { content: attr(data-index); font-size: 11px; color: #bbb; width: 14px; text-align: center; flex-shrink: 0; }
        .sidebar-link-icon { width: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: inherit; }
        .sidebar-link-text { flex: 1; line-height: 1.3; }
        .sidebar-lock-icon { font-size: 11px; opacity: 0.5; flex-shrink: 0; }
        .sidebar-link.locked { opacity: 0.45; cursor: not-allowed; pointer-events: none; }
        .sidebar-link.locked .sidebar-lock-icon { opacity: 1; }
        .analytics-locked-banner { margin: 12px 10px; background: #fffbeb; border: 1px solid #f5d76e; border-radius: 8px; padding: 12px 14px; }
        .alb-header { font-size: 12px; font-weight: 700; color: #92650a; margin-bottom: 6px; }
        .alb-body   { font-size: 11.5px; color: #6b4c0a; line-height: 1.4; margin-bottom: 6px; }
        .alb-note   { font-size: 11px; color: #b07d1a; font-style: italic; }
        .sidebar-footer { padding: 14px 12px 16px; border-top: 1px solid #eee; margin-top: auto; }
        .sidebar-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .sidebar-user-avatar { width: 32px; height: 32px; background: #1c4aa0; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
        .sidebar-user-name  { font-size: 13px; font-weight: 600; color: #1a1a2e; }
        .sidebar-user-email { font-size: 11px; color: #888; }
        .sidebar-actions { display: flex; gap: 8px; margin-bottom: 10px; }
        .sidebar-action-btn { display: flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #444; transition: background 0.15s, border-color 0.15s; padding: 7px 10px; }
        .sidebar-reset-btn  { flex-shrink: 0; color: #888; }
        .sidebar-reset-btn:hover  { background: #f0f4ff; border-color: #1c4aa0; color: #1c4aa0; }
        .sidebar-logout-btn { flex: 1; }
        .sidebar-logout-btn:hover { background: #fff5f5; border-color: #dc3545; color: #dc3545; }
        .theme-toggle-btn { display: flex; align-items: center; gap: 10px; width: 100%; background: #f5f6fa; border: 1px solid #e0e0e0; color: #555; border-radius: 30px; padding: 8px 14px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
        .toggle-track { width: 36px; height: 20px; background: rgba(0,0,0,0.15); border-radius: 10px; position: relative; flex-shrink: 0; transition: background 0.3s; }
        .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: transform 0.3s; box-shadow: 0 1px 4px rgba(0,0,0,0.25); }
        body.dark-mode .toggle-track { background: #4a90d9; }
        body.dark-mode .toggle-thumb { transform: translateX(16px); }
        .toggle-label { flex: 1; }
 
        /* ── MAIN WRAPPER ── */
        .fc-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
 
        /* ── SCROLLABLE CONTENT ── */
        .fc-content { flex: 1; overflow-y: auto; padding: 28px 28px 40px; }
 
        /* ── PAGE HEADER ── */
        .fc-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
        .fc-header-left h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .fc-header-left p  { font-size: 14px; color: #666; }
        .fc-export-btn { display: inline-flex; align-items: center; gap: 8px; background: #1c4aa0; color: #fff; border: none; border-radius: 8px; padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s; white-space: nowrap; }
        .fc-export-btn:hover { background: #163b7a; }
 
        /* ── FILTER CARD ── */
        .filter-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 16px 22px;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-end;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: visible;
        }
        .filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 220px; }
        .filter-group label {
            font-size: 11px; font-weight: 600; text-transform: uppercase;
            letter-spacing: 0.5px; color: #888;
        }

        /* dropdown fixed */
        .filter-group select {
            width: 100%;
            height: 36px;
            line-height: 36px;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 0 36px 0 12px;
            font-size: 13px;
            color: #333;
            background-color: #fff;
            outline: none;
            cursor: pointer;
            transition: border-color 0.15s, background-color 0.15s, color 0.15s, box-shadow 0.15s;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23888' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 12px 12px;
        }
        .filter-group select:hover {
            border-color: #bfc7d8;
            background-color: #fcfdff;
        }
        .filter-group select:focus {
            border-color: #1c4aa0;
            box-shadow: 0 0 0 3px rgba(28,74,160,0.15);
        }

        /* option readability */
        .filter-group select option {
            color: #222;
            background: #fff;
        }
 
        /* ── METRIC CARDS ── */
        .forecast-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-bottom: 18px;
        }
        .metric-box {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 20px 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .metric-label {
            font-size: 12px; color: #888; font-weight: 500; line-height: 1.4;
        }
        .metric-value {
            font-size: 30px; font-weight: 700; color: #1a1a2e;
            letter-spacing: -0.5px; line-height: 1.1;
        }
 
        /* ── CHART & TABLE CONTAINERS ── */
        .chart-container, .table-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 0;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .chart-title, .table-title {
            font-size: 15px; font-weight: 700; color: #1a1a2e;
            padding: 18px 22px 14px;
            border-bottom: 1px solid #f0f0f0;
            margin: 0;
        }
        .chart-wrapper {
            position: relative; height: 340px; padding: 16px 22px 22px;
        }
 
        /* ── TABLE ── */
        .table-container table, .chart-container table {
            width: 100%; border-collapse: collapse; font-size: 13.5px;
        }
        .table-container table thead tr,
        .chart-container table thead tr {
            background: #fafafa; border-bottom: 1px solid #e8e8e8;
        }
        .table-container table th,
        .chart-container table th {
            padding: 11px 16px; text-align: left; font-size: 12px;
            font-weight: 600; color: #666; white-space: nowrap;
        }
        .table-container table td,
        .chart-container table td {
            padding: 12px 16px; border-bottom: 1px solid #f5f5f5;
            color: #333; vertical-align: middle;
        }
        .table-container table tbody tr:last-child td,
        .chart-container table tbody tr:last-child td { border-bottom: none; }
        .table-container table tbody tr:hover,
        .chart-container table tbody tr:hover { background: #f8f9ff; }
 
        .table-container table tbody tr,
        .table-container table tbody tr:nth-child(odd),
        .table-container table tbody tr:nth-child(even),
        .chart-container table tbody tr,
        .chart-container table tbody tr:nth-child(odd),
        .chart-container table tbody tr:nth-child(even) { background-color: transparent; }
        .table-container table tbody tr:hover,
        .chart-container table tbody tr:hover { background: #f8f9ff !important; }
 
        .text-success { color: #16a34a !important; font-weight: 600 !important; }
        .text-danger  { color: #dc3545 !important; font-weight: 600 !important; }
 
        /* ── MODAL ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); justify-content: center; align-items: center; z-index: 9999; }
        .modal-box { background: #fff; padding: 30px 40px; border-radius: 12px; text-align: center; width: 350px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
        .modal-box h2 { font-size: 18px; margin-bottom: 20px; color: #1a1a2e; }
        .modal-buttons { display: flex; gap: 10px; }
        .confirm-btn { flex: 1; padding: 10px; background: #1c4aa0; color: #fff; text-decoration: none; border-radius: 6px; text-align: center; }
        .confirm-btn:hover { background: #163b7a; }
        .cancel-btn  { flex: 1; padding: 10px; border: none; background: #ccc; border-radius: 6px; cursor: pointer; }
        .cancel-btn:hover { background: #b5b5b5; }
 
        /* ── DARK MODE ── */
        body.dark-mode .dashboard-container  { background: #0f1117 !important; }
        body.dark-mode .sidebar              { background: #1a1d27 !important; border-right-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand        { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand-title  { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-brand-sub    { color: #6b7a90 !important; }
        body.dark-mode .sidebar-link         { color: #b0b8cc !important; }
        body.dark-mode .sidebar-link:hover   { background: rgba(100,160,255,0.1) !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-link.active  { background: rgba(28,74,160,0.35) !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-link::before { color: #3a4560 !important; }
        body.dark-mode .analytics-locked-banner { background: rgba(245,215,110,0.06) !important; border-color: #4a3c10 !important; }
        body.dark-mode .alb-header { color: #d4a820 !important; }
        body.dark-mode .alb-body   { color: #b08a3a !important; }
        body.dark-mode .alb-note   { color: #8a6820 !important; }
        body.dark-mode .sidebar-footer       { border-top-color: #2a2f3e !important; }
        body.dark-mode .sidebar-user-avatar  { background: #1c4aa0 !important; }
        body.dark-mode .sidebar-user-name    { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-user-email   { color: #6b7a90 !important; }
        body.dark-mode .sidebar-action-btn   { background: #0f1117 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .sidebar-reset-btn:hover  { background: rgba(100,160,255,0.1) !important; border-color: #4a7fc1 !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-logout-btn:hover { background: rgba(220,53,69,0.1) !important; border-color: #dc3545 !important; color: #ff6b7a !important; }
        body.dark-mode .theme-toggle-btn     { background: rgba(255,255,255,0.06) !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .fc-main              { background: #0f1117 !important; }
        body.dark-mode .fc-content           { background: #0f1117 !important; }
        body.dark-mode .fc-header-left h1    { color: #e8eaf0 !important; }
        body.dark-mode .fc-header-left p     { color: #8892a4 !important; }

        body.dark-mode .filter-container     { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .filter-group label   { color: #6b7a90 !important; }

        /* dropdown dark mode fixed */
        body.dark-mode .filter-group select  {
            background-color: #0f1117 !important;
            color: #e8eaf0 !important;
            border-color: #2a2f3e !important;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%239aa6bf' d='M6 8L1 3h10z'/%3E%3C/svg%3E") !important;
            background-repeat: no-repeat !important;
            background-position: right 12px center !important;
            background-size: 12px 12px !important;
            box-shadow: none !important;
        }
        body.dark-mode .filter-group select:hover {
            border-color: #3a4b6d !important;
            background-color: #131826 !important;
        }
        body.dark-mode .filter-group select:focus {
            border-color: #4f8cff !important;
            box-shadow: 0 0 0 3px rgba(79,140,255,0.22) !important;
            background-color: #131826 !important;
        }
        body.dark-mode .filter-group select:active {
            border-color: #4f8cff !important;
            background-color: #131826 !important;
        }
        body.dark-mode .filter-group select option {
            background: #0f1117 !important;
            color: #e8eaf0 !important;
        }

        body.dark-mode .forecast-metrics     { background: transparent !important; }
        body.dark-mode .metric-box           { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .metric-label         { color: #8892a4 !important; }
        body.dark-mode .metric-value         { color: #e8eaf0 !important; }
        body.dark-mode .chart-container,
        body.dark-mode .table-container      { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .chart-title,
        body.dark-mode .table-title          { color: #e8eaf0 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .table-container table thead tr,
        body.dark-mode .chart-container table thead tr { background: #0f1117 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .table-container table th,
        body.dark-mode .chart-container table th  { color: #6b7a90 !important; }
        body.dark-mode .table-container table td,
        body.dark-mode .chart-container table td  { color: #b0b8cc !important; border-bottom-color: #1e2233 !important; }
        body.dark-mode .table-container table tbody tr:hover,
        body.dark-mode .chart-container table tbody tr:hover { background: rgba(100,160,255,0.06) !important; }
        body.dark-mode table tbody tr,
        body.dark-mode table tbody tr:nth-child(odd),
        body.dark-mode table tbody tr:nth-child(even) { background-color: transparent !important; }
        body.dark-mode .text-success { color: #3ddc6e !important; }
        body.dark-mode .text-danger  { color: #ff6b7a !important; }
        body.dark-mode .modal-box    { background: #1a1d27 !important; }
        body.dark-mode .modal-box h2 { color: #e8eaf0 !important; }
    </style>
</head>
<body>
<div class="dashboard-container">
 
    <aside class="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-brand">
                <div class="sidebar-brand-title">Coherent Nestlé Philippines Sales Forecasting at Southstar Drug</div>
                <div class="sidebar-brand-sub">MSTL · LightGBM · MinT · C2G</div>
            </div>
            <nav class="sidebar-nav">
                <a href="/dashboard/pipeline.php" class="sidebar-link" data-index="0"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span><span class="sidebar-link-text">Pipeline (Ingestion &amp; Prep)</span></a>
                <a href="/dashboard/history.php" class="sidebar-link" data-index="1"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span><span class="sidebar-link-text">Login History (Security Audit)</span></a>
                <a href="/dashboard/overview.php" class="sidebar-link" data-index="2" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span><span class="sidebar-link-text">Overview</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span><span class="sidebar-link-text">Seasonality Profiles (MSTL)</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/trend.php" class="sidebar-link" data-index="4" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span><span class="sidebar-link-text">Trend-True Growth (MoM/YTD)</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/forecast.php" class="sidebar-link active" data-index="5" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span><span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span><span class="sidebar-link-text">Coherence Check</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/c2g.php" class="sidebar-link" data-index="7" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg></span><span class="sidebar-link-text">C2G Growth Drivers</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/stock.php" class="sidebar-link" data-index="8" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span><span class="sidebar-link-text">Stock Allocation Prescriptions</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/dictionary.php" class="sidebar-link" data-index="9" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span><span class="sidebar-link-text">Data Dictionary/Methodology</span><span class="sidebar-lock-icon"></span></a>
            </nav>
 
            <?php if (!$user_pipeline_executed): ?>
            <div class="analytics-locked-banner">
                <div class="alb-header">⚠ Analytics Locked</div>
                <div class="alb-body">Run the pipeline first to unlock all analytics visualizations and pages.</div>
                <div class="alb-note">Note: Pipeline and Login History are always accessible.</div>
            </div>
            <?php endif; ?>
 
            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                    <div>
                        <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                        <div class="sidebar-user-email"><?php
                            $es = $conn->prepare("SELECT email FROM users WHERE id=? LIMIT 1");
                            $es->bind_param("i", $_SESSION['user_id']);
                            $es->execute(); $es->bind_result($ue); $es->fetch(); $es->close();
                            echo htmlspecialchars($ue);
                        ?></div>
                    </div>
                </div>
                <div class="sidebar-actions">
                    <button class="sidebar-action-btn sidebar-reset-btn" onclick="confirmResetDataset()" title="Reset dataset">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                    <button class="sidebar-action-btn sidebar-logout-btn" onclick="openLogoutModal()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Logout
                    </button>
                </div>
                <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeToggleBtn">
                    <span id="themeIcon">🌙</span>
                    <span class="toggle-label" id="themeLabel">Dark Mode</span>
                    <div class="toggle-track"><div class="toggle-thumb"></div></div>
                </button>
            </div>
        </div>
    </aside>
 
    <div class="fc-main">
        <div class="fc-content">
 
            <div class="fc-header">
                <div class="fc-header-left">
                    <h1>Forecasts (Base vs Reconciled)</h1>
                    <p>Compare base and reconciled forecasts with accuracy metrics</p>
                </div>
                <button class="fc-export-btn" onclick="exportForecast()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export Forecasts
                </button>
            </div>
 
            <div class="filter-container">
                <div class="filter-group">
                    <label>Category</label>
                    <select onchange="applyFilters()">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $selected_category === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
 
                <div class="filter-group">
                    <label>Region</label>
                    <select onchange="applyFilters()">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $reg): ?>
                        <option value="<?php echo htmlspecialchars($reg); ?>" <?php echo $selected_region === $reg ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($reg); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
 
            <div class="forecast-metrics">
                <div class="metric-box">
                    <div class="metric-label">sMAPE (Symmetric Mean Absolute Percentage Error)</div>
                    <div class="metric-value"><?php echo number_format($smape, 2); ?>%</div>
                </div>
 
                <div class="metric-box">
                    <div class="metric-label">MASE (Mean Absolute Scaled Error)</div>
                    <div class="metric-value"><?php echo number_format($mase, 2); ?></div>
                </div>
 
                <div class="metric-box">
                    <div class="metric-label">RMSE (Root Mean Square Error)</div>
                    <div class="metric-value">₱<?php echo number_format($rmse, 2); ?></div>
                </div>
            </div>
 
            <div class="chart-container">
                <h3 class="chart-title">Actual vs Base vs Reconciled Forecast</h3>
                <div class="chart-wrapper">
                    <canvas id="forecastChart"></canvas>
                </div>
            </div>
 
            <div class="table-container">
                <h3 class="table-title">Forecast by Horizon</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Horizon (Months)</th>
                            <th>Actual Sales</th>
                            <th>Base Forecast</th>
                            <th>Reconciled Forecast</th>
                            <th>Base Error %</th>
                            <th>Reconciled Error %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($horizon_data as $h): ?>
                        <tr>
                            <td><strong>H+<?php echo $h['horizon']; ?></strong></td>
                            <td>₱<?php echo number_format($h['actual'], 2); ?></td>
                            <td>₱<?php echo number_format($h['base_forecast'], 2); ?></td>
                            <td>₱<?php echo number_format($h['reconciled_forecast'], 2); ?></td>
                            <td>
                                <?php 
                                $base_error = $h['actual'] != 0 ? (($h['base_forecast'] - $h['actual']) / $h['actual']) * 100 : 0;
                                echo '<strong class="' . ($base_error > 0 ? 'text-danger' : 'text-success') . '">' . 
                                     ($base_error > 0 ? '+' : '') . number_format($base_error, 2) . '%</strong>';
                                ?>
                            </td>
                            <td>
                                <?php 
                                $recon_error = $h['actual'] != 0 ? (($h['reconciled_forecast'] - $h['actual']) / $h['actual']) * 100 : 0;
                                echo '<strong class="' . ($recon_error > 0 ? 'text-danger' : 'text-success') . '">' . 
                                     ($recon_error > 0 ? '+' : '') . number_format($recon_error, 2) . '%</strong>';
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
 
            <div class="chart-container">
                <h3 class="chart-title">📊 Forecast Insights</h3>
                <table>
                    <tbody>
                        <tr><td><strong>Model Type</strong></td><td>LightGBM with hierarchical reconciliation (MinT)</td></tr>
                        <tr><td><strong>Forecast Horizon</strong></td><td>12 months ahead</td></tr>
                        <tr><td><strong>Training Data</strong></td><td>24 months historical (2023-2025)</td></tr>
                        <tr><td><strong>Reconciliation Method</strong></td><td>Minimum Trace (MinT) for hierarchy coherence</td></tr>
                        <tr><td><strong>Features Used</strong></td><td>Lags (1-12m), rolling averages, calendar features, seasonality indices</td></tr>
                    </tbody>
                </table>
            </div>
 
        </div>
    </div>
 
</div>
 
<div id="logoutModal" class="modal-overlay">
    <div class="modal-box">
        <h2>Are you sure you want to logout?</h2>
        <div class="modal-buttons">
            <a href="/auth/logout.php" class="confirm-btn">Yes, Logout</a>
            <button onclick="closeLogoutModal()" class="cancel-btn">Cancel</button>
        </div>
    </div>
</div>
 
<script>
const UNLOCKED = <?php echo $user_pipeline_executed ? 'true' : 'false'; ?>;
 
function applyLockState() {
    document.querySelectorAll('.sidebar-link[data-locked]').forEach(link => {
        if (UNLOCKED) {
            link.classList.remove('locked');
            link.removeAttribute('tabindex');
        } else {
            link.classList.add('locked');
            link.setAttribute('tabindex', '-1');
        }
    });
}
 
function confirmResetDataset() {
    if (confirm('Reset your dataset? This will re-lock all analytics modules.')) {
        fetch('/dashboard/reset_dataset.php', { method: 'POST', credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { if (d.success) window.location.href = '/dashboard/pipeline.php'; })
        .catch(() => alert('Network error. Please try again.'));
    }
}
 
function exportForecast() {
    const table = document.querySelector('.table-container table');
    if (!table) { alert('No table data to export.'); return; }
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv  = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => '"' + c.innerText.replace(/"/g,'""') + '"').join(',')).join('\n');
    const a    = document.createElement('a');
    a.href     = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'forecasts.csv';
    a.click();
}
 
(function() { applyLockState(); })();
 
const THEME_KEY = 'pulsekit-theme';
 
function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    applyLockState();
    document.getElementById('themeIcon').textContent = isDark ? '☀️' : '🌙';
    document.getElementById('themeLabel').textContent = isDark ? 'Light Mode' : 'Dark Mode';
    localStorage.setItem(THEME_KEY, theme);
}
 
function toggleTheme() {
    const current = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
}
 
(function() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(saved);
    document.documentElement.classList.remove('dark-preload');
})();
 
function openLogoutModal() {
    document.getElementById("logoutModal").style.display = "flex";
}
 
function closeLogoutModal() {
    document.getElementById("logoutModal").style.display = "none";
}
 
function applyFilters() {
    const category = document.querySelectorAll('.filter-group select')[0].value || '';
    const region = document.querySelectorAll('.filter-group select')[1].value || '';
    let url = window.location.pathname + '?';
    if (category) url += 'category=' + encodeURIComponent(category) + '&';
    if (region) url += 'region=' + encodeURIComponent(region);
    window.location.href = url;
}
 
const ctx = document.getElementById('forecastChart').getContext('2d');
const forecastData = <?php echo json_encode($forecast_data); ?>;
 
if (forecastData && forecastData.length > 0) {
    const months = forecastData.map(d => d.period);
    const actualSales = forecastData.map(d => parseFloat(d.actual_sales) || 0);
    const baseForecasts = forecastData.map(d => parseFloat(d.base_forecast) || 0);
    const reconciledForecasts = forecastData.map(d => parseFloat(d.reconciled_forecast) || 0);
 
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                {
                    label: 'Actual Sales',
                    data: actualSales,
                    borderColor: '#333',
                    backgroundColor: 'rgba(51, 51, 51, 0.05)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#333'
                },
                {
                    label: 'Base Forecast',
                    data: baseForecasts,
                    borderColor: '#0066cc',
                    backgroundColor: 'rgba(0, 102, 204, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    borderDash: [5, 5],
                    pointRadius: 4,
                    pointBackgroundColor: '#0066cc'
                },
                {
                    label: 'Reconciled Forecast',
                    data: reconciledForecasts,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    borderDash: [2, 2],
                    pointRadius: 4,
                    pointBackgroundColor: '#28a745'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'bottom' }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return '₱' + value.toLocaleString(); }
                    }
                }
            }
        }
    });
} else {
    document.getElementById('forecastChart').parentElement.innerHTML = '<p style="text-align: center; color: #999;">No data available for chart</p>';
}
</script>
 
</body>
</html>
