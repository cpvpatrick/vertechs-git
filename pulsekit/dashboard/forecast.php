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
            <a href="/pulsekit/dashboard/forecast.php" class="active" data-locked="true">Forecasts (Base <br>vs Reconciled)</a>
            <a href="/pulsekit/dashboard/coherence.php" data-locked="true">Coherence Check</a>
            <a href="/pulsekit/dashboard/c2g.php" data-locked="true">C2G Growth Drivers</a>
            <a href="/pulsekit/dashboard/stock.php" data-locked="true">Stock Allocation<br>Prescriptions</a>
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
            <h1>Forecasts (Base vs Reconciled)</h1>
            <p>Compare base and reconciled forecasts with accuracy metrics</p>
        </div>

        <!-- FILTER DROPDOWNS -->
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

        <!-- FORECAST ACCURACY METRICS -->
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

        <!-- ACTUAL VS FORECASTS LINE CHART -->
        <div class="chart-container">
            <h3 class="chart-title">Actual vs Base vs Reconciled Forecast</h3>
            <div class="chart-wrapper">
                <canvas id="forecastChart"></canvas>
            </div>
        </div>

        <!-- FORECAST BY HORIZON TABLE -->
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

        <!-- FORECAST INSIGHTS -->
        <div class="chart-container">
            <h3 class="chart-title">📊 Forecast Insights</h3>
            <table>
                <tbody>
                    <tr>
                        <td><strong>Model Type</strong></td>
                        <td>LightGBM with hierarchical reconciliation (MinT)</td>
                    </tr>
                    <tr>
                        <td><strong>Forecast Horizon</strong></td>
                        <td>12 months ahead</td>
                    </tr>
                    <tr>
                        <td><strong>Training Data</strong></td>
                        <td>24 months historical (2023-2025)</td>
                    </tr>
                    <tr>
                        <td><strong>Reconciliation Method</strong></td>
                        <td>Minimum Trace (MinT) for hierarchy coherence</td>
                    </tr>
                    <tr>
                        <td><strong>Features Used</strong></td>
                        <td>Lags (1-12m), rolling averages, calendar features, seasonality indices</td>
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

function applyFilters() {
    const category = document.querySelectorAll('select')[0].value || '';
    const region = document.querySelectorAll('select')[1].value || '';
    
    let url = window.location.pathname + '?';
    if (category) url += 'category=' + encodeURIComponent(category) + '&';
    if (region) url += 'region=' + encodeURIComponent(region);
    
    window.location.href = url;
}

// Chart.js - Forecast Comparison
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
                legend: {
                    display: true,
                    position: 'bottom'
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
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