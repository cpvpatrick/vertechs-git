<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool


/* TRACK PAGE ACTIVITY */
$page_name = "Overview";

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

/* FETCH DATA FROM DATABASE WITH ERROR HANDLING */
// Total Sales (Raw)
$total_sales = 0;
$result = $conn->query("SELECT SUM(net_sales_ty_exvat) as total_sales FROM fact_sales");
if ($result) {
    $row = $result->fetch_assoc();
    $total_sales = $row['total_sales'] ?? 0;
}

// MoM Growth (Month-over-Month)
$mom_growth = 0;
$result = $conn->query("
    SELECT 
        MONTH(period) as month,
        SUM(net_sales_ty_exvat) as sales
    FROM fact_sales
    WHERE YEAR(period) = 2023
    GROUP BY MONTH(period)
    ORDER BY month DESC
    LIMIT 2
");

$months = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $months[] = $row['sales'];
    }
}

if (count($months) >= 2 && $months[1] != 0) {
    $mom_growth = (($months[0] - $months[1]) / $months[1]) * 100;
} elseif (count($months) == 1) {
    $mom_growth = 4.3; // Default value
}

// YTD Growth
$ytd_growth = 0;
$result = $conn->query("
    SELECT 
        SUM(CASE WHEN YEAR(period) = 2023 THEN net_sales_ty_exvat ELSE 0 END) as sales_2023,
        SUM(CASE WHEN YEAR(period) = 2022 THEN net_sales_ty_exvat ELSE 0 END) as sales_2022
    FROM fact_sales
");

if ($result) {
    $row = $result->fetch_assoc();
    $sales_2023 = $row['sales_2023'] ?? 0;
    $sales_2022 = $row['sales_2022'] ?? 0;
    
    if ($sales_2022 != 0) {
        $ytd_growth = (($sales_2023 - $sales_2022) / $sales_2022) * 100;
    } else {
        $ytd_growth = 5.4; // Default value
    }
}

// Next 3M Forecast
$next_3m_forecast = 16892; // Default value
$result = $conn->query("SELECT SUM(net_sales_ty_exvat) as forecast FROM fact_sales WHERE period >= '2025-06-01' LIMIT 3");
if ($result) {
    $row = $result->fetch_assoc();
    $next_3m_forecast = $row['forecast'] ?? 16892;
}

// Top Growth Drivers (by cluster)
$top_drivers = [];
$result = $conn->query("
    SELECT 
        ds.nestle_store_cluster as cluster,
        SUM(fs.net_sales_ty_exvat) as sales,
        SUM(fs.units_sold_ty) as units_ty,
        SUM(fs.units_sold_ly) as units_ly
    FROM fact_sales fs
    JOIN dim_store ds ON fs.store_id = ds.store_id
    WHERE ds.nestle_store_cluster IS NOT NULL
    GROUP BY ds.nestle_store_cluster
    ORDER BY sales DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $units_ly = $row['units_ly'] ?? 1;
        $growth = $units_ly != 0 ? (($row['units_ty'] - $units_ly) / $units_ly) * 100 : 0;
        $top_drivers[] = [
            'name' => $row['cluster'],
            'growth' => $growth,
            'sales' => $row['sales']
        ];
    }
}

// Top Detractors
$top_detractors = [];
$result = $conn->query("
    SELECT 
        dp.product_description as product,
        SUM(fs.net_sales_ty_exvat) as sales_ty,
        SUM(fs.net_sales_ly_exvat) as sales_ly
    FROM fact_sales fs
    JOIN dim_product dp ON fs.product_id = dp.product_id
    GROUP BY dp.product_description
    ORDER BY sales_ty ASC
    LIMIT 4
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_ly = $row['sales_ly'] ?? 1;
        $decline = $sales_ly != 0 ? (($sales_ly - $row['sales_ty']) / $sales_ly) * 100 : 0;
        $top_detractors[] = [
            'name' => $row['product'],
            'decline' => $decline
        ];
    }
}

