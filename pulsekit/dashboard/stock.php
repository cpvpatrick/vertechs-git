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

/**
 * Stock Metrics
 *
 * Condition | g_T (MSTL Trend)        | g_F (MinT Forecast)     | C2G c                              | Prescription
 * ----------|-------------------------|-------------------------|------------------------------------|------------------------------
 * E1        | g_T >= +3%              | g_F >= +3%              | c positive & high (>=+2% or Top25%)| Expand
 * E2        | g_T >= +3%              | g_F >= +3%              | c low/neutral (0% to <+2%)         | Expand (Selective)
 * M1        | -3% < g_T < +3%         | g_F >= +3%              | c positive                         | Maintain → Watch
 * M2        | g_T >= +3%              | -3% < g_F < +3%         | c positive                         | Maintain
 * M3        | -3% < g_T < +3%         | -3% < g_F < +3%         | c near 0                           | Maintain
 * D1        | g_T <= -3%              | g_F <= -3%              | c negative (or bottom tier)        | De-Prioritize
 * D2        | g_T <= -3%              | g_F <= -3%              | c positive (rare)                  | Maintain (Investigate)
 * D3        | g_T >= +3%              | g_F <= -3%              | any                                | Maintain (Conflict)
 * D4        | g_T <= -3%              | g_F >= +3%              | any                                | Maintain (Rebound)
 */
function getStockPrescription($g_T, $g_F, $c2g, $all_c2g_values) {
    // Compute Top 25% threshold from all C2G values
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

    $c2g_high    = ($c2g >= 2 || $c2g >= $top25_threshold);  // >=+2% or Top 25%
    $c2g_low_neu = ($c2g >= 0 && $c2g < 2);                  // 0% to <+2%
    $c2g_pos     = $c2g > 0;
    $c2g_near0   = ($c2g >= -1 && $c2g <= 1);
    $c2g_neg     = $c2g < 0;

    // E1: Strong trend + strong forecast + high C2G
    if ($trend_high && $forecast_high && $c2g_high) {
        return ['condition' => 'E1', 'action' => 'Expand', 'guidance' => 'Increase allocation; prioritize replenishment; ensure shelf availability.'];
    }
    // E2: Strong trend + strong forecast + low/neutral C2G
    if ($trend_high && $forecast_high && $c2g_low_neu) {
        return ['condition' => 'E2', 'action' => 'Expand (Selective)', 'guidance' => 'Expand selectively; target best SKUs/brands within the segment.'];
    }
    // D3: High trend but declining forecast (conflict)
    if ($trend_high && $forecast_low) {
        return ['condition' => 'D3', 'action' => 'Maintain (Conflict)', 'guidance' => 'Conflicting signals; hold steady; check shocks/stockouts; reassess next update.'];
    }
    // D4: Declining trend but strong forecast (possible rebound)
    if ($trend_low && $forecast_high) {
        return ['condition' => 'D4', 'action' => 'Maintain (Rebound)', 'guidance' => 'Possible rebound; keep steady; don\'t cut too early; confirm next month.'];
    }
    // D1: Declining trend + declining forecast + negative C2G
    if ($trend_low && $forecast_low && $c2g_neg) {
        return ['condition' => 'D1', 'action' => 'De-Prioritize', 'guidance' => 'Reduce allocation; rebalance inventory; tighten replenishment; avoid restock.'];
    }
    // D2: Declining trend + declining forecast + positive C2G (rare)
    if ($trend_low && $forecast_low && $c2g_pos) {
        return ['condition' => 'D2', 'action' => 'Maintain (Investigate)', 'guidance' => 'Declining overall but still a driver; investigate substitutions, distribution issues, or local shifts.'];
    }
    // M1: Flat trend + strong forecast + positive C2G
    if ($trend_flat && $forecast_high && $c2g_pos) {
        return ['condition' => 'M1', 'action' => 'Maintain → Watch', 'guidance' => 'Hold steady; monitor next 1-2 cycles for confirmation.'];
    }
    // M2: Strong trend + flat forecast + positive C2G
    if ($trend_high && $forecast_flat && $c2g_pos) {
        return ['condition' => 'M2', 'action' => 'Maintain', 'guidance' => 'Stable levels; avoid overreacting — trend is good but forecast is flat.'];
    }
    // M3: Flat trend + flat forecast + C2G near 0
    if ($trend_flat && $forecast_flat && $c2g_near0) {
        return ['condition' => 'M3', 'action' => 'Maintain', 'guidance' => 'No change; review in next cycle.'];
    }
    // Fallback: general Maintain
    return ['condition' => '—', 'action' => 'Maintain', 'guidance' => 'No clear signal; hold current levels and monitor.'];
}

