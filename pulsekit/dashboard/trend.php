<?php
require_once "../includes/auth_check.php";
 
/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool
 
 
/* TRACK PAGE ACTIVITY */
$page_name = "Trend";
 
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
$filter_region  = $_GET['region']  ?? 'All';
$filter_cluster = $_GET['cluster'] ?? 'All';
$filter_category = $_GET['category'] ?? 'All';
$filter_brand   = $_GET['brand']   ?? 'All';
$filter_sku     = $_GET['sku']     ?? 'All';

/* FETCH DATA FROM POSTGRESQL ANALYTICS DATABASE */
require_once "../db_analytics.php";

$trend_data = [];
$regions    = [];
$clusters   = [];
$skus       = [];

if ($pdo) {
    // ── Trend data from pipeline output ─────────────────────────────────────
    try {
        $sql    = "
            SELECT
                \"NESTLE REGION\"        AS region,
                \"NESTLE STORE CLUSTER\" AS cluster,
                \"Product Description\"  AS sku,
                \"Product Description\"  AS brand,
                \"Product Description\"  AS category,
                SUM(raw_sales)           AS sales_ty,
                NULL::numeric            AS sales_ly,
                NULL::numeric            AS units_ty,
                NULL::numeric            AS units_ly,
                AVG(raw_mom_growth_pct)  AS mom_growth,
                AVG(raw_yoy_growth_pct)  AS ytd_growth
            FROM api_trend_true_growth_table
            WHERE 1 = 1
        ";
        $params = [];
        if ($filter_region !== 'All') {
            $sql .= " AND \"NESTLE REGION\" = :region";
            $params[':region'] = $filter_region;
        }
        if ($filter_cluster !== 'All') {
            $sql .= " AND \"NESTLE STORE CLUSTER\" = :cluster";
            $params[':cluster'] = $filter_cluster;
        }
        if ($filter_sku !== 'All') {
            $sql .= " AND \"Product Description\" = :sku";
            $params[':sku'] = $filter_sku;
        }
        $sql .= "
            GROUP BY \"NESTLE REGION\", \"NESTLE STORE CLUSTER\", \"Product Description\"
            ORDER BY sales_ty DESC
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch()) {
            $trend_data[] = [
                'region'    => $row['region'],
                'cluster'   => $row['cluster'],
                'category'  => $row['category'],
                'brand'     => $row['brand'],
                'sku'       => $row['sku'],
                'sales_ty'  => floatval($row['sales_ty']  ?? 0),
                'sales_ly'  => floatval($row['sales_ly']  ?? 0),
                'units_ty'  => floatval($row['units_ty']  ?? 0),
                'units_ly'  => floatval($row['units_ly']  ?? 0),
                'mom_growth' => floatval($row['mom_growth'] ?? 0),
                'ytd_growth' => floatval($row['ytd_growth'] ?? 0),
            ];
        }
    } catch (PDOException $e) {
        error_log("Trend data query failed: " . $e->getMessage());
    }

    // ── Dropdown: distinct regions ────────────────────────────────────────────
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT \"NESTLE REGION\" AS nestle_region
            FROM api_trend_true_growth_table
            WHERE \"NESTLE REGION\" IS NOT NULL
            ORDER BY \"NESTLE REGION\"
        ");
        while ($row = $stmt->fetch()) {
            $regions[] = $row['nestle_region'];
        }
    } catch (PDOException $e) {
        error_log("Trend regions query failed: " . $e->getMessage());
    }

    // ── Dropdown: distinct clusters ───────────────────────────────────────────
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT \"NESTLE STORE CLUSTER\" AS nestle_store_cluster
            FROM api_trend_true_growth_table
            WHERE \"NESTLE STORE CLUSTER\" IS NOT NULL
            ORDER BY \"NESTLE STORE CLUSTER\"
        ");
        while ($row = $stmt->fetch()) {
            $clusters[] = $row['nestle_store_cluster'];
        }
    } catch (PDOException $e) {
        error_log("Trend clusters query failed: " . $e->getMessage());
    }

    // ── Dropdown: distinct SKUs ───────────────────────────────────────────────
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT \"Product Description\" AS product_description
            FROM api_trend_true_growth_table
            WHERE \"Product Description\" IS NOT NULL
            ORDER BY \"Product Description\"
        ");
        while ($row = $stmt->fetch()) {
            $skus[] = $row['product_description'];
        }
    } catch (PDOException $e) {
        error_log("Trend SKUs query failed: " . $e->getMessage());
    }
}
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Trend-True Growth (MoM/YTD) - PulseKit Dashboard</title>
    <link rel="stylesheet" href="/pulsekit/assets/main.css">
    <link rel="stylesheet" href="/pulsekit/assets/dashboard-styles.css">
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
        html.dark-preload .tr-topbar { background-color: #1a1d27; border-bottom-color: #2a2f3e; }
 
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
        .tr-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
 
        /* ── TOP FILTER BAR ── */
        .tr-topbar { display: flex; align-items: center; gap: 14px; padding: 10px 28px; background: #fff; border-bottom: 1px solid #e8e8e8; flex-shrink: 0; flex-wrap: wrap; }
        .tr-topbar-filter { display: flex; align-items: center; gap: 7px; }
        .tr-date-input { border: 1px solid #ddd; border-radius: 6px; padding: 5px 10px; font-size: 13px; color: #333; background: #fff; outline: none; transition: border-color 0.15s; }
        .tr-date-input:focus { border-color: #1c4aa0; }
        .tr-date-sep { font-size: 13px; color: #888; }
        .tr-metric-group { display: flex; align-items: center; gap: 6px; }
        .tr-metric-label { font-size: 13px; color: #555; font-weight: 500; }
        .tr-metric-btn { padding: 5px 14px; border-radius: 6px; font-size: 13px; font-weight: 600; border: 1px solid #ddd; background: #fff; color: #666; cursor: pointer; transition: all 0.15s; }
        .tr-metric-btn.active { background: #1c4aa0; color: #fff; border-color: #1c4aa0; }
        .tr-metric-btn:not(.active):hover { background: #f0f4ff; border-color: #1c4aa0; color: #1c4aa0; }
        .tr-more-filters { margin-left: auto; display: flex; align-items: center; gap: 5px; font-size: 13px; color: #555; font-weight: 500; background: none; border: none; cursor: pointer; padding: 5px 0; }
        .tr-more-filters:hover { color: #1c4aa0; }
 
        /* ── SCROLLABLE CONTENT ── */
        .tr-content { flex: 1; overflow-y: auto; padding: 28px 28px 40px; }
 
        /* ── PAGE HEADER ── */
        .tr-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
        .tr-header-left h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .tr-header-left p  { font-size: 14px; color: #666; }
        .tr-export-btn { display: inline-flex; align-items: center; gap: 8px; background: #1c4aa0; color: #fff; border: none; border-radius: 8px; padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s; white-space: nowrap; }
        .tr-export-btn:hover { background: #163b7a; }
 
        /* ── FILTER CARD ── */
        .filter-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-viewby-label {
            font-size: 13px; font-weight: 600; color: #555; white-space: nowrap;
        }
        .filter-group { display: flex; align-items: center; gap: 6px; flex-direction: row; }
        .filter-group label { font-size: 13px; color: #555; font-weight: 500; white-space: nowrap; }
        .filter-group select {
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            color: #333;
            background: #fff;
            outline: none;
            cursor: pointer;
            transition: border-color 0.15s;
        }
        .filter-group select:focus { border-color: #1c4aa0; }
 
        /* ── MAIN DATA TABLE ── */
        .table-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 0;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .table-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            padding: 18px 22px 14px;
            border-bottom: 1px solid #f0f0f0;
        }
        .table-container table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        .table-container table thead tr {
            background: #fafafa;
            border-bottom: 1px solid #e8e8e8;
        }

        /* ✅ headers fully non-interactable */
        .table-container table th {
            padding: 11px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            white-space: nowrap;

            cursor: default !important;
            pointer-events: none !important;
            user-select: text;
            transition: none !important;
            text-decoration: none !important;
        }
        .table-container table thead th:hover,
        .table-container table thead th:active,
        .table-container table thead th:focus,
        .table-container table thead th:focus-visible {
            color: #666 !important;
            background: inherit !important;
            text-decoration: none !important;
            box-shadow: none !important;
            outline: none !important;
            transform: none !important;
        }

        .table-container table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f5f5f5;
            color: #333;
            vertical-align: middle;
        }
        .table-container table tbody tr:last-child td { border-bottom: none; }
        .table-container table tbody tr:hover { background: #f8f9ff; }
 
        /* Kill zebra striping from dashboard-styles.css */
        .table-container table tbody tr,
        .table-container table tbody tr:nth-child(odd),
        .table-container table tbody tr:nth-child(even) { background-color: transparent; }
        .table-container table tbody tr:hover { background: #f8f9ff !important; }
 
        /* Growth value colors */
        .text-success { color: #16a34a !important; }
        .text-danger  { color: #dc3545 !important; }
 
        /* ── BOTTOM GRID ── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
 
        /* ── CHART CONTAINER (summary cards at bottom) ── */
        .chart-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 20px 22px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .chart-title {
            font-size: 14px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 14px;
        }
        .chart-container table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .chart-container table td { padding: 10px 0; border-bottom: 1px solid #f0f0f0; color: #333; }
        .chart-container table tr:last-child td { border-bottom: none; }
        .chart-container table tbody tr:hover { background: #f8f9ff; }
        .chart-container table tbody tr,
        .chart-container table tbody tr:nth-child(odd),
        .chart-container table tbody tr:nth-child(even) { background-color: transparent; }
        .chart-container table tbody tr:hover { background: #f8f9ff !important; }
 
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
        body.dark-mode .tr-main              { background: #0f1117 !important; }
        body.dark-mode .tr-topbar            { background: #1a1d27 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .tr-date-input        { background: #0f1117 !important; border-color: #2a2f3e !important; color: #e8eaf0 !important; }
        body.dark-mode .tr-metric-btn        { background: #0f1117 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .tr-metric-btn.active { background: #1c4aa0 !important; color: #fff !important; border-color: #1c4aa0 !important; }
        body.dark-mode .tr-metric-label      { color: #8892a4 !important; }
        body.dark-mode .tr-more-filters      { color: #8892a4 !important; }
        body.dark-mode .tr-content           { background: #0f1117 !important; }
        body.dark-mode .tr-header-left h1    { color: #e8eaf0 !important; }
        body.dark-mode .tr-header-left p     { color: #8892a4 !important; }
        body.dark-mode .filter-container     { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .filter-viewby-label  { color: #8892a4 !important; }
        body.dark-mode .filter-group label   { color: #8892a4 !important; }
        body.dark-mode .filter-group select  { background: #0f1117 !important; border-color: #2a2f3e !important; color: #e8eaf0 !important; }
        body.dark-mode .table-container      { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .table-title          { color: #e8eaf0 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .table-container table thead tr { background: #0f1117 !important; border-bottom-color: #2a2f3e !important; }

        /* ✅ static in dark mode too */
        body.dark-mode .table-container table th {
            color: #6b7a90 !important;
            cursor: default !important;
            pointer-events: none !important;
            transition: none !important;
            text-decoration: none !important;
        }
        body.dark-mode .table-container table thead th:hover,
        body.dark-mode .table-container table thead th:active,
        body.dark-mode .table-container table thead th:focus,
        body.dark-mode .table-container table thead th:focus-visible {
            color: #6b7a90 !important;
            background: inherit !important;
            text-decoration: none !important;
            box-shadow: none !important;
            outline: none !important;
            transform: none !important;
        }

        body.dark-mode .table-container table td  { color: #b0b8cc !important; border-bottom-color: #1e2233 !important; }
        body.dark-mode .table-container table tbody tr:hover { background: rgba(100,160,255,0.06) !important; }
        body.dark-mode .chart-container      { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .chart-title          { color: #e8eaf0 !important; }
        body.dark-mode .chart-container table td { color: #b0b8cc !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .chart-container table tbody tr:hover { background: rgba(100,160,255,0.06) !important; }
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
                <a href="/pulsekit/dashboard/pipeline.php" class="sidebar-link" data-index="0">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                    <span class="sidebar-link-text">Pipeline (Ingestion &amp; Prep)</span>
                </a>
                <a href="/pulsekit/dashboard/history.php" class="sidebar-link" data-index="1">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="sidebar-link-text">Login History (Security Audit)</span>
                </a>
                <a href="/pulsekit/dashboard/overview.php" class="sidebar-link" data-index="2" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                    <span class="sidebar-link-text">Overview</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                    <span class="sidebar-link-text">Seasonality Profiles (MSTL)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/trend.php" class="sidebar-link active" data-index="4" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                    <span class="sidebar-link-text">Trend-True Growth (MoM/YTD)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/forecast.php" class="sidebar-link" data-index="5" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                    <span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                    <span class="sidebar-link-text">Coherence Check</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/c2g.php" class="sidebar-link" data-index="7" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg></span>
                    <span class="sidebar-link-text">C2G Growth Drivers</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/stock.php" class="sidebar-link" data-index="8" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
                    <span class="sidebar-link-text">Stock Allocation Prescriptions</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/dictionary.php" class="sidebar-link" data-index="9" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                    <span class="sidebar-link-text">Data Dictionary/Methodology</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
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
                    <button class="sidebar-action-btn sidebar-reset-btn" onclick="confirmResetDataset()" title="Reset dataset"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg></button>
                    <button class="sidebar-action-btn sidebar-logout-btn" onclick="openLogoutModal()"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>Logout</button>
                </div>
                <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeToggleBtn"><span id="themeIcon">🌙</span><span class="toggle-label" id="themeLabel">Dark Mode</span><div class="toggle-track"><div class="toggle-thumb"></div></div></button>
            </div>
        </div>
    </aside>
 
    <div class="tr-main">
        <div class="tr-content">
            <div class="tr-header">
                <div class="tr-header-left">
                    <h1>Trend-True Growth (MoM / YTD)</h1>
                    <p>Growth metrics can be viewed as a table and filtered by region, cluster, and SKU</p>
                </div>
                <button class="tr-export-btn" onclick="exportCSV()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </button>
            </div>
 
            <div class="filter-container">
                <div class="filter-group">
                    <label>Region</label>
                    <select onchange="applyFilters()">
                        <option value="">All Regions</option>
                        <?php foreach ($regions as $r): ?>
                        <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $filter_region === $r ? 'selected' : ''; ?>><?php echo htmlspecialchars($r); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Cluster</label>
                    <select onchange="applyFilters()">
                        <option value="">All Clusters</option>
                        <?php foreach ($clusters as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_cluster === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>SKU</label>
                    <select onchange="applyFilters()">
                        <option value="">All SKUs</option>
                        <?php foreach ($skus as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $filter_sku === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars(substr($s, 0, 30)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
 
            <div class="table-container">
                <h3 class="table-title">Trend-True Growth Metrics</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Region</th>
                            <th>Cluster</th>
                            <th>Category</th>
                            <th>Brand</th>
                            <th>SKU</th>
                            <th>Sales TY</th>
                            <th>Sales LY</th>
                            <th>MoM Growth %</th>
                            <th>YTD Growth %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($trend_data as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['region']); ?></td>
                            <td><?php echo htmlspecialchars($row['cluster']); ?></td>
                            <td><?php echo htmlspecialchars($row['category']); ?></td>
                            <td><?php echo htmlspecialchars($row['brand']); ?></td>
                            <td><strong><?php echo htmlspecialchars(substr($row['sku'], 0, 25)); ?></strong></td>
                            <td>₱<?php echo number_format($row['sales_ty'], 2); ?></td>
                            <td>₱<?php echo number_format($row['sales_ly'], 2); ?></td>
                            <td><strong class="<?php echo $row['mom_growth'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($row['mom_growth'] > 0 ? '+' : '') . number_format($row['mom_growth'], 2); ?>%</strong></td>
                            <td><strong class="<?php echo $row['ytd_growth'] > 0 ? 'text-success' : 'text-danger'; ?>"><?php echo ($row['ytd_growth'] > 0 ? '+' : '') . number_format($row['ytd_growth'], 2); ?>%</strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
 
            <div class="grid-2">
                <div class="chart-container">
                    <h3 class="chart-title">📊 Growth Distribution</h3>
                    <table>
                        <tbody>
                            <tr>
                                <td><strong>Positive Growth</strong></td>
                                <td style="text-align: right; color: #28a745; font-weight: 700;">
                                    <?php echo count(array_filter($trend_data, fn($x) => $x['mom_growth'] > 0)); ?> items
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Negative Growth</strong></td>
                                <td style="text-align: right; color: #dc3545; font-weight: 700;">
                                    <?php echo count(array_filter($trend_data, fn($x) => $x['mom_growth'] < 0)); ?> items
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Average MoM Growth</strong></td>
                                <td style="text-align: right; font-weight: 700;">
                                    <?php 
                                    $avg_growth = count($trend_data) > 0 ? array_sum(array_column($trend_data, 'mom_growth')) / count($trend_data) : 0;
                                    echo number_format($avg_growth, 2) . '%';
                                    ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
 
                <div class="chart-container">
                    <h3 class="chart-title">📈 Top Performers</h3>
                    <table>
                        <tbody>
                            <?php 
                            $sorted_trend = $trend_data;
                            usort($sorted_trend, function($a, $b) {
                                return $b['mom_growth'] <=> $a['mom_growth'];
                            });
                            $top_performers = array_slice($sorted_trend, 0, 5);
                            foreach ($top_performers as $perf):
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars(substr($perf['sku'], 0, 20)); ?></strong></td>
                                <td style="text-align: right; color: #28a745; font-weight: 700;">
                                    +<?php echo number_format($perf['mom_growth'], 2); ?>%
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
 
        </div>
    </div>
 
</div>
 
<div id="logoutModal" class="modal-overlay">
    <div class="modal-box">
        <h2>Are you sure you want to logout?</h2>
        <div class="modal-buttons">
            <a href="/pulsekit/auth/logout.php" class="confirm-btn">Yes, Logout</a>
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
        fetch('/pulsekit/dashboard/reset_dataset.php', { method: 'POST', credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { if (d.success) window.location.href = '/pulsekit/dashboard/pipeline.php'; })
        .catch(() => alert('Network error. Please try again.'));
    }
}
 
function exportCSV() {
    const table = document.querySelector('.table-container table');
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows.map(r => Array.from(r.querySelectorAll('th,td')).map(c => '"' + c.innerText.replace(/"/g,'""') + '"').join(',')).join('\n');
    const a = document.createElement('a');
    a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'trend_growth.csv';
    a.click();
}
 
(function() {
    applyLockState();
})();
 
const THEME_KEY = 'pulsekit-theme';
 
function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    applyLockState();
    document.getElementById('themeIcon').textContent = isDark ? '☀️' : '🌙';
    document.getElementById('themeLabel').textContent = isDark ? 'Light Mode' : 'Dark Mode';
    localStorage.setItem(THEME_KEY, theme);
 
    document.querySelectorAll('td[style*="color"]').forEach(td => {
        const style = td.getAttribute('style') || '';
        if (style.includes('#28a745')) td.style.color = isDark ? '#3ddc6e' : '#28a745';
        else if (style.includes('#dc3545')) td.style.color = isDark ? '#ff6b7a' : '#dc3545';
        else if (style.includes('#666')) td.style.color = isDark ? '#8892a4' : '#666';
    });
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
    const selects = document.querySelectorAll('.filter-group select');
    const region = selects[0].value || 'All';
    const cluster = selects[1].value || 'All';
    const sku = selects[2].value || 'All';
    
    const params = new URLSearchParams();
    if (region !== 'All') params.set('region', region);
    if (cluster !== 'All') params.set('cluster', cluster);
    if (sku !== 'All') params.set('sku', sku);
    window.location.href = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
}
</script>
 
</body>
</html>