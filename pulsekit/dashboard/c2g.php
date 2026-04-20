<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool


/* TRACK PAGE ACTIVITY */
$page_name = "C2G";

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
$drill_by = $_GET['drill_by'] ?? 'regions';

/* FETCH C2G DATA - CONTRIBUTION TO GROWTH */
// By Regions
$regions_c2g = [];
$total_growth = 0;

$result = $conn->query("
    SELECT 
        ds.nestle_region as segment,
        SUM(fs.net_sales_ty_exvat) as sales_ty,
        SUM(fs.net_sales_ly_exvat) as sales_ly,
        SUM(fs.units_sold_ty) as units_ty,
        SUM(fs.units_sold_ly) as units_ly
    FROM fact_sales fs
    JOIN dim_store ds ON fs.store_id = ds.store_id
    WHERE ds.nestle_region IS NOT NULL
    GROUP BY ds.nestle_region
    ORDER BY sales_ty DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_ly = $row['sales_ly'] ?? 1;
        $units_ly = $row['units_ly'] ?? 1;
        
        $growth = $row['sales_ty'] - $sales_ly;
        $total_growth += $growth;
        
        $growth_pct = $sales_ly != 0 ? (($row['sales_ty'] - $sales_ly) / $sales_ly) * 100 : 0;
        
        $regions_c2g[] = [
            'segment' => $row['segment'],
            'sales_ty' => $row['sales_ty'],
            'sales_ly' => $row['sales_ly'],
            'units_ty' => $row['units_ty'],
            'units_ly' => $row['units_ly'],
            'growth' => $growth,
            'growth_pct' => $growth_pct
        ];
    }
}

// By Clusters
$clusters_c2g = [];
$result = $conn->query("
    SELECT 
        ds.nestle_store_cluster as segment,
        SUM(fs.net_sales_ty_exvat) as sales_ty,
        SUM(fs.net_sales_ly_exvat) as sales_ly,
        SUM(fs.units_sold_ty) as units_ty,
        SUM(fs.units_sold_ly) as units_ly
    FROM fact_sales fs
    JOIN dim_store ds ON fs.store_id = ds.store_id
    WHERE ds.nestle_store_cluster IS NOT NULL
    GROUP BY ds.nestle_store_cluster
    ORDER BY sales_ty DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_ly = $row['sales_ly'] ?? 1;
        $growth = $row['sales_ty'] - $sales_ly;
        $growth_pct = $sales_ly != 0 ? (($row['sales_ty'] - $sales_ly) / $sales_ly) * 100 : 0;
        
        $clusters_c2g[] = [
            'segment' => $row['segment'],
            'sales_ty' => $row['sales_ty'],
            'sales_ly' => $row['sales_ly'],
            'units_ty' => $row['units_ty'],
            'units_ly' => $row['units_ly'],
            'growth' => $growth,
            'growth_pct' => $growth_pct
        ];
    }
}

// By Products (Brands)
$products_c2g = [];
$result = $conn->query("
    SELECT 
        SUBSTRING(dp.product_description, 1, 10) as segment,
        SUM(fs.net_sales_ty_exvat) as sales_ty,
        SUM(fs.net_sales_ly_exvat) as sales_ly,
        SUM(fs.units_sold_ty) as units_ty,
        SUM(fs.units_sold_ly) as units_ly
    FROM fact_sales fs
    JOIN dim_product dp ON fs.product_id = dp.product_id
    GROUP BY SUBSTRING(dp.product_description, 1, 10)
    ORDER BY sales_ty DESC
");

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_ly = $row['sales_ly'] ?? 1;
        $growth = $row['sales_ty'] - $sales_ly;
        $growth_pct = $sales_ly != 0 ? (($row['sales_ty'] - $sales_ly) / $sales_ly) * 100 : 0;
        
        $products_c2g[] = [
            'segment' => $row['segment'],
            'sales_ty' => $row['sales_ty'],
            'sales_ly' => $row['sales_ly'],
            'units_ty' => $row['units_ty'],
            'units_ly' => $row['units_ly'],
            'growth' => $growth,
            'growth_pct' => $growth_pct
        ];
    }
}