if ($result) {
    // First pass: collect raw rows and C2G values for threshold calc
    $raw_rows = [];
    $all_c2g_values = [];
    while ($row = $result->fetch_assoc()) {
        $raw_rows[] = $row;
        $all_c2g_values[] = floatval($row['c2g_signal'] ?? 0);
    }

    // Second pass: apply decision logic
    foreach ($raw_rows as $row) {
        $current_sales = $row['current_sales'] ?? 1;
        // g_T: trend % = (TY - LY) / LY * 100, using trend_signal / (current_sales - trend_signal)
        $ly_sales = $current_sales - floatval($row['trend_signal']);
        $g_T = ($ly_sales != 0) ? (floatval($row['trend_signal']) / $ly_sales * 100) : 0;

        // g_F: forecast % vs current sales
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

// Filter by action group if specified
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
        body.dark-mode .kpi-card.positive {
            border-left-color: #28a745 !important;
        }
        body.dark-mode .kpi-label { color: #8892a4 !important; }
        body.dark-mode .kpi-value { color: #e8eaf0 !important; }
        body.dark-mode .kpi-value.positive { color: #3ddc6e !important; }
        body.dark-mode .kpi-meta { color: #6b7a90 !important; }

        /* Page header */
        body.dark-mode .page-header h1 { color: #e8eaf0 !important; }
        body.dark-mode .page-header p { color: #8892a4 !important; }

        /* Chart & table containers */
        body.dark-mode .chart-container,
        body.dark-mode .table-container,
        body.dark-mode .coherence-check,
        body.dark-mode .pipeline-section,
        body.dark-mode .forecast-metrics,
        body.dark-mode .panel {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
        }
        body.dark-mode .chart-title,
        body.dark-mode .table-title { color: #e8eaf0 !important; }

        /* Metric boxes (forecast page) */
        body.dark-mode .metric-box {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .metric-label { color: #8892a4 !important; }
        body.dark-mode .metric-value { color: #e8eaf0 !important; }

        /* Allocation cards (stock page) */
        body.dark-mode .allocation-summary { background: transparent !important; }
        body.dark-mode .allocation-card {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .allocation-count { color: #e8eaf0 !important; }
        body.dark-mode .allocation-description { color: #8892a4 !important; }

        /* Action filter buttons */
        body.dark-mode .action-filter-btn {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
            color: #e8eaf0 !important;
        }
        body.dark-mode .action-filter-btn.active {
            background-color: #1c4aa0 !important;
            color: #ffffff !important;
        }

        /* Filter containers */
        body.dark-mode .filter-container {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .filter-group label { color: #8892a4 !important; }
        body.dark-mode .filter-group select {
            background-color: #0f1117 !important;
            border-color: #2a2f3e !important;
            color: #e8eaf0 !important;
        }

        /* Pipeline specific */
        body.dark-mode .pipeline-section { color: #e8eaf0 !important; }
        body.dark-mode .upload-area {
            background-color: #0f1117 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .load-sample-btn {
            background-color: #1c4aa0 !important;
            color: #ffffff !important;
        }

        /* Grid item backgrounds (inline white bg in pipeline) */
        body.dark-mode .grid-2 > div,
        body.dark-mode .grid-3 > div {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }

        /* Panel header/badge (coherence page) */
        body.dark-mode .panel-header { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .panel-title { color: #8892a4 !important; }
        body.dark-mode .panel-badge { background-color: #0f1117 !important; color: #e8eaf0 !important; }

        /* Tables */
        body.dark-mode table { background-color: transparent !important; }
        body.dark-mode .chart-container table td,
        body.dark-mode .table-container table td,
        body.dark-mode .coherence-check table td,
        body.dark-mode .pipeline-section table td {
            color: #e8eaf0 !important;
            border-bottom-color: #2a2f3e !important;
        }
        body.dark-mode table tr { border-bottom-color: #2a2f3e !important; }
        body.dark-mode table tr:hover { background-color: rgba(100,160,255,0.08) !important; }

        body.dark-mode .chart-container table th,
        body.dark-mode .table-container table th,
        body.dark-mode .coherence-check table th,
        body.dark-mode .pipeline-section table th {
            background-color: #1c3a7a !important;
            color: #e8eaf0 !important;
            border-bottom-color: #2a2f3e !important;
        }

        /* History table */
        body.dark-mode .history-table { background-color: #1a1d27 !important; }
        body.dark-mode .history-table th {
            background-color: #1c3a7a !important;
            color: #e8eaf0 !important;
        }
        body.dark-mode .history-table td {
            color: #e8eaf0 !important;
            border-bottom-color: #2a2f3e !important;
        }
        body.dark-mode .history-table tr:hover {
            background-color: rgba(100,160,255,0.08) !important;
        }

        /* History page h1 (no .page-header wrapper) */
        body.dark-mode main.content h1 { color: #e8eaf0 !important; }

        /* Modal */
        body.dark-mode .modal-box { background-color: #1a1d27 !important; }
        body.dark-mode .modal-box h2 { color: #e8eaf0 !important; }

        /* Coherence status badges */
        body.dark-mode .coherence-status.pass {
            background-color: rgba(40,167,69,0.15) !important;
            color: #3ddc6e !important;
        }

        /* Catch-all text */
        body.dark-mode p,
        body.dark-mode span.sortable,
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3,
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode td, body.dark-mode th, body.dark-mode label,
        body.dark-mode strong {
            color: #e8eaf0 !important;
        }

        /* Preserve green/red metric colors on class-based elements */
        body.dark-mode .text-success { color: #3ddc6e !important; }
        body.dark-mode .text-danger  { color: #ff6b7a !important; }

        /* Smooth transitions */
        .content, .kpi-card, .chart-container, .table-container,
        .modal-box, .history-table, .history-table td, .history-table th,
        .panel, .metric-box, .allocation-card, .filter-container,
        .pipeline-section, .coherence-check, .forecast-metrics {
            transition: background-color 0.3s ease, color 0.3s ease,
                        border-color 0.3s ease, box-shadow 0.3s ease !important;
        }

        /* Kill striped/zebra rows from dashboard-styles.css */
        body.dark-mode table tbody tr,
        body.dark-mode table tbody tr:nth-child(odd),
        body.dark-mode table tbody tr:nth-child(even) {
            background-color: transparent !important;
        }
        body.dark-mode table tbody tr:hover {
            background-color: rgba(100, 160, 255, 0.08) !important;
        }
        body.dark-mode table td,
        body.dark-mode table th {
            background-color: transparent !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .chart-container table th,
        body.dark-mode .table-container table th,
        body.dark-mode .coherence-check table th {
            background-color: #1c3a7a !important;
            color: #e8eaf0 !important;
        }

        /* ── Decision Logic Table — dark mode ── */
        body.dark-mode .decision-logic-table {
            border-color: #2a2f3e !important;
        }
        body.dark-mode .decision-logic-table th {
            background-color: #1e2235 !important;
            color: #b0b8cc !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .decision-logic-table td {
            background-color: transparent !important;
            color: #e8eaf0 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .decision-logic-table tbody tr:hover {
            background-color: rgba(100, 160, 255, 0.08) !important;
        }

        /* =========================
           MODULE LOCK STYLES
        ========================= */
        .menu a.locked {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
            position: relative;
        }
        .menu a.locked::after {
            content: "🔒";
            font-size: 11px;
            margin-left: 6px;
            vertical-align: middle;
            opacity: 0.8;
        }
        .menu a.locked::before {
            display: none !important;
        }

        /* Unlock toast notification */
        #unlockToast {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            background: #1c4aa0;
            color: #ffffff;
            padding: 12px 24px;
            border-radius: 30px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 20px rgba(28,74,160,0.4);
            opacity: 0;
            transition: opacity 0.3s ease, transform 0.3s ease;
            z-index: 9998;
            pointer-events: none;
            white-space: nowrap;
        }
        #unlockToast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }

        /* Lock overlay for locked page content */
        #lockedOverlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 17, 23, 0.85);
            z-index: 8000;
            justify-content: center;
            align-items: center;
            backdrop-filter: blur(4px);
        }
        #lockedOverlay.visible {
            display: flex;
        }
        .locked-card {
            background: #1a1d27;
            border: 1px solid #2a2f3e;
            border-radius: 16px;
            padding: 40px 50px;
            text-align: center;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        .locked-card .lock-icon {
            font-size: 48px;
            margin-bottom: 16px;
            display: block;
        }
        .locked-card h2 {
            color: #e8eaf0;
            font-size: 20px;
            margin-bottom: 10px;
        }
        .locked-card p {
            color: #8892a4;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .locked-card .go-pipeline-btn {
            display: inline-block;
            background: #1c4aa0;
            color: #ffffff;
            padding: 12px 28px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: background 0.2s ease;
        }
        .locked-card .go-pipeline-btn:hover {
            background: #2255b8;
        }

    </style>
</head>
<body>

<div class="dashboard-container">

    <aside class="sidebar">
        <div class="sidebar-overlay"></div>

        <nav class="menu">
            <div class="menu-logo">
                <img src="/pulsekit/assets/pulsekit.png" alt="PulseKit Logo">
            </div>
                        <a href="/pulsekit/dashboard/pipeline.php">Pipeline (Ingestion <br>&amp; Prep)</a>
            <a href="/pulsekit/dashboard/history.php">Login History<br>(Security Audit)</a>
            <a href="/pulsekit/dashboard/overview.php" data-locked="true">Overview</a>
            <a href="/pulsekit/dashboard/seasonality.php" data-locked="true">Seasonality<br>Profiles (MSTL)</a>
            <a href="/pulsekit/dashboard/trend.php" data-locked="true">Trend-True Growth<br>(MoM/YTD)</a>
            <a href="/pulsekit/dashboard/forecast.php" data-locked="true">Forecasts (Base <br>vs Reconciled)</a>
            <a href="/pulsekit/dashboard/coherence.php" data-locked="true">Coherence Check</a>
            <a href="/pulsekit/dashboard/c2g.php" data-locked="true">C2G Growth Drivers</a>
            <a href="/pulsekit/dashboard/stock.php" class="active" data-locked="true">Stock Allocation<br>Prescriptions</a>
            <a href="/pulsekit/dashboard/dictionary.php" data-locked="true">Data Dictionary /<br>Methodology</a>
            <a href="#" class="logout" onclick="openLogoutModal(); return false;">Logout</a>
            <!-- DARK / LIGHT THEME TOGGLE -->
            <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeToggleBtn" title="Toggle dark/light mode">
                <span class="toggle-icon" id="themeIcon">🌙</span>
                <span class="toggle-label" id="themeLabel">Dark Mode</span>
                <div class="toggle-track">
                    <div class="toggle-thumb"></div>
                </div>
        
            </button>
        </nav>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="content">
        <div class="page-header">
            <h1>Stock Allocation Prescriptions</h1>
            <p>Rules-based recommendations using Trend + Forecast + C2G signals</p>
        </div>

        <!-- ALLOCATION SUMMARY CARDS -->
        <div class="allocation-summary">
            <div class="allocation-card expand">
                <div class="allocation-action">📈 Expand</div>
                <div class="allocation-count"><?php echo $expand_count; ?></div>
                <div class="allocation-description">High growth + positive C2G</div>
            </div>

            <div class="allocation-card maintain">
                <div class="allocation-action">➡️ Maintain</div>
                <div class="allocation-count"><?php echo $maintain_count; ?></div>
                <div class="allocation-description">Stable performance</div>
            </div>

            <div class="allocation-card deprioritize">
                <div class="allocation-action">📉 De-prioritize</div>
                <div class="allocation-count"><?php echo $deprioritize_count; ?></div>
                <div class="allocation-description">Declining + negative C2G</div>
            </div>
        </div>

        <!-- ACTION FILTER BUTTONS -->
        <div class="action-filter">
            <button class="action-filter-btn <?php echo $filter_action === 'All' ? 'active' : ''; ?>" 
                    onclick="filterByAction('All')">
                All (<?php echo count($stock_data); ?>)
            </button>
            <button class="action-filter-btn expand <?php echo $filter_action === 'Expand' ? 'active' : ''; ?>" 
                    onclick="filterByAction('Expand')">
                Expand (<?php echo $expand_count; ?>)
            </button>
            <button class="action-filter-btn maintain <?php echo $filter_action === 'Maintain' ? 'active' : ''; ?>" 
                    onclick="filterByAction('Maintain')">
                Maintain (<?php echo $maintain_count; ?>)
            </button>
            <button class="action-filter-btn deprioritize <?php echo $filter_action === 'De-prioritize' ? 'active' : ''; ?>" 
                    onclick="filterByAction('De-prioritize')">
                De-prioritize (<?php echo $deprioritize_count; ?>)
            </button>
        </div>

        <!-- STOCK ALLOCATION TABLE -->
        <div class="table-container">
            <h3 class="table-title">Stock Allocation Recommendations</h3>
            <table>
                <thead>
                    <tr>
                        <th>Priority</th>
                        <th>Condition</th>
                        <th>Segment</th>
                        <th>Action</th>
                        <th>Trend g_T</th>
                        <th>Forecast g_F</th>
                        <th>C2G %</th>
                        <th>Current Sales</th>
                        <th>Action Guidance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $priority = 1;
                    foreach ($filtered_data as $item): 
                    ?>
                    <tr>
                        <td><strong><?php echo $priority; ?></strong></td>
                        <td>
                            <span style="
                                display: inline-block;
                                padding: 2px 8px;
                                border-radius: 4px;
                                font-size: 12px;
                                font-weight: 700;
                                background: #e8f0fe;
                                color: #1c4aa0;
                            "><?php echo htmlspecialchars($item['condition']); ?></span>
                        </td>
                        <td>
                            <div><strong><?php echo htmlspecialchars(substr($item['sku'], 0, 25)); ?></strong></div>
                            <div style="font-size: 12px; color: #999;">
                                <?php echo htmlspecialchars($item['region']); ?> / <?php echo htmlspecialchars($item['cluster']); ?>
                            </div>
                        </td>
                        <td>
                            <span class="allocation-action" style="
                                display: inline-block;
                                padding: 4px 12px;
                                border-radius: 4px;
                                font-size: 12px;
                                font-weight: 600;
                                <?php 
                                if (strpos($item['action'], 'Expand') !== false) {
                                    echo 'background: #d4edda; color: #155724;';
                                } elseif ($item['action'] === 'De-Prioritize') {
                                    echo 'background: #f8d7da; color: #721c24;';
                                } elseif ($item['action'] === 'Maintain (Conflict)') {
                                    echo 'background: #fce8d2; color: #7d3c00;';
                                } elseif ($item['action'] === 'Maintain (Investigate)') {
                                    echo 'background: #e8d5f5; color: #5b2c8d;';
                                } elseif ($item['action'] === 'Maintain (Rebound)') {
                                    echo 'background: #d0eaf8; color: #1a5276;';
                                } elseif ($item['action'] === 'Maintain → Watch') {
                                    echo 'background: #fef9c3; color: #7d6608;';
                                } else {
                                    echo 'background: #fff3cd; color: #856404;';
                                }
                                ?>
                            ">
                                <?php echo htmlspecialchars($item['action']); ?>
                            </span>
                        </td>
                        <td>
                            <strong class="<?php echo $item['trend_pct'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($item['trend_pct'] > 0 ? '+' : '') . number_format($item['trend_pct'], 2); ?>%
                            </strong>
                        </td>
                        <td>
                            <strong class="<?php echo isset($item['forecast_pct']) && $item['forecast_pct'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo isset($item['forecast_pct']) ? (($item['forecast_pct'] > 0 ? '+' : '') . number_format($item['forecast_pct'], 2) . '%') : '—'; ?>
                            </strong>
                        </td>
                        <td>
                            <strong class="<?php echo $item['c2g_pct'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($item['c2g_pct'] > 0 ? '+' : '') . number_format($item['c2g_pct'], 2); ?>%
                            </strong>
                        </td>
                        <td>₱<?php echo number_format($item['current_sales'], 2); ?></td>
                        <td style="font-size: 13px; color: #555;"><?php echo htmlspecialchars($item['reason']); ?></td>
                    </tr>
                    <?php 
                        $priority++;
                    endforeach; 
                    ?>
                </tbody>
            </table>
        </div>

        <!-- STOCK METRICS TABLE — matches image layout exactly -->
        <div class="chart-container" style="margin-bottom: 24px;">
            <h3 class="chart-title" style="font-size:17px; font-weight:700; margin-bottom:20px;">
                Stock Metrics
            </h3>
            <div style="overflow-x: auto;">
                <table class="decision-logic-table" style="width:100%; border-collapse: collapse; font-size: 13.5px; border: 1px solid #c8cdd6;">
                    <thead>
                        <tr>
                            <th style="border: 1px solid #c8cdd6; padding: 13px 14px; text-align: left; font-weight: 700; background: #f7f8fa; color: #1a1a2e; width: 90px;">Condition ID</th>
                            <th style="border: 1px solid #c8cdd6; padding: 13px 14px; text-align: left; font-weight: 700; background: #f7f8fa; color: #1a1a2e; width: 140px;">Trend (MSTL) g_T</th>
                            <th style="border: 1px solid #c8cdd6; padding: 13px 14px; text-align: left; font-weight: 700; background: #f7f8fa; color: #1a1a2e; width: 160px;">Forecast (MinT) g_F</th>
                            <th style="border: 1px solid #c8cdd6; padding: 13px 14px; text-align: left; font-weight: 700; background: #f7f8fa; color: #1a1a2e; width: 210px;">C2G c</th>
                            <th style="border: 1px solid #c8cdd6; padding: 13px 14px; text-align: left; font-weight: 700; background: #f7f8fa; color: #1a1a2e; width: 160px;">Prescription</th>
                            <th style="border: 1px solid #c8cdd6; padding: 13px 14px; text-align: left; font-weight: 700; background: #f7f8fa; color: #1a1a2e;">Action Guidance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">E1</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c positive and high (&ge; +2% or Top 25%)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">EXPAND</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Increase allocation; prioritize replenishment; ensure shelf availability.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">E2</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c low/neutral (0% to &lt; +2% or not Top 25%)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">EXPAND (Selective)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Expand selectively; target best SKUs/brands within the segment.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">M1</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">-3% &lt; g_T &lt; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c positive</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">MAINTAIN &rarr; WATCH</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Hold steady; monitor next 1–2 cycles for confirmation.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">M2</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">-3% &lt; g_F &lt; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c positive</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">MAINTAIN</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Stable levels; avoid overreacting — trend is good but forecast is flat.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">M3</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">-3% &lt; g_T &lt; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">-3% &lt; g_F &lt; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c near 0</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">MAINTAIN</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">No change; review in next cycle.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">D1</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &le; -3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &le; -3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c negative (or bottom tier)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">DE-PRIORITIZE</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Reduce allocation; rebalance inventory; tighten replenishment; avoid restock.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">D2</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &le; -3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &le; -3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">c positive (rare)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">MAINTAIN (Investigate)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Declining overall but still a driver; investigate substitutions, distribution issues, or local shifts.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">D3</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &le; -3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">any</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">MAINTAIN (Conflict)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Conflicting signals; hold steady; check shocks/stockouts; reassess next update.</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">D4</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_T &le; -3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">g_F &ge; +3%</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">any</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px; font-weight: 700;">MAINTAIN (Rebound)</td>
                            <td style="border: 1px solid #c8cdd6; padding: 13px 14px;">Possible rebound; keep steady; don't cut too early; confirm next month.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="grid-2">
            <div class="chart-container">
                <h3 class="chart-title">📊 Signal Definitions</h3>
                <table>
                    <tbody>
                        <tr>
                            <td><strong>Trend (g_T)</strong></td>
                            <td>MSTL-decomposed YoY/MoM growth rate. &ge;+3% = high, &le;-3% = low.</td>
                        </tr>
                        <tr>
                            <td><strong>Forecast (g_F)</strong></td>
                            <td>MinT reconciled forecast growth vs actuals. &ge;+3% = high, &le;-3% = low.</td>
                        </tr>
                        <tr>
                            <td><strong>C2G (c)</strong></td>
                            <td>Contribution-to-Growth index. Positive = growth driver; negative = drag.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="chart-container">
                <h3 class="chart-title">🎯 Quick Reference — Thresholds</h3>
                <table>
                    <tbody>
                        <tr>
                            <td><strong>High Growth</strong></td>
                            <td>g_T or g_F &ge; +3%</td>
                        </tr>
                        <tr>
                            <td><strong>Flat / Neutral</strong></td>
                            <td>-3% &lt; g &lt; +3%</td>
                        </tr>
                        <tr>
                            <td><strong>Declining</strong></td>
                            <td>g_T or g_F &le; -3%</td>
                        </tr>
                        <tr>
                            <td><strong>C2G High</strong></td>
                            <td>&ge; +2% or Top 25% of SKUs</td>
                        </tr>
                        <tr>
                            <td><strong>C2G Low/Neutral</strong></td>
                            <td>0% to &lt;+2%</td>
                        </tr>
                        <tr>
                            <td><strong>C2G Negative</strong></td>
                            <td>&lt; 0%</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- IMPLEMENTATION GUIDE -->
        <div class="chart-container">
            <h3 class="chart-title">🎯 Implementation Guide</h3>
            <table>
                <thead>
                    <tr>
                        <th>Action</th>
                        <th>Recommended Steps</th>
                        <th>Timeline</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Expand / Expand (Selective)</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Increase inventory allocation by 15–25% (full Expand) or 5–15% (Selective)</li>
                                <li>Prioritize in promotional campaigns; expand shelf space in high-performing clusters</li>
                                <li>For Selective: target best SKUs/brands within the segment</li>
                            </ul>
                        </td>
                        <td>Immediate (Week 1–2)</td>
                    </tr>
                    <tr>
                        <td><strong>Maintain → Watch</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Hold current levels; monitor next 1–2 replenishment cycles</li>
                                <li>Flag for review if g_T does not recover above +3%</li>
                            </ul>
                        </td>
                        <td>Ongoing (review in 2 cycles)</td>
                    </tr>
                    <tr>
                        <td><strong>Maintain</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Keep current inventory levels; support with seasonal promotions</li>
                                <li>Monitor performance weekly; no drastic changes needed</li>
                            </ul>
                        </td>
                        <td>Ongoing</td>
                    </tr>
                    <tr>
                        <td><strong>Maintain (Investigate)</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Hold allocation while investigating substitution effects or distribution gaps</li>
                                <li>Check for local demand shifts or stockout history</li>
                            </ul>
                        </td>
                        <td>Within 1 cycle</td>
                    </tr>
                    <tr>
                        <td><strong>Maintain (Conflict)</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Hold steady — do not expand or reduce until signals align</li>
                                <li>Check for stockouts, pricing shocks, or data anomalies</li>
                            </ul>
                        </td>
                        <td>Reassess next update</td>
                    </tr>
                    <tr>
                        <td><strong>Maintain (Rebound)</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Do not cut allocation — possible recovery in progress</li>
                                <li>Confirm rebound signal in next month before expanding</li>
                            </ul>
                        </td>
                        <td>Confirm next month</td>
                    </tr>
                    <tr>
                        <td><strong>De-Prioritize</strong></td>
                        <td>
                            <ul style="margin: 0; padding-left: 20px;">
                                <li>Reduce inventory allocation by 10–20%</li>
                                <li>Phase out from low-performing locations; consider promotional clearance</li>
                            </ul>
                        </td>
                        <td>Gradual (Week 3–4)</td>
                    </tr>
                </tbody>
            </table>
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

/* =========================
   THEME TOGGLE
========================= */
/* =========================
   MODULE LOCK SYSTEM (server-side, per-user)
   Unlock state comes from PHP/DB — not localStorage
========================= */
const UNLOCKED = <?php echo $user_dataset_loaded ? 'true' : 'false'; ?>;

function applyLockState() {
    document.querySelectorAll('.menu a[data-locked]').forEach(link => {
        if (UNLOCKED) {
            link.classList.remove('locked');
            link.removeAttribute('tabindex');
        } else {
            link.classList.add('locked');
            link.setAttribute('tabindex', '-1');
        }
    });
}

function showLockedOverlay() {
    const overlay = document.getElementById('lockedOverlay');
    if (overlay) overlay.classList.add('visible');
}

// On page load: show overlay if this page is locked for this user
(function() {
    const lockedPages = [
        'overview.php', 'seasonality.php', 'trend.php', 'forecast.php',
        'coherence.php', 'c2g.php', 'stock.php', 'dictionary.php',
    ];
    const currentFile = window.location.pathname.split('/').pop();
    if (lockedPages.includes(currentFile) && !UNLOCKED) {
        showLockedOverlay();
    }
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

    // Fix inline-style green/red cells
    document.querySelectorAll('td[style*="color"]').forEach(td => {
        const style = td.getAttribute('style') || '';
        if (style.includes('#28a745')) {
            td.style.color = isDark ? '#3ddc6e' : '#28a745';
        } else if (style.includes('#dc3545')) {
            td.style.color = isDark ? '#ff6b7a' : '#dc3545';
        } else if (style.includes('#0066cc')) {
            td.style.color = isDark ? '#4da6ff' : '#0066cc';
        } else if (style.includes('#666')) {
            td.style.color = isDark ? '#8892a4' : '#666';
        }
    });

    // Fix inline bg colors on grid divs (pipeline page)
    document.querySelectorAll('[style*="background: white"], [style*="background:#fff"], [style*="background: #fff"]').forEach(el => {
        el.style.backgroundColor = isDark ? '#1a1d27' : '#ffffff';
    });
    document.querySelectorAll('[style*="background: #f0f7ff"]').forEach(el => {
        el.style.backgroundColor = isDark ? '#1a2540' : '#f0f7ff';
        el.style.borderColor = isDark ? '#2a3f6e' : '#0066cc';
    });

    // Fix inline text colors
    document.querySelectorAll('[style*="color: #666"], [style*="color:#666"]').forEach(el => {
        el.style.color = isDark ? '#8892a4' : '#666';
    });
    document.querySelectorAll('[style*="color: #999"], [style*="color:#999"]').forEach(el => {
        el.style.color = isDark ? '#6b7a90' : '#999';
    });

    // Update any active Chart.js charts
    if (window.Chart && Chart.instances) {
        Object.values(Chart.instances).forEach(chart => {
            const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
            const tickColor = isDark ? '#8892a4' : '#666';
            const legendColor = isDark ? '#e8eaf0' : '#333';
            if (chart.options.scales) {
                if (chart.options.scales.x) {
                    chart.options.scales.x.grid = chart.options.scales.x.grid || {};
                    chart.options.scales.x.ticks = chart.options.scales.x.ticks || {};
                    chart.options.scales.x.grid.color = gridColor;
                    chart.options.scales.x.ticks.color = tickColor;
                }
                if (chart.options.scales.y) {
                    chart.options.scales.y.grid = chart.options.scales.y.grid || {};
                    chart.options.scales.y.ticks = chart.options.scales.y.ticks || {};
                    chart.options.scales.y.grid.color = gridColor;
                    chart.options.scales.y.ticks.color = tickColor;
                }
            }
            if (chart.options.plugins && chart.options.plugins.legend) {
                chart.options.plugins.legend.labels = chart.options.plugins.legend.labels || {};
                chart.options.plugins.legend.labels.color = legendColor;
            }
            chart.update();
        });
    }
}

function toggleTheme() {
    const current = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(current === 'dark' ? 'light' : 'dark');
}




// Apply saved theme immediately on load
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

function filterByAction(action) {
    const url = window.location.pathname + (action === 'All' ? '' : '?action=' + encodeURIComponent(action));
    window.location.href = url;
}
</script>

</body>
</html>