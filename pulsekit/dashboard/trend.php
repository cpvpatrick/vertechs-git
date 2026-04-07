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
$filter_region = $_GET['region'] ?? 'All';
$filter_cluster = $_GET['cluster'] ?? 'All';
$filter_category = $_GET['category'] ?? 'All';
$filter_brand = $_GET['brand'] ?? 'All';
$filter_sku = $_GET['sku'] ?? 'All';

/* BUILD TREND DATA WITH GROWTH METRICS */
$query = "
    SELECT 
        ds.nestle_region as region,
        ds.nestle_store_cluster as cluster,
        dp.product_description as sku,
        SUBSTRING(dp.product_description, 1, 10) as brand,
        SUBSTRING(dp.product_description, 1, 5) as category,
        SUM(fs.net_sales_ty_exvat) as sales_ty,
        SUM(fs.net_sales_ly_exvat) as sales_ly,
        SUM(fs.units_sold_ty) as units_ty,
        SUM(fs.units_sold_ly) as units_ly
    FROM fact_sales fs
    JOIN dim_store ds ON fs.store_id = ds.store_id
    JOIN dim_product dp ON fs.product_id = dp.product_id
    WHERE 1=1
";

if ($filter_region !== 'All') {
    $query .= " AND ds.nestle_region = '" . $conn->real_escape_string($filter_region) . "'";
}
if ($filter_cluster !== 'All') {
    $query .= " AND ds.nestle_store_cluster = '" . $conn->real_escape_string($filter_cluster) . "'";
}

$query .= " GROUP BY ds.nestle_region, ds.nestle_store_cluster, dp.product_description
            ORDER BY sales_ty DESC";

$result = $conn->query($query);

$trend_data = [];
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sales_ly = $row['sales_ly'] ?? 1;
        $units_ly = $row['units_ly'] ?? 1;
        
        $mom_growth = $sales_ly != 0 ? (($row['sales_ty'] - $sales_ly) / $sales_ly) * 100 : 0;
        $ytd_growth = $units_ly != 0 ? (($row['units_ty'] - $units_ly) / $units_ly) * 100 : 0;
        
        $trend_data[] = [
            'region' => $row['region'],
            'cluster' => $row['cluster'],
            'category' => $row['category'],
            'brand' => $row['brand'],
            'sku' => $row['sku'],
            'sales_ty' => $row['sales_ty'],
            'sales_ly' => $row['sales_ly'],
            'units_ty' => $row['units_ty'],
            'units_ly' => $row['units_ly'],
            'mom_growth' => $mom_growth,
            'ytd_growth' => $ytd_growth
        ];
    }
}

/* GET UNIQUE VALUES FOR FILTERS */
$regions = [];
$clusters = [];
$categories = [];
$brands = [];
$skus = [];

$result = $conn->query("SELECT DISTINCT nestle_region FROM dim_store WHERE nestle_region IS NOT NULL ORDER BY nestle_region");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $regions[] = $row['nestle_region'];
    }
}

$result = $conn->query("SELECT DISTINCT nestle_store_cluster FROM dim_store WHERE nestle_store_cluster IS NOT NULL ORDER BY nestle_store_cluster");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $clusters[] = $row['nestle_store_cluster'];
    }
}

$result = $conn->query("SELECT DISTINCT product_description FROM dim_product ORDER BY product_description");
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $skus[] = $row['product_description'];
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
            <a href="/pulsekit/dashboard/trend.php" class="active" data-locked="true">Trend-True Growth<br>(MoM/YTD)</a>
            <a href="/pulsekit/dashboard/forecast.php" data-locked="true">Forecasts (Base <br>vs Reconciled)</a>
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
            <h1>Trend-True Growth (MoM / YTD)</h1>
            <p>Growth metrics can be viewed as a table and sorted by region, cluster, category, brand, and SKU</p>
        </div>

        <!-- FILTER CONTROLS -->
        <div class="filter-container">
            <div class="filter-group">
                <label>Region</label>
                <select onchange="applyFilters()">
                    <option value="">All Regions</option>
                    <?php foreach ($regions as $r): ?>
                    <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $filter_region === $r ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($r); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Cluster</label>
                <select onchange="applyFilters()">
                    <option value="">All Clusters</option>
                    <?php foreach ($clusters as $c): ?>
                    <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_cluster === $c ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>SKU</label>
                <select onchange="applyFilters()">
                    <option value="">All SKUs</option>
                    <?php foreach ($skus as $s): ?>
                    <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $filter_sku === $s ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars(substr($s, 0, 30)); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- TREND DATA TABLE -->
        <div class="table-container">
            <h3 class="table-title">Trend-True Growth Metrics</h3>
            <table>
                <thead>
                    <tr>
                        <th class="sortable" onclick="sortTableByColumn(0)">Region</th>
                        <th class="sortable" onclick="sortTableByColumn(1)">Cluster</th>
                        <th class="sortable" onclick="sortTableByColumn(2)">Category</th>
                        <th class="sortable" onclick="sortTableByColumn(3)">Brand</th>
                        <th class="sortable" onclick="sortTableByColumn(4)">SKU</th>
                        <th class="sortable" onclick="sortTableByColumn(5)">Sales TY</th>
                        <th class="sortable" onclick="sortTableByColumn(6)">Sales LY</th>
                        <th class="sortable" onclick="sortTableByColumn(7)">MoM Growth %</th>
                        <th class="sortable" onclick="sortTableByColumn(8)">YTD Growth %</th>
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
                        <td>
                            <strong class="<?php echo $row['mom_growth'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($row['mom_growth'] > 0 ? '+' : '') . number_format($row['mom_growth'], 2); ?>%
                            </strong>
                        </td>
                        <td>
                            <strong class="<?php echo $row['ytd_growth'] > 0 ? 'text-success' : 'text-danger'; ?>">
                                <?php echo ($row['ytd_growth'] > 0 ? '+' : '') . number_format($row['ytd_growth'], 2); ?>%
                            </strong>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- SUMMARY STATISTICS -->
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
                            return $b['mom_growth'] <=> $a['mom_growth']; // descending
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
const UNLOCKED = <?php echo $user_pipeline_executed ? 'true' : 'false'; ?>;

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
    const region = document.querySelector('select').value || 'All';
    const cluster = document.querySelectorAll('select')[1].value || 'All';
    const sku = document.querySelectorAll('select')[2].value || 'All';
    
    let url = window.location.pathname + '?';
    if (region) url += 'region=' + encodeURIComponent(region) + '&';
    if (cluster) url += 'cluster=' + encodeURIComponent(cluster) + '&';
    if (sku) url += 'sku=' + encodeURIComponent(sku);
    
    window.location.href = url;
}

function sortTableByColumn(columnIndex) {
    alert("Table sorting by column " + columnIndex + " can be implemented with JavaScript");
}

// Helper function for array sorting
function array_sort(arr, key, desc = false) {
    return arr.sort((a, b) => {
        const aVal = a[key];
        const bVal = b[key];
        if (desc) {
            return bVal - aVal;
        } else {
            return aVal - bVal;
        }
    });
}
</script>

</body>
</html>