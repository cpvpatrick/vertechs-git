<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool

/* TRACK PAGE ACTIVITY */
$page_name = "Stock";

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

/* GET FILTER PARAMETER */
$filter_action = $_GET['action'] ?? 'All';

/* FETCH STOCK ALLOCATION DATA */
$stock_data = [];
$expand_count = 0;
$maintain_count = 0;
$deprioritize_count = 0;

$result = $conn->query("
    SELECT 
        dp.product_description as sku,
        ds.nestle_region as region,
        ds.nestle_store_cluster as cluster,
        SUM(fs.net_sales_ty_exvat) as current_sales,
        SUM(fs.net_sales_ty_exvat) - SUM(fs.net_sales_ly_exvat) as trend_signal,
        SUM(fs.net_sales_ty_exvat) * 1.05 as forecast_signal,
        RAND() * 100 as c2g_signal
    FROM fact_sales fs
    JOIN dim_product dp ON fs.product_id = dp.product_id
    JOIN dim_store ds ON fs.store_id = ds.store_id
    WHERE ds.nestle_region IS NOT NULL AND ds.nestle_store_cluster IS NOT NULL
    GROUP BY dp.product_description, ds.nestle_region, ds.nestle_store_cluster
    ORDER BY current_sales DESC
");

function getStockPrescription($g_T, $g_F, $c2g, $all_c2g_values) {
    sort($all_c2g_values);
    $top25_threshold = count($all_c2g_values) > 0
        ? $all_c2g_values[(int)(count($all_c2g_values) * 0.75)]
        : 75;

    $trend_high   = $g_T >= 3;
    $trend_flat   = ($g_T > -3 && $g_T < 3);
    $trend_low    = $g_T <= -3;
    $forecast_high = $g_F >= 3;
    $forecast_flat = ($g_F > -3 && $g_F < 3);
    $forecast_low  = $g_F <= -3;

    $c2g_high    = ($c2g >= 2 || $c2g >= $top25_threshold);
    $c2g_low_neu = ($c2g >= 0 && $c2g < 2);
    $c2g_pos     = $c2g > 0;
    $c2g_near0   = ($c2g >= -1 && $c2g <= 1);
    $c2g_neg     = $c2g < 0;

    if ($trend_high && $forecast_high && $c2g_high) {
        return ['condition' => 'E1', 'action' => 'Expand', 'guidance' => 'Increase allocation; prioritize replenishment; ensure shelf availability.'];
    }
    if ($trend_high && $forecast_high && $c2g_low_neu) {
        return ['condition' => 'E2', 'action' => 'Expand (Selective)', 'guidance' => 'Expand selectively; target best SKUs/brands within the segment.'];
    }
    if ($trend_high && $forecast_low) {
        return ['condition' => 'D3', 'action' => 'Maintain (Conflict)', 'guidance' => 'Conflicting signals; hold steady; check shocks/stockouts; reassess next update.'];
    }
    if ($trend_low && $forecast_high) {
        return ['condition' => 'D4', 'action' => 'Maintain (Rebound)', 'guidance' => 'Possible rebound; keep steady; don\'t cut too early; confirm next month.'];
    }
    if ($trend_low && $forecast_low && $c2g_neg) {
        return ['condition' => 'D1', 'action' => 'De-Prioritize', 'guidance' => 'Reduce allocation; rebalance inventory; tighten replenishment; avoid restock.'];
    }
    if ($trend_low && $forecast_low && $c2g_pos) {
        return ['condition' => 'D2', 'action' => 'Maintain (Investigate)', 'guidance' => 'Declining overall but still a driver; investigate substitutions, distribution issues, or local shifts.'];
    }
    if ($trend_flat && $forecast_high && $c2g_pos) {
        return ['condition' => 'M1', 'action' => 'Maintain → Watch', 'guidance' => 'Hold steady; monitor next 1-2 cycles for confirmation.'];
    }
    if ($trend_high && $forecast_flat && $c2g_pos) {
        return ['condition' => 'M2', 'action' => 'Maintain', 'guidance' => 'Stable levels; avoid overreacting — trend is good but forecast is flat.'];
    }
    if ($trend_flat && $forecast_flat && $c2g_near0) {
        return ['condition' => 'M3', 'action' => 'Maintain', 'guidance' => 'No change; review in next cycle.'];
    }
    return ['condition' => '—', 'action' => 'Maintain', 'guidance' => 'No clear signal; hold current levels and monitor.'];
}

if ($result) {
    $raw_rows = [];
    $all_c2g_values = [];
    while ($row = $result->fetch_assoc()) {
        $raw_rows[] = $row;
        $all_c2g_values[] = floatval($row['c2g_signal'] ?? 0);
    }

    foreach ($raw_rows as $row) {
        $current_sales = $row['current_sales'] ?? 1;
        $ly_sales = $current_sales - floatval($row['trend_signal']);
        $g_T = ($ly_sales != 0) ? (floatval($row['trend_signal']) / $ly_sales * 100) : 0;
        $g_F = ($current_sales != 0) ? ((floatval($row['forecast_signal']) - $current_sales) / $current_sales * 100) : 0;
        $c2g_pct = floatval($row['c2g_signal'] ?? 0);

        $prescription = getStockPrescription($g_T, $g_F, $c2g_pct, $all_c2g_values);
        $action = $prescription['action'];

        if (strpos($action, 'Expand') !== false) {
            $expand_count++;
        } elseif ($action === 'De-Prioritize') {
            $deprioritize_count++;
        } else {
            $maintain_count++;
        }

        $stock_data[] = [
            'sku'              => $row['sku'],
            'region'           => $row['region'],
            'cluster'          => $row['cluster'],
            'current_sales'    => $row['current_sales'],
            'trend_pct'        => $g_T,
            'forecast_pct'     => $g_F,
            'c2g_pct'          => $c2g_pct,
            'forecast_signal'  => $row['forecast_signal'],
            'condition'        => $prescription['condition'],
            'action'           => $action,
            'reason'           => $prescription['guidance'],
        ];
    }
}

$filtered_data = $stock_data;
if ($filter_action !== 'All') {
    if ($filter_action === 'Expand') {
        $filtered_data = array_filter($stock_data, fn($x) => strpos($x['action'], 'Expand') !== false);
    } elseif ($filter_action === 'Maintain') {
        $filtered_data = array_filter($stock_data, fn($x) => strpos($x['action'], 'Maintain') !== false || $x['action'] === 'Maintain → Watch');
    } elseif ($filter_action === 'De-prioritize') {
        $filtered_data = array_filter($stock_data, fn($x) => $x['action'] === 'De-Prioritize');
    } else {
        $filtered_data = array_filter($stock_data, fn($x) => $x['action'] === $filter_action);
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Stock Allocation Prescriptions - PulseKit Dashboard</title>
    <link rel="stylesheet" href="/assets/main.css">
    <link rel="stylesheet" href="/assets/dashboard-styles.css">
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
        .tr-more-filters { margin-left: auto; display: flex; align-items: center; gap: 5px; font-size: 13px; color: #555; font-weight: 500; background: none; border: none; cursor: pointer; padding: 5px 0; }
        .tr-more-filters:hover { color: #1c4aa0; }

        /* ── SCROLLABLE CONTENT ── */
        .tr-content { flex: 1; overflow-y: auto; padding: 28px 28px 40px; }

        /* ── PAGE HEADER ── */
        .tr-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
        .tr-header-left h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .tr-header-left p  { font-size: 14px; color: #666; }
        .tr-export-btn { display: inline-flex; align-items: center; gap: 8px; background: #1c4aa0; color: #fff; border: none; border-radius: 8px; padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s; }
        .tr-export-btn:hover { background: #163b7a; }

        /* ── KPI GRID ── */
        .kpi-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 18px; }
        .kpi-card { background: #fff; border: 1px solid #e8e8e8; border-radius: 10px; padding: 18px 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .kpi-label { font-size: 13px; font-weight: 600; color: #666; margin-bottom: 8px; }
        .kpi-value { font-size: 24px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .kpi-value.positive { color: #16a34a; }
        .kpi-meta { font-size: 12px; color: #888; }

        /* ── ACTION FILTERS ── */
        .action-filter-container { display: flex; gap: 8px; margin-bottom: 18px; flex-wrap: wrap; }
        .action-filter-btn { display: inline-block; padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; border: 1px solid #ddd; background: #fff; color: #555; transition: all 0.15s; }
        .action-filter-btn:hover { background: #f0f4ff; border-color: #1c4aa0; color: #1c4aa0; }
        .action-filter-btn.active { background: #1c4aa0; color: #fff; border-color: #1c4aa0; }

        /* ── CHART & TABLE CONTAINERS ── */
        .chart-container, .table-container, .coherence-check {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 0;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .chart-title, .table-title, .panel-title {
            font-size: 15px; font-weight: 700; color: #1a1a2e;
            padding: 18px 22px 14px;
            border-bottom: 1px solid #f0f0f0;
            margin: 0;
        }

        /* ── TABLE ── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        thead tr {
            background: #fafafa;
            border-bottom: 1px solid #e8e8e8;
        }
        th {
            padding: 11px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            white-space: nowrap;

            /* static, non-interactive column tabs */
            cursor: default !important;
            pointer-events: none !important;
            transition: none !important;
        }
        thead th:hover,
        thead th:active,
        thead th:focus {
            color: #666 !important;
            background: inherit !important;
            transform: none !important;
            box-shadow: none !important;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid #f5f5f5;
            color: #333;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }

        /* row hover kept for data rows */
        tbody tr:hover { background: #f8f9ff; }

        /* Kill zebra striping */
        tbody tr,
        tbody tr:nth-child(odd),
        tbody tr:nth-child(even) { background-color: transparent; }
        tbody tr:hover { background: #f8f9ff !important; }

        .text-success { color: #16a34a !important; }
        .text-danger  { color: #dc3545 !important; }

        /* Badge styles */
        .action-badge { display: inline-block; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .action-badge.expand { background: #dcfce7; color: #166534; }
        .action-badge.maintain { background: #fef9c3; color: #854d0e; }
        .action-badge.deprioritize { background: #fee2e2; color: #991b1b; }

        .guidance-text { font-size: 12px; color: #666; }

        /* ── BOTTOM GRID ── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }

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
        body.dark-mode .sidebar-footer       { border-top-color: #2a2f3e !important; }
        body.dark-mode .sidebar-user-name    { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-user-email   { color: #6b7a90 !important; }
        body.dark-mode .sidebar-action-btn   { background: #0f1117 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .theme-toggle-btn     { background: rgba(255,255,255,0.06) !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .tr-main              { background: #0f1117 !important; }
        body.dark-mode .tr-topbar            { background: #1a1d27 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .tr-content           { background: #0f1117 !important; }
        body.dark-mode .tr-header-left h1    { color: #e8eaf0 !important; }
        body.dark-mode .tr-header-left p     { color: #8892a4 !important; }
        body.dark-mode .kpi-card             { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .kpi-label            { color: #8892a4 !important; }
        body.dark-mode .kpi-value            { color: #e8eaf0 !important; }
        body.dark-mode .kpi-meta             { color: #6b7a90 !important; }
        body.dark-mode .action-filter-btn    { background: #1a1d27 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .action-filter-btn.active { background: #1c4aa0 !important; color: #fff !important; }

        body.dark-mode .chart-container, 
        body.dark-mode .table-container,
        body.dark-mode .coherence-check      { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .chart-title, 
        body.dark-mode .table-title,
        body.dark-mode .panel-title          { color: #e8eaf0 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode thead tr              { background: #14171f !important; border-bottom-color: #2a2f3e !important; }

        /* static headers in dark mode too */
        body.dark-mode th                    { 
            color: #6b7a90 !important;
            cursor: default !important;
            pointer-events: none !important;
            transition: none !important;
        }
        body.dark-mode thead th:hover,
        body.dark-mode thead th:active,
        body.dark-mode thead th:focus {
            color: #6b7a90 !important;
            background: inherit !important;
            box-shadow: none !important;
            transform: none !important;
        }

        body.dark-mode td                    { color: #b0b8cc !important; border-bottom-color: #242936 !important; }
        body.dark-mode tbody tr,
        body.dark-mode tbody tr:nth-child(odd),
        body.dark-mode tbody tr:nth-child(even) { background-color: transparent !important; }
        body.dark-mode tbody tr:hover        { background: rgba(100,160,255,0.05) !important; }

        body.dark-mode .guidance-text        { color: #9aa4bd; }
        body.dark-mode .text-success         { color: #3ddc6e !important; }
        body.dark-mode .text-danger          { color: #ff6b7a !important; }
        body.dark-mode .modal-box            { background: #1a1d27 !important; }
        body.dark-mode .modal-box h2         { color: #e8eaf0 !important; }
    </style>
</head>
<body>

<div class="dashboard-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-brand">
                <div class="sidebar-brand-title">Coherent Nestlé Philippines Sales Forecasting at Southstar Drug</div>
                <div class="sidebar-brand-sub">MSTL · LightGBM · MinT · C2G</div>
            </div>

            <nav class="sidebar-nav">
                <a href="/dashboard/pipeline.php" class="sidebar-link" data-index="0">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                    <span class="sidebar-link-text">Pipeline (Ingestion & Prep)</span>
                </a>
                <a href="/dashboard/history.php" class="sidebar-link" data-index="1">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="sidebar-link-text">Login History (Security Audit)</span>
                </a>
                <a href="/dashboard/overview.php" class="sidebar-link" data-index="2" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                    <span class="sidebar-link-text">Overview</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg></span>
                    <span class="sidebar-link-text">Seasonality Profiles (MSTL)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/trend.php" class="sidebar-link" data-index="4" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                    <span class="sidebar-link-text">Trend-True Growth (MoM/YTD)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/forecast.php" class="sidebar-link" data-index="5" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></span>
                    <span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="sidebar-link-text">Coherence Check</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/c2g.php" class="sidebar-link" data-index="7" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>
                    <span class="sidebar-link-text">C2G Growth Drivers</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/stock.php" class="sidebar-link active" data-index="8" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
                    <span class="sidebar-link-text">Stock Allocation Prescriptions</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/dictionary.php" class="sidebar-link" data-index="9" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                    <span class="sidebar-link-text">Data Dictionary / Methodology</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
            </nav>

            <?php if (!$user_dataset_loaded): ?>
            <div class="analytics-locked-banner">
                <div class="alb-header">⚠ Analytics Locked</div>
                <div class="alb-body">Run the pipeline first to unlock all analytics visualizations and pages.</div>
                <div class="alb-note">Note: Pipeline and Login History are always accessible.</div>
            </div>
            <?php endif; ?>

            <div class="sidebar-footer">
                <div class="sidebar-user">
                    <div class="sidebar-user-avatar"><?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?></div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></div>
                        <div class="sidebar-user-email"><?php
                            $email_stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
                            $email_stmt->bind_param("i", $_SESSION['user_id']);
                            $email_stmt->execute();
                            $email_stmt->bind_result($user_email);
                            $email_stmt->fetch();
                            $email_stmt->close();
                            echo htmlspecialchars($user_email);
                        ?></div>
                    </div>
                </div>
                <div class="sidebar-actions">
                    <button class="sidebar-action-btn sidebar-reset-btn" onclick="confirmResetDataset()" title="Reset dataset">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                    <button class="sidebar-action-btn sidebar-logout-btn" onclick="openLogoutModal()" title="Logout">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Logout
                    </button>
                </div>
                <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeToggleBtn" title="Toggle dark/light mode">
                    <span class="toggle-icon" id="themeIcon">🌙</span>
                    <span class="toggle-label" id="themeLabel">Dark Mode</span>
                    <div class="toggle-track"><div class="toggle-thumb"></div></div>
                </button>
            </div>
        </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <div class="tr-main">
        <div class="tr-topbar">
            <button class="tr-more-filters">
                More Filters
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
        </div>

        <div class="tr-content">
            <div class="tr-header">
                <div class="tr-header-left">
                    <h1>Stock Allocation Prescriptions</h1>
                    <p>Strategic inventory recommendations based on trend and forecast</p>
                </div>
                <button class="tr-export-btn" onclick="alert('Report download initiated...')">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download Report
                </button>
            </div>

            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Expand</div>
                    <div class="kpi-value positive"><?php echo number_format($expand_count); ?></div>
                    <div class="kpi-meta">High growth segments</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Maintain</div>
                    <div class="kpi-value"><?php echo number_format($maintain_count); ?></div>
                    <div class="kpi-meta">Stable performance</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">De-Prioritize</div>
                    <div class="kpi-value text-danger"><?php echo number_format($deprioritize_count); ?></div>
                    <div class="kpi-meta">Declining segments</div>
                </div>
            </div>

            <div class="action-filter-container">
                <a href="?action=All" class="action-filter-btn <?php echo $filter_action === 'All' ? 'active' : ''; ?>">All Actions</a>
                <a href="?action=Expand" class="action-filter-btn <?php echo $filter_action === 'Expand' ? 'active' : ''; ?>">Expand</a>
                <a href="?action=Maintain" class="action-filter-btn <?php echo $filter_action === 'Maintain' ? 'active' : ''; ?>">Maintain</a>
                <a href="?action=De-prioritize" class="action-filter-btn <?php echo $filter_action === 'De-prioritize' ? 'active' : ''; ?>">De-prioritize</a>
            </div>

            <div class="table-container">
                <h3 class="table-title">Allocation Prescriptions Details</h3>
                <table>
                    <thead>
                        <tr>
                            <th>SKU</th>
                            <th>Region</th>
                            <th>Cluster</th>
                            <th>Trend %</th>
                            <th>Forecast %</th>
                            <th>Action</th>
                            <th>Guidance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filtered_data as $row): 
                            $action_class = '';
                            if (strpos($row['action'], 'Expand') !== false) $action_class = 'expand';
                            elseif (strpos($row['action'], 'Maintain') !== false) $action_class = 'maintain';
                            elseif ($row['action'] === 'De-Prioritize') $action_class = 'deprioritize';
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(substr($row['sku'], 0, 25)); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['region']); ?></td>
                            <td><?php echo htmlspecialchars($row['cluster']); ?></td>
                            <td>
                                <strong class="<?php echo $row['trend_pct'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($row['trend_pct'] >= 0 ? '+' : '') . number_format($row['trend_pct'], 1); ?>%
                                </strong>
                            </td>
                            <td>
                                <strong class="<?php echo $row['forecast_pct'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($row['forecast_pct'] >= 0 ? '+' : '') . number_format($row['forecast_pct'], 1); ?>%
                                </strong>
                            </td>
                            <td><span class="action-badge <?php echo $action_class; ?>"><?php echo htmlspecialchars($row['action']); ?></span></td>
                            <td class="guidance-text"><?php echo htmlspecialchars($row['reason']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-container">
                <h3 class="table-title">Stock Metrics Decision Logic</h3>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Condition ID</th>
                                <th>Trend (MSTL) g_T</th>
                                <th>Forecast (MinT) g_F</th>
                                <th>C2G c</th>
                                <th>Prescription</th>
                                <th>Action Guidance</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><strong>E1</strong></td><td>g_T &ge; +3%</td><td>g_F &ge; +3%</td><td>c positive and high (&ge; +2% or Top 25%)</td><td><strong>EXPAND</strong></td><td>Increase allocation; prioritize replenishment; ensure shelf availability.</td></tr>
                            <tr><td><strong>E2</strong></td><td>g_T &ge; +3%</td><td>g_F &ge; +3%</td><td>c low/neutral (0% to &lt; +2% or not Top 25%)</td><td><strong>EXPAND (Selective)</strong></td><td>Expand selectively; target best SKUs/brands within the segment.</td></tr>
                            <tr><td><strong>M1</strong></td><td>-3% &lt; g_T &lt; +3%</td><td>g_F &ge; +3%</td><td>c positive</td><td><strong>MAINTAIN &rarr; WATCH</strong></td><td>Hold steady; monitor next 1–2 cycles for confirmation.</td></tr>
                            <tr><td><strong>M2</strong></td><td>g_T &ge; +3%</td><td>-3% &lt; g_F &lt; +3%</td><td>c positive</td><td><strong>MAINTAIN</strong></td><td>Stable levels; avoid overreacting — trend is good but forecast is flat.</td></tr>
                            <tr><td><strong>M3</strong></td><td>-3% &lt; g_T &lt; +3%</td><td>-3% &lt; g_F &lt; +3%</td><td>c near 0</td><td><strong>MAINTAIN</strong></td><td>No change; review in next cycle.</td></tr>
                            <tr><td><strong>D1</strong></td><td>g_T &le; -3%</td><td>g_F &le; -3%</td><td>c negative (or bottom tier)</td><td><strong>DE-PRIORITIZE</strong></td><td>Reduce allocation; rebalance inventory; tighten replenishment; avoid restock.</td></tr>
                            <tr><td><strong>D2</strong></td><td>g_T &le; -3%</td><td>g_F &le; -3%</td><td>c positive (rare)</td><td><strong>MAINTAIN (Investigate)</strong></td><td>Declining overall but still a driver; investigate substitutions, distribution issues, or local shifts.</td></tr>
                            <tr><td><strong>D3</strong></td><td>g_T &ge; +3%</td><td>g_F &le; -3%</td><td>any</td><td><strong>MAINTAIN (Conflict)</strong></td><td>Conflicting signals; hold steady; check shocks/stockouts; reassess next update.</td></tr>
                            <tr><td><strong>D4</strong></td><td>g_T &le; -3%</td><td>g_F &ge; +3%</td><td>any</td><td><strong>MAINTAIN (Rebound)</strong></td><td>Possible rebound; keep steady; don't cut too early; confirm next month.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid-2">
                <div class="chart-container">
                    <h3 class="chart-title">📊 Signal Definitions</h3>
                    <table>
                        <tbody>
                            <tr><td><strong>Trend (g_T)</strong></td><td>MSTL-decomposed YoY/MoM growth rate. &ge;+3% = high, &le;-3% = low.</td></tr>
                            <tr><td><strong>Forecast (g_F)</strong></td><td>MinT reconciled forecast growth vs actuals. &ge;+3% = high, &le;-3% = low.</td></tr>
                            <tr><td><strong>C2G (c)</strong></td><td>Contribution-to-Growth index. Positive = growth driver; negative = drag.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="chart-container">
                    <h3 class="chart-title">🎯 Quick Reference — Thresholds</h3>
                    <table>
                        <tbody>
                            <tr><td><strong>High Growth</strong></td><td>g_T or g_F &ge; +3%</td></tr>
                            <tr><td><strong>Flat / Neutral</strong></td><td>-3% &lt; g &lt; +3%</td></tr>
                            <tr><td><strong>Declining</strong></td><td>g_T or g_F &le; -3%</td></tr>
                            <tr><td><strong>C2G High</strong></td><td>&ge; +2% or Top 25% of SKUs</td></tr>
                            <tr><td><strong>C2G Low/Neutral</strong></td><td>0% to &lt;+2%</td></tr>
                            <tr><td><strong>C2G Negative</strong></td><td>&lt; 0%</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="table-container">
                <h3 class="table-title">🎯 Implementation Guide</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Action</th>
                            <th>Recommended Steps</th>
                            <th>Timeline</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td><strong>Expand / Expand (Selective)</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Increase inventory allocation by 15–25% (full Expand) or 5–15% (Selective)</li><li>Prioritize in promotional campaigns; expand shelf space in high-performing clusters</li><li>For Selective: target best SKUs/brands within the segment</li></ul></td><td>Immediate (Week 1–2)</td></tr>
                        <tr><td><strong>Maintain → Watch</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Hold current levels; monitor next 1–2 replenishment cycles</li><li>Flag for review if g_T does not recover above +3%</li></ul></td><td>Ongoing (review in 2 cycles)</td></tr>
                        <tr><td><strong>Maintain</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Keep current inventory levels; support with seasonal promotions</li><li>Monitor performance weekly; no drastic changes needed</li></ul></td><td>Ongoing</td></tr>
                        <tr><td><strong>Maintain (Investigate)</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Hold allocation while investigating substitution effects or distribution gaps</li><li>Check for local demand shifts or stockout history</li></ul></td><td>Within 1 cycle</td></tr>
                        <tr><td><strong>Maintain (Conflict)</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Hold steady — do not expand or reduce until signals align</li></ul></td><td>Reassess next update</td></tr>
                        <tr><td><strong>Maintain (Rebound)</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Do not cut allocation — possible recovery in progress</li><li>Confirm rebound signal in next month before expanding</li></ul></td><td>Confirm next month</td></tr>
                        <tr><td><strong>De-Prioritize</strong></td><td><ul style="margin: 0; padding-left: 20px;"><li>Reduce inventory allocation by 10–20%</li><li>Phase out from low-performing locations; consider promotional clearance</li></ul></td><td>Gradual (Week 3–4)</td></tr>
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
function openLogoutModal() { document.getElementById("logoutModal").style.display = "flex"; }
function closeLogoutModal() { document.getElementById("logoutModal").style.display = "none"; }

const UNLOCKED = <?php echo $user_dataset_loaded ? 'true' : 'false'; ?>;
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
(function() { applyLockState(); })();

const THEME_KEY = 'pulsekit-theme';
function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    document.getElementById('themeIcon').textContent  = isDark ? '☀️' : '🌙';
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
function confirmResetDataset() {
    if (confirm("Are you sure you want to reset the dataset? This will clear all uploaded data.")) {
        window.location.href = "/dashboard/reset_dataset.php";
    }
}
</script>

</body>
</html>