// Select data based on drill_by parameter
$display_data = [];
$drill_title = '';
switch ($drill_by) {
    case 'clusters':
        $display_data = $clusters_c2g;
        $drill_title = 'Clusters';
        break;
    case 'products':
        $display_data = $products_c2g;
        $drill_title = 'Products (Brands)';
        break;
    default:
        $display_data = $regions_c2g;
        $drill_title = 'Regions';
}

// Get top 10 contributors
$top_10 = array_slice($display_data, 0, 10);

// Calculate percentages
$max_growth = count($display_data) > 0 ? max(array_column($display_data, 'growth')) : 1;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>C2G Growth Drivers - PulseKit Dashboard</title>
    <link rel="stylesheet" href="/assets/main.css">
    <link rel="stylesheet" href="/assets/dashboard-styles.css">
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
        html.dark-preload .sidebar { background-color: #1a1d27; border-right-color: #2a2f3e; }

        /* ── BASE RESET ── */
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
        .sidebar-logout-btn { flex: 1; }
        .sidebar-logout-btn:hover { background: #fff5f5; border-color: #dc3545; color: #dc3545; }

        .theme-toggle-btn { display: flex; align-items: center; gap: 10px; width: 100%; background: #f5f6fa; border: 1px solid #e0e0e0; color: #555; border-radius: 30px; padding: 8px 14px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background 0.2s; }
        .toggle-track { width: 36px; height: 20px; background: rgba(0,0,0,0.15); border-radius: 10px; position: relative; flex-shrink: 0; transition: background 0.3s; }
        .toggle-thumb { position: absolute; top: 2px; left: 2px; width: 16px; height: 16px; background: #fff; border-radius: 50%; transition: transform 0.3s; box-shadow: 0 1px 4px rgba(0,0,0,0.25); }
        body.dark-mode .toggle-track { background: #4a90d9; }
        body.dark-mode .toggle-thumb { transform: translateX(16px); }
        .toggle-label { flex: 1; }

        /* ── MAIN WRAPPER ── */
        .c2g-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }

        /* ── PAGE HEADER ── */
        .c2g-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 22px; }
        .c2g-header-left h1 { font-size: 26px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .c2g-header-left p  { font-size: 14px; color: #666; }
        .c2g-export-btn { display: inline-flex; align-items: center; gap: 8px; background: #1c4aa0; color: #fff; border: none; border-radius: 8px; padding: 10px 20px; font-size: 13px; font-weight: 600; cursor: pointer; transition: background 0.15s; white-space: nowrap; text-decoration: none; }
        .c2g-export-btn:hover { background: #163b7a; }

        /* ── SCROLLABLE CONTENT ── */
        .c2g-content { flex: 1; overflow-y: auto; padding: 28px 28px 40px; }

        /* ── DRILL-DOWN FILTER (pill buttons like screenshot) ── */
        .filter-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 14px 20px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .filter-viewby-label { font-size: 13px; font-weight: 600; color: #555; white-space: nowrap; display: flex; align-items: center; gap: 8px; }
        .filter-viewby-label svg { color: #1c4aa0; }
        .drill-btn-group { display: flex; gap: 6px; }
        .drill-btn {
            padding: 6px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid #ddd;
            background: #fff;
            color: #666;
            cursor: pointer;
            transition: all 0.15s;
            text-decoration: none;
        }
        .drill-btn.active { background: #1c4aa0; color: #fff; border-color: #1c4aa0; }
        .drill-btn:not(.active):hover { background: #f0f4ff; border-color: #1c4aa0; color: #1c4aa0; }

        .contributions-banner {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 16px 22px;
            margin-bottom: 18px;
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        /* ── CHART CONTAINER ── */
        .chart-container {
            background: #fff;
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 18px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .chart-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 14px;
        }
        .chart-wrapper { height: 320px; position: relative; }
        .chart-container table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .chart-container table td { padding: 10px 0; border-bottom: 1px solid #f0f0f0; color: #333; }
        .chart-container table tr:last-child td { border-bottom: none; }
        .chart-container table tbody tr:nth-child(odd),
        .chart-container table tbody tr:nth-child(even) { background-color: transparent; }
        .chart-container table tbody tr:hover { background: #f8f9ff !important; }

        /* ── TABLE CONTAINER ── */
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

        /* ✅ STATIC COLUMN TABS (no hover, no pointer, no animation) */
        .table-container table th {
            padding: 11px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            white-space: nowrap;
            cursor: default !important;
            pointer-events: none !important;
            transition: none !important;
        }
        .table-container table thead th:hover,
        .table-container table thead th:active,
        .table-container table thead th:focus {
            background: inherit !important;
            color: #666 !important;
            transform: none !important;
            box-shadow: none !important;
        }

        .table-container table td {
            padding: 12px 16px;
            border-bottom: 1px solid #f5f5f5;
            color: #333;
            vertical-align: middle;
        }
        .table-container table tbody tr:last-child td { border-bottom: none; }
        .table-container table tbody tr:nth-child(odd),
        .table-container table tbody tr:nth-child(even) { background-color: transparent; }
        .table-container table tbody tr:hover { background: #f8f9ff !important; }

        /* ── BOTTOM GRID ── */
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }
        .grid-2 .chart-container { margin-bottom: 0; }

        .text-success { color: #16a34a !important; }
        .text-danger  { color: #dc3545 !important; }

        /* ── MODAL ── */
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 9999; justify-content: center; align-items: center; }
        .modal-overlay[style*="flex"] { display: flex; }
        .modal-box { background: #fff; border-radius: 12px; padding: 32px; max-width: 380px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .modal-box h2 { font-size: 18px; color: #1a1a2e; margin-bottom: 24px; }
        .modal-buttons { display: flex; gap: 12px; justify-content: center; }
        .confirm-btn { background: #dc3545; color: #fff; padding: 10px 24px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 14px; }
        .cancel-btn  { background: #f5f5f5; color: #333; padding: 10px 24px; border-radius: 8px; border: 1px solid #ddd; cursor: pointer; font-weight: 600; font-size: 14px; }

        /* ══════════════════════════════════════
           DARK MODE OVERRIDES
        ══════════════════════════════════════ */
        body.dark-mode,
        body.dark-mode .dashboard-container { background-color: #0f1117 !important; }

        body.dark-mode .sidebar { background: #1a1d27 !important; border-right-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand-title { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-brand-sub  { color: #6b7a90 !important; }
        body.dark-mode .sidebar-link { color: #8892a4 !important; }
        body.dark-mode .sidebar-link:hover { background: rgba(100,160,255,0.08) !important; color: #4da6ff !important; }
        body.dark-mode .sidebar-link.active { background: rgba(28,74,160,0.25) !important; color: #4da6ff !important; }
        body.dark-mode .sidebar-footer { border-top-color: #2a2f3e !important; }
        body.dark-mode .sidebar-user-name { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-user-email { color: #6b7a90 !important; }
        body.dark-mode .sidebar-action-btn { background: #1a1d27 !important; border-color: #2a2f3e !important; color: #8892a4 !important; }
        body.dark-mode .sidebar-logout-btn:hover { background: rgba(220,53,69,0.1) !important; border-color: #dc3545 !important; color: #ff6b7a !important; }
        body.dark-mode .theme-toggle-btn { background: #0f1117 !important; border-color: #2a2f3e !important; color: #8892a4 !important; }

        body.dark-mode .c2g-content { background: #0f1117 !important; }
        body.dark-mode .c2g-header-left h1 { color: #e8eaf0 !important; }
        body.dark-mode .c2g-header-left p  { color: #8892a4 !important; }

        body.dark-mode .filter-container { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .filter-viewby-label { color: #8892a4 !important; }
        body.dark-mode .drill-btn { background: #1a1d27 !important; border-color: #2a2f3e !important; color: #8892a4 !important; }
        body.dark-mode .drill-btn.active { background: #1c4aa0 !important; border-color: #1c4aa0 !important; color: #fff !important; }

        body.dark-mode .contributions-banner {
            background: #1a1d27 !important;
            border-color: #2a2f3e !important;
            color: #e8eaf0 !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
        }

        body.dark-mode .chart-container { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .chart-title { color: #e8eaf0 !important; }
        body.dark-mode .chart-container table td { color: #b0b8cc !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .chart-container table tbody tr:hover { background: rgba(100,160,255,0.06) !important; }

        body.dark-mode .table-container { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .table-title { color: #e8eaf0 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .table-container table thead tr { background: #0f1117 !important; border-bottom-color: #2a2f3e !important; }

        /* static headers also in dark mode */
        body.dark-mode .table-container table th {
            color: #6b7a90 !important;
            cursor: default !important;
            pointer-events: none !important;
            transition: none !important;
        }
        body.dark-mode .table-container table thead th:hover,
        body.dark-mode .table-container table thead th:active,
        body.dark-mode .table-container table thead th:focus {
            background: inherit !important;
            color: #6b7a90 !important;
            box-shadow: none !important;
            transform: none !important;
        }

        body.dark-mode .table-container table td { color: #b0b8cc !important; border-bottom-color: #1e2233 !important; }
        body.dark-mode .table-container table tbody tr:hover { background: rgba(100,160,255,0.06) !important; }

        body.dark-mode .text-success { color: #3ddc6e !important; }
        body.dark-mode .text-danger  { color: #ff6b7a !important; }
        body.dark-mode .modal-box { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .modal-box h2 { color: #e8eaf0 !important; }
        body.dark-mode .cancel-btn { background: #0f1117 !important; border-color: #2a2f3e !important; color: #e8eaf0 !important; }

        .sidebar, .chart-container, .table-container, .filter-container, .modal-box {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }
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
                <a href="/dashboard/forecast.php" class="sidebar-link" data-index="5" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span><span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span><span class="sidebar-link-text">Coherence Check</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/c2g.php" class="sidebar-link active" data-index="7" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg></span><span class="sidebar-link-text">C2G Growth Drivers</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/stock.php" class="sidebar-link" data-index="8" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span><span class="sidebar-link-text">Stock Allocation Prescriptions</span><span class="sidebar-lock-icon"></span></a>
                <a href="/dashboard/dictionary.php" class="sidebar-link" data-index="9" data-locked="true"><span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span><span class="sidebar-link-text">Data Dictionary/Methodology</span><span class="sidebar-lock-icon"></span></a>
            </nav>

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

    <div class="c2g-main">
        <div class="c2g-content">
            <div class="c2g-header">
                <div class="c2g-header-left">
                    <h1>C2G Growth Drivers</h1>
                    <p>Additive contribution to trend-true growth</p>
                </div>
                <a href="?drill_by=<?php echo urlencode($drill_by); ?>&export=csv" class="c2g-export-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export CSV
                </a>
            </div>

            <div class="filter-container">
                <div class="filter-viewby-label">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
                    Drill down by:
                </div>
                <div class="drill-btn-group">
                    <button class="drill-btn <?php echo $drill_by === 'regions' ? 'active' : ''; ?>" onclick="changeDrillBy('regions')">Regions</button>
                    <button class="drill-btn <?php echo $drill_by === 'clusters' ? 'active' : ''; ?>" onclick="changeDrillBy('clusters')">Clusters</button>
                    <button class="drill-btn <?php echo $drill_by === 'products' ? 'active' : ''; ?>" onclick="changeDrillBy('products')">Products (Brands)</button>
                </div>
            </div>

            <div class="contributions-banner">
                <strong>Contributions sum:</strong> <?php echo number_format($total_growth != 0 ? 100 : 0, 2); ?>%
            </div>

            <div class="chart-container">
                <div class="chart-title">Top 10 Contributors</div>
                <div class="chart-wrapper">
                    <canvas id="c2gChart"></canvas>
                </div>
            </div>

            <div class="table-container">
                <div class="table-title">Detailed Breakdown — <?php echo $drill_title; ?></div>
                <table>
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th><?php echo $drill_title; ?></th>
                            <th>Contribution %</th>
                            <th>Growth (Units)</th>
                            <th>Recent Sales (TY)</th>
                            <th>YoY Growth %</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rank = 1;
                        foreach ($display_data as $item): 
                            $contribution_pct = $total_growth != 0 ? ($item['growth'] / $total_growth) * 100 : 0;
                            $unit_growth = $item['units_ty'] - $item['units_ly'];
                        ?>
                        <tr>
                            <td><strong><?php echo $rank; ?></strong></td>
                            <td><?php echo htmlspecialchars($item['segment']); ?></td>
                            <td>
                                <strong class="<?php echo $contribution_pct > 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($contribution_pct > 0 ? '+' : '') . number_format($contribution_pct, 2); ?>%
                                </strong>
                            </td>
                            <td>
                                <strong class="<?php echo $unit_growth > 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($unit_growth > 0 ? '+' : '') . number_format($unit_growth, 0); ?>
                                </strong>
                            </td>
                            <td>₱<?php echo number_format($item['sales_ty'], 2); ?></td>
                            <td>
                                <strong class="<?php echo $item['growth_pct'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($item['growth_pct'] > 0 ? '+' : '') . number_format($item['growth_pct'], 2); ?>%
                                </strong>
                            </td>
                        </tr>
                        <?php 
                            $rank++;
                        endforeach; 
                        ?>
                    </tbody>
                </table>
            </div>

            <div class="grid-2">
                <div class="chart-container">
                    <div class="chart-title">Growth Drivers Summary</div>
                    <table>
                        <tbody>
                            <tr>
                                <td><strong>Total Growth</strong></td>
                                <td style="text-align: right; color: #28a745; font-weight: 700;">
                                    ₱<?php echo number_format($total_growth, 2); ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Top Contributor</strong></td>
                                <td style="text-align: right; font-weight: 700;">
                                    <?php echo count($display_data) > 0 ? htmlspecialchars($display_data[0]['segment']) : 'N/A'; ?>
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Top Contributor Share</strong></td>
                                <td style="text-align: right; font-weight: 700;">
                                    <?php echo count($display_data) > 0 && $total_growth != 0 ? number_format(($display_data[0]['growth'] / $total_growth) * 100, 1) : 0; ?>%
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Number of Segments</strong></td>
                                <td style="text-align: right; font-weight: 700;">
                                    <?php echo count($display_data); ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="chart-container">
                    <div class="chart-title">Contribution Distribution</div>
                    <table>
                        <tbody>
                            <tr>
                                <td><strong>Positive Contributors</strong></td>
                                <td style="text-align: right; color: #28a745; font-weight: 700;">
                                    <?php echo count(array_filter($display_data, fn($x) => $x['growth'] > 0)); ?> segments
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Negative Contributors</strong></td>
                                <td style="text-align: right; color: #dc3545; font-weight: 700;">
                                    <?php echo count(array_filter($display_data, fn($x) => $x['growth'] < 0)); ?> segments
                                </td>
                            </tr>
                            <tr>
                                <td><strong>Concentration (Top 3)</strong></td>
                                <td style="text-align: right; font-weight: 700;">
                                    <?php 
                                    $top_3_growth = array_sum(array_slice(array_column($display_data, 'growth'), 0, 3));
                                    echo $total_growth != 0 ? number_format(($top_3_growth / $total_growth) * 100, 1) : 0; 
                                    ?>%
                                </td>
                            </tr>
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
            <a href="/auth/logout.php" class="confirm-btn">Yes, Logout</a>
            <button onclick="closeLogoutModal()" class="cancel-btn">Cancel</button>
        </div>
    </div>
</div>

<script>
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

function confirmResetDataset() {
    if (confirm('Reset your dataset? This will re-lock all analytics modules.')) {
        fetch('/dashboard/reset_dataset.php', { method: 'POST', credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => { if (d.success) window.location.href = '/dashboard/pipeline.php'; })
        .catch(() => alert('Network error. Please try again.'));
    }
}

function changeDrillBy(value) {
    window.location.href = window.location.pathname + '?drill_by=' + value;
}

// Chart.js - C2G Top 10 Contributors
const ctx = document.getElementById('c2gChart').getContext('2d');
const c2gData = <?php echo json_encode($top_10); ?>;

if (c2gData && c2gData.length > 0) {
    const segments = c2gData.map(d => d.segment);
    const contributions = c2gData.map(d => d.growth);
    const colors = contributions.map(c => c > 0 ? '#28a745' : '#dc3545');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: segments,
            datasets: [{
                label: 'Contribution to Growth (₱)',
                data: contributions,
                backgroundColor: colors,
                borderColor: colors,
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'bottom' }
            },
            scales: {
                x: {
                    ticks: {
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
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