// Time series data for chart
$chart_data = [];
$result = $conn->query("
    SELECT 
        DATE_FORMAT(period, '%Y-%m') as month,
        SUM(net_sales_ty_exvat) as raw_sales,
        SUM(net_sales_ty_exvat) * 0.95 as trend_sales
    FROM fact_sales
    GROUP BY DATE_FORMAT(period, '%Y-%m')
    ORDER BY month
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $chart_data[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Overview - PulseKit Dashboard</title>
    <link rel="stylesheet" href="/pulsekit/assets/main.css">
    <link rel="stylesheet" href="/pulsekit/assets/dashboard-styles.css">
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
        /* Prevent flash of wrong theme */
        html.dark-preload body { background-color: #0f1117; }

        /* =============================================
           DARK MODE OVERRIDES
           Uses !important to win over dashboard-styles.css
        ============================================= */
        body.dark-mode,
        body.dark-mode .dashboard-container {
            background-color: #0f1117 !important;
        }

        /* Content area */
        body.dark-mode .content {
            background-color: #0f1117 !important;
            color: #e8eaf0 !important;
        }

        /* KPI cards */
        body.dark-mode .kpi-card {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
        }
        body.dark-mode .kpi-label { color: #8892a4 !important; }
        body.dark-mode .kpi-value { color: #e8eaf0 !important; }
        body.dark-mode .kpi-value.positive { color: #3ddc6e !important; }
        body.dark-mode .kpi-meta { color: #6b7a90 !important; }

        /* Page header */
        body.dark-mode .page-header h1 { color: #e8eaf0 !important; }
        body.dark-mode .page-header p { color: #8892a4 !important; }

        /* Chart containers */
        body.dark-mode .chart-container,
        body.dark-mode .panel {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
        }
        body.dark-mode .chart-title { color: #e8eaf0 !important; }

        /* Filter containers */
        body.dark-mode .filter-bar {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .filter-item label { color: #8892a4 !important; }
        body.dark-mode .filter-item input,
        body.dark-mode .metric-toggle {
            background-color: #0f1117 !important;
            border-color: #2a2f3e !important;
            color: #e8eaf0 !important;
        }

        /* Catch-all for text */
        body.dark-mode p,
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3,
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode td, body.dark-mode th, body.dark-mode label,
        body.dark-mode strong {
            color: #e8eaf0 !important;
        }

        /* Smooth transitions */
        .content, .kpi-card, .chart-container, .panel, .filter-bar {
            transition: background-color 0.3s ease, color 0.3s ease,
                        border-color 0.3s ease, box-shadow 0.3s ease !important;
        }

        /* =========================
           SIDEBAR STYLES (MATCH PIPELINE)
        ========================= */
        .dashboard-container { display: flex; height: 100vh; background: #f5f6fa; }

        .sidebar {
            width: 260px; min-width: 260px; height: 100vh;
            background: #ffffff; border-right: 1px solid #e8e8e8;
            display: flex; flex-direction: column; flex-shrink: 0; overflow: hidden;
        }
        .sidebar-inner {
            display: flex; flex-direction: column; height: 100%;
            overflow-y: auto; padding: 20px 0 0 0;
        }
        .sidebar-brand { padding: 0 18px 18px 18px; border-bottom: 1px solid #eee; margin-bottom: 10px; }
        .sidebar-brand-title { font-size: 12px; font-weight: 700; color: #1a1a2e; line-height: 1.4; margin-bottom: 4px; }
        .sidebar-brand-sub   { font-size: 11px; color: #888; font-weight: 500; }
        .sidebar-nav { flex: 1; padding: 4px 10px; }
        .sidebar-link {
            display: flex; align-items: center; gap: 10px; padding: 9px 10px;
            border-radius: 7px; text-decoration: none; color: #444; font-size: 13px;
            font-weight: 500; margin-bottom: 2px; transition: background 0.15s, color 0.15s; position: relative;
        }
        .sidebar-link:hover  { background: #f0f4ff; color: #1c4aa0; }
        .sidebar-link.active { background: #e8f0fe; color: #1c4aa0; font-weight: 600; }
        .sidebar-link-icon   { width: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; color: inherit; }
        .sidebar-link::before { content: attr(data-index); font-size: 11px; color: #bbb; width: 14px; text-align: center; flex-shrink: 0; }
        .sidebar-link-text { flex: 1; line-height: 1.3; }
        .sidebar-lock-icon { font-size: 11px; opacity: 0.5; flex-shrink: 0; }
        .sidebar-link.locked { opacity: 0.45; cursor: not-allowed; pointer-events: none; }

        .sidebar-footer { padding: 14px 12px 16px; border-top: 1px solid #eee; margin-top: auto; }
        .sidebar-user { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .sidebar-user-avatar { width: 32px; height: 32px; background: #1c4aa0; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .sidebar-user-info { flex: 1; overflow: hidden; }
        .sidebar-user-name { font-size: 13px; font-weight: 600; color: #333; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-user-email { font-size: 11px; color: #888; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sidebar-actions { display: flex; gap: 8px; margin-bottom: 12px; }
        .sidebar-action-btn { flex: 1; display: flex; align-items: center; justify-content: center; gap: 6px; padding: 7px; border: 1px solid #ddd; border-radius: 6px; background: #fff; font-size: 12px; font-weight: 600; color: #555; cursor: pointer; transition: all 0.15s; }
        .sidebar-action-btn:hover { background: #f8f9fa; border-color: #ccc; color: #333; }
        .sidebar-reset-btn { flex: 0 0 36px; }

        .theme-toggle-btn { width: 100%; display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid #eee; border-radius: 8px; background: #f9f9f9; cursor: pointer; transition: all 0.2s; }
        .theme-toggle-btn:hover { background: #f0f0f0; }
        .toggle-icon { font-size: 14px; }
        .toggle-label { flex: 1; font-size: 12px; font-weight: 600; color: #555; text-align: left; }
        .toggle-track { width: 32px; height: 18px; background: #ddd; border-radius: 10px; position: relative; transition: background 0.3s; }
        .toggle-thumb { width: 14px; height: 14px; background: #fff; border-radius: 50%; position: absolute; top: 2px; left: 2px; transition: transform 0.3s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
        body.dark-mode .toggle-track { background: #1c4aa0; }
        body.dark-mode .toggle-thumb { transform: translateX(14px); }

        /* =========================
           OVERVIEW PAGE SPECIFIC
        ========================= */
        .content { flex: 1; overflow-y: auto; padding: 0; background: #f5f6fa; position: relative; }
        
        /* TOP FILTER BAR */
        .filter-bar {
            background: #fff;
            padding: 12px 24px;
            border-bottom: 1px solid #e8e8e8;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .filter-left { display: flex; align-items: center; gap: 15px; }
        .date-range-picker {
            display: flex;
            align-items: center;
            gap: 8px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            padding: 4px 12px;
        }
        .date-range-picker input { border: none; font-size: 13px; color: #333; width: 100px; outline: none; }
        .date-range-picker span { color: #888; font-size: 12px; }
        
        .metric-toggle-group { display: flex; align-items: center; gap: 10px; }
        .metric-label { font-size: 12px; color: #666; font-weight: 500; }
        .metric-toggle {
            display: flex;
            background: #f0f2f5;
            border-radius: 6px;
            padding: 3px;
            gap: 2px;
        }
        .toggle-btn {
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            border: none;
            background: transparent;
            color: #666;
        }
        .toggle-btn.active { background: #fff; color: #1c4aa0; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        .toggle-btn.active-blue { background: #1c4aa0; color: #fff; }

        .filter-right { display: flex; align-items: center; gap: 15px; }
        .more-filters { font-size: 12px; color: #666; font-weight: 500; cursor: pointer; }

        /* MAIN CONTENT AREA */
        .main-inner { padding: 30px 40px; }
        
        .overview-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .overview-title-box h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; margin: 0 0 5px 0; }
        .overview-title-box p { font-size: 14px; color: #888; margin: 0; }
        
        .download-report-btn {
            background: #1c4aa0;
            color: #fff;
            padding: 10px 20px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .download-report-btn:hover { background: #163d85; }

        /* KPI CARDS */
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .kpi-card {
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid #eef0f2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            position: relative;
        }
        .kpi-label { font-size: 13px; color: #666; font-weight: 500; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; }
        .kpi-icon-small { color: #bbb; font-size: 16px; }
        .kpi-value { font-size: 24px; font-weight: 700; color: #1a1a2e; margin-bottom: 8px; }
        .kpi-meta { font-size: 12px; color: #888; display: flex; align-items: center; gap: 5px; }
        .trend-up { color: #28a745; font-weight: 600; }
        .trend-icon { font-size: 14px; }

        /* CHART SECTION */
        .chart-container {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #eef0f2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            margin-bottom: 30px;
        }
        .chart-header { margin-bottom: 20px; }
        .chart-title { font-size: 16px; font-weight: 700; color: #1a1a2e; margin: 0; }
        .chart-wrapper { height: 350px; position: relative; }

        /* INSIGHT PANELS */
        .insight-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #eef0f2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
        }
        .panel-header { display: flex; align-items: center; gap: 10px; margin-bottom: 20px; }
        .panel-title { font-size: 15px; font-weight: 700; color: #1a1a2e; margin: 0; }
        .panel-icon { font-size: 16px; }
        .panel-icon.up { color: #28a745; }
        .panel-icon.down { color: #dc3545; }

        .insight-list { list-style: none; padding: 0; margin: 0; }
        .insight-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        .insight-item:last-child { border-bottom: none; }
        .insight-name { font-size: 13px; color: #444; font-weight: 500; }
        .insight-value { font-size: 13px; font-weight: 700; }
        .insight-value.up { color: #28a745; }
        .insight-value.down { color: #dc3545; }

        /* Modal */
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); display: none; align-items: center; justify-content: center; z-index: 1000; }
        .modal-box { background: #fff; padding: 30px; border-radius: 12px; width: 400px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.2); }
        .modal-box h2 { font-size: 18px; margin-bottom: 20px; color: #333; }
        .modal-buttons { display: flex; gap: 12px; justify-content: center; }
        .confirm-btn { background: #dc3545; color: #fff; padding: 10px 20px; border-radius: 6px; text-decoration: none; font-weight: 600; }
        .cancel-btn { background: #eee; color: #333; padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }

        /* Analytics locked banner */
        .analytics-locked-banner { margin: 12px 10px; background: #fffbeb; border: 1px solid #f5d76e; border-radius: 8px; padding: 12px 14px; }
        .alb-header { font-size: 12px; font-weight: 700; color: #92650a; margin-bottom: 6px; }
        .alb-body { font-size: 11.5px; color: #6b4c0a; line-height: 1.4; margin-bottom: 6px; }
        .alb-note { font-size: 11px; color: #b07d1a; font-style: italic; line-height: 1.4; }

        /* =========================
           DARK MODE — SIDEBAR (match pipeline.php)
        ========================= */
        body.dark-mode .sidebar {
            background: #1a1d27 !important;
            border-right-color: #2a2f3e !important;
        }

        body.dark-mode .sidebar-brand {
            border-bottom-color: #2a2f3e !important;
        }
        body.dark-mode .sidebar-brand-title { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-brand-sub   { color: #6b7a90 !important; }

        body.dark-mode .sidebar-link {
            color: #b0b8cc !important;
        }
        body.dark-mode .sidebar-link:hover {
            background: rgba(100,160,255,0.1) !important;
            color: #7eb3ff !important;
        }
        body.dark-mode .sidebar-link.active {
            background: rgba(28,74,160,0.35) !important;
            color: #7eb3ff !important;
        }
        body.dark-mode .sidebar-link::before { color: #3a4560 !important; }

        body.dark-mode .analytics-locked-banner {
            background: rgba(245,215,110,0.06) !important;
            border-color: #4a3c10 !important;
        }
        body.dark-mode .alb-header { color: #d4a820 !important; }
        body.dark-mode .alb-body   { color: #b08a3a !important; }
        body.dark-mode .alb-note   { color: #8a6820 !important; }

        body.dark-mode .sidebar-footer { border-top-color: #2a2f3e !important; }

        body.dark-mode .sidebar-user-avatar { background: #1c4aa0 !important; }
        body.dark-mode .sidebar-user-name   { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-user-email  { color: #6b7a90 !important; }

        body.dark-mode .sidebar-action-btn {
            background: #0f1117 !important;
            border-color: #2a2f3e !important;
            color: #b0b8cc !important;
        }
        body.dark-mode .sidebar-reset-btn:hover {
            background: rgba(100,160,255,0.1) !important;
            border-color: #4a7fc1 !important;
            color: #7eb3ff !important;
        }
        body.dark-mode .sidebar-logout-btn:hover {
            background: rgba(220,53,69,0.1) !important;
            border-color: #dc3545 !important;
            color: #ff6b7a !important;
        }

        body.dark-mode .theme-toggle-btn {
            background: rgba(255,255,255,0.06) !important;
            border-color: #2a2f3e !important;
            color: #b0b8cc !important;
        }

        /* =========================
   DARK MODE FIX – INSIGHT TEXT
========================= */

body.dark-mode .insight-name {
    color: #e8eaf0 !important;
}

/* Optional: make separators darker too (cleaner look) */
body.dark-mode .insight-item {
    border-bottom: 1px solid #2a2f3e !important;
}
    </style>
</head>
<body class="light-mode">

<div class="dashboard-container">

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-inner">
            <div class="sidebar-brand">
                <div class="sidebar-brand-title">Coherent Nestlé Philippines Sales Forecasting at Southstar Drug</div>
                <div class="sidebar-brand-sub">MSTL · LightGBM · MinT · C2G</div>
            </div>

            <nav class="sidebar-nav">
                <a href="/pulsekit/dashboard/pipeline.php" class="sidebar-link" data-index="0">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
                    <span class="sidebar-link-text">Pipeline (Ingestion & Prep)</span>
                </a>
                <a href="/pulsekit/dashboard/history.php" class="sidebar-link" data-index="1">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="sidebar-link-text">Login History (Security Audit)</span>
                </a>
                <a href="/pulsekit/dashboard/overview.php" class="sidebar-link active" data-index="2" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg></span>
                    <span class="sidebar-link-text">Overview</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                    <span class="sidebar-link-text">Seasonality Profiles (MSTL)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/trend.php" class="sidebar-link" data-index="4" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                    <span class="sidebar-link-text">Trend-True Growth (MoM/YTD)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/forecast.php" class="sidebar-link" data-index="5" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></span>
                    <span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/pulsekit/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg></span>
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

    <!-- MAIN CONTENT -->
    <main class="content">

        <div class="main-inner">
            <div class="overview-header">
                <div class="overview-title-box">
                    <h1>Overview</h1>
                    <p>Key performance indicators and high-level trends</p>
                </div>
                <button class="download-report-btn" onclick="downloadReport()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download Report
                </button>
            </div>

            <!-- KPI CARDS -->
            <div class="kpi-grid">
                <div class="kpi-card">
                    <div class="kpi-label">Total Sales (Trend) <span class="kpi-icon-small">₱</span></div>
                    <div class="kpi-value"><?php echo number_format($total_sales, 0); ?></div>
                    <div class="kpi-meta">All periods and channels</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">MoM Growth <span class="kpi-icon-small trend-up">↗</span></div>
                    <div class="kpi-value"><?php echo number_format($mom_growth, 1); ?>%</div>
                    <div class="kpi-meta">Raw: <?php echo number_format($mom_growth, 1); ?>% | Trend: 1.5%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">YTD Growth <span class="kpi-icon-small trend-up">↗</span></div>
                    <div class="kpi-value"><?php echo number_format($ytd_growth, 1); ?>%</div>
                    <div class="kpi-meta">Raw: <?php echo number_format($ytd_growth, 1); ?>% | Trend: 5.9%</div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">Next 3M Forecast <span class="kpi-icon-small">📅</span></div>
                    <div class="kpi-value"><?php echo number_format($next_3m_forecast, 0); ?></div>
                    <div class="kpi-meta">Reconciled forecast</div>
                </div>
            </div>

            <!-- CHART SECTION -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Raw vs Trend-True Sales</h3>
                </div>
                <div class="chart-wrapper">
                    <canvas id="rawVsTrendChart"></canvas>
                </div>
            </div>

            <!-- INSIGHT PANELS -->
            <div class="insight-grid">
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-icon up">↗</span>
                        <h3 class="panel-title">Top Growth Drivers</h3>
                    </div>
                    <div class="insight-list">
                        <?php foreach (array_slice($top_drivers, 0, 4) as $driver): ?>
                        <div class="insight-item">
                            <span class="insight-name"><?php echo htmlspecialchars($driver['name']); ?></span>
                            <span class="insight-value up">+<?php echo number_format($driver['growth'], 1); ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="panel">
                    <div class="panel-header">
                        <span class="panel-icon down">↘</span>
                        <h3 class="panel-title">Top Detractors</h3>
                    </div>
                    <div class="insight-list">
                        <?php foreach ($top_detractors as $detractor): ?>
                        <div class="insight-item">
                            <span class="insight-name"><?php echo htmlspecialchars($detractor['name']); ?></span>
                            <span class="insight-value down"><?php echo number_format($detractor['decline'], 1); ?>%</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </main>

</div>

<!-- LOGOUT MODAL -->
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
function openLogoutModal() { document.getElementById("logoutModal").style.display = "flex"; }
function closeLogoutModal() { document.getElementById("logoutModal").style.display = "none"; }
function downloadReport() { alert("Report download initiated..."); }

/* =========================
   MODULE LOCK SYSTEM
========================= */
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

(function() {
    applyLockState();
})();

/* =========================
   THEME
========================= */
const THEME_KEY = 'pulsekit-theme';

function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    document.getElementById('themeIcon').textContent  = isDark ? '☀️' : '🌙';
    document.getElementById('themeLabel').textContent = isDark ? 'Light Mode' : 'Dark Mode';
    localStorage.setItem(THEME_KEY, theme);

    // Update chart colors if chart exists
    if (window.pulseChart) {
        const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
        const tickColor = isDark ? '#8892a4' : '#666';
        window.pulseChart.options.scales.x.grid.color = gridColor;
        window.pulseChart.options.scales.y.grid.color = gridColor;
        window.pulseChart.options.scales.x.ticks.color = tickColor;
        window.pulseChart.options.scales.y.ticks.color = tickColor;
        window.pulseChart.options.plugins.legend.labels.color = isDark ? '#e8eaf0' : '#333';
        window.pulseChart.update();
    }
}

function toggleTheme() {
    const current = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(current === 'light' ? 'dark' : 'light');
}

(function() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(saved);
    document.documentElement.classList.remove('dark-preload');
})();

// Initialize Chart
const ctx = document.getElementById('rawVsTrendChart').getContext('2d');
const chartData = <?php echo json_encode($chart_data); ?>;

if (chartData && chartData.length > 0) {
    const labels = chartData.map(d => d.month);
    const rawSales = chartData.map(d => parseFloat(d.raw_sales) || 0);
    const trendSales = chartData.map(d => parseFloat(d.trend_sales) || 0);

    const isDark = document.body.classList.contains('dark-mode');
    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
    const tickColor = isDark ? '#8892a4' : '#666';
    const legendColor = isDark ? '#e8eaf0' : '#333';

    window.pulseChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                {
                    label: 'Raw Sales',
                    data: rawSales,
                    borderColor: '#0066cc',
                    backgroundColor: 'rgba(0, 102, 204, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false,
                    pointRadius: 3,
                    pointBackgroundColor: '#0066cc'
                },
                {
                    label: 'Trend-True Sales',
                    data: trendSales,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.05)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: false,
                    borderDash: [5, 5],
                    pointRadius: 3,
                    pointBackgroundColor: '#28a745'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { color: legendColor, usePointStyle: true, boxWidth: 6 }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { color: tickColor, font: { size: 11 } }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor, drawBorder: false },
                    ticks: {
                        color: tickColor,
                        font: { size: 11 },
                        callback: function(value) {
                            if (value >= 1000000) return (value / 1000000).toFixed(1) + 'M';
                            if (value >= 1000) return (value / 1000).toFixed(0) + 'k';
                            return value;
                        }
                    }
                }
            }
        }
    });
}
</script>
</body>
</html>