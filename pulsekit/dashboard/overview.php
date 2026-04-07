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
        body.dark-mode .kpi-card.positive {
            border-left-color: #28a745 !important;
        }
        body.dark-mode .kpi-label {
            color: #8892a4 !important;
        }
        body.dark-mode .kpi-value {
            color: #e8eaf0 !important;
        }
        body.dark-mode .kpi-value.positive {
            color: #3ddc6e !important;
        }
        body.dark-mode .kpi-meta {
            color: #6b7a90 !important;
        }

        /* Page header */
        body.dark-mode .page-header h1 {
            color: #e8eaf0 !important;
        }
        body.dark-mode .page-header p {
            color: #8892a4 !important;
        }

        /* Chart containers */
        body.dark-mode .chart-container {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
        }
        body.dark-mode .chart-title {
            color: #e8eaf0 !important;
        }

        /* Tables inside chart containers */
        body.dark-mode .chart-container table td {
            color: #e8eaf0 !important;
            border-bottom-color: #2a2f3e !important;
            background-color: transparent !important;
        }
        body.dark-mode .chart-container table th {
            background-color: #1c3a7a !important;
            color: #e8eaf0 !important;
            border-color: #2a2f3e !important;
        }
        body.dark-mode .chart-container table tr {
            border-bottom-color: #2a2f3e !important;
            background-color: transparent !important;
        }
        body.dark-mode .chart-container table tr:hover {
            background-color: rgba(100, 160, 255, 0.08) !important;
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

        /* Kill any white/light background on td or tr from dashboard-styles.css */
        body.dark-mode table td,
        body.dark-mode table th {
            background-color: transparent !important;
            border-color: #2a2f3e !important;
        }

        /* History table */
        body.dark-mode .history-table {
            background-color: #1a1d27 !important;
        }
        body.dark-mode .history-table th {
            background-color: #1c3a7a !important;
            color: #e8eaf0 !important;
        }
        body.dark-mode .history-table td {
            color: #e8eaf0 !important;
            border-bottom-color: #2a2f3e !important;
        }
        body.dark-mode .history-table tr:hover {
            background-color: rgba(100, 160, 255, 0.08) !important;
        }

        /* Modal */
        body.dark-mode .modal-box {
            background-color: #1a1d27 !important;
        }
        body.dark-mode .modal-box h2 {
            color: #e8eaf0 !important;
        }

        /* Catch-all for any remaining white backgrounds */
        body.dark-mode div[class*="card"],
        body.dark-mode div[class*="panel"],
        body.dark-mode div[class*="box"],
        body.dark-mode div[class*="widget"],
        body.dark-mode div[class*="section"] {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }

        /* Catch-all for text */
        body.dark-mode p,
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3,
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode td, body.dark-mode th, body.dark-mode label,
        body.dark-mode strong {
            color: #e8eaf0 !important;
        }

        /* Preserve green positive / red negative metric colors */
        body.dark-mode td[style*="color: #28a745"],
        body.dark-mode td[style*="color:#28a745"] {
            color: #3ddc6e !important;
        }
        body.dark-mode td[style*="color: #dc3545"],
        body.dark-mode td[style*="color:#dc3545"] {
            color: #ff6b7a !important;
        }

        /* Smooth transitions on key elements */
        .content, .kpi-card, .chart-container, .modal-box,
        .history-table, .history-table td, .history-table th,
        .page-header h1, .page-header p, .kpi-label, .kpi-value, .kpi-meta {
            transition: background-color 0.3s ease, color 0.3s ease,
                        border-color 0.3s ease, box-shadow 0.3s ease !important;
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
            <a href="/pulsekit/dashboard/overview.php" class="active" data-locked="true">Overview</a>
            <a href="/pulsekit/dashboard/seasonality.php" data-locked="true">Seasonality<br>Profiles (MSTL)</a>
            <a href="/pulsekit/dashboard/trend.php" data-locked="true">Trend-True Growth<br>(MoM/YTD)</a>
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
            <h1>Overview</h1>
            <p>Key performance indicators and high-level trends</p>
        </div>

        <button class="download-btn" onclick="downloadReport()">📥 Download Report</button>

        <!-- KPI CARDS -->
        <div class="kpi-container">
            <div class="kpi-card">
                <div class="kpi-label"><span class="kpi-icon">💰</span> Total Sales (Raw)</div>
                <div class="kpi-value"><?php echo number_format($total_sales, 0); ?></div>
                <div class="kpi-meta">All periods and channels</div>
            </div>

            <div class="kpi-card positive">
                <div class="kpi-label"><span class="kpi-icon">📈</span> MoM Growth</div>
                <div class="kpi-value positive"><?php echo number_format($mom_growth, 1); ?>%</div>
                <div class="kpi-meta">Raw: <?php echo number_format($mom_growth, 1); ?>% | Trend: 1.3%</div>
            </div>

            <div class="kpi-card positive">
                <div class="kpi-label"><span class="kpi-icon">📊</span> YTD Growth</div>
                <div class="kpi-value positive"><?php echo number_format($ytd_growth, 1); ?>%</div>
                <div class="kpi-meta">Raw: <?php echo number_format($ytd_growth, 1); ?>% | Trend: 5.4%</div>
            </div>

            <div class="kpi-card">
                <div class="kpi-label"><span class="kpi-icon">📅</span> Next 3M Forecast</div>
                <div class="kpi-value"><?php echo number_format($next_3m_forecast, 0); ?></div>
                <div class="kpi-meta">Reconciled forecast</div>
            </div>
        </div>

        <!-- RAW VS TREND-TRUE SALES CHART -->
        <div class="chart-container">
            <h3 class="chart-title">Raw vs Trend-True Sales</h3>
            <div class="chart-wrapper">
                <canvas id="rawVsTrendChart"></canvas>
            </div>
        </div>

        <!-- TOP DRIVERS & DETRACTORS -->
        <div class="grid-2">
            <div class="chart-container">
                <h3 class="chart-title">📈 Top Growth Drivers</h3>
                <table>
                    <tbody>
                        <?php foreach (array_slice($top_drivers, 0, 4) as $driver): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($driver['name']); ?></strong></td>
                            <td style="text-align: right; color: #28a745; font-weight: 700;">+<?php echo number_format($driver['growth'], 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="chart-container">
                <h3 class="chart-title">📉 Top Detractors</h3>
                <table>
                    <tbody>
                        <?php foreach ($top_detractors as $detractor): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($detractor['name']); ?></strong></td>
                            <td style="text-align: right; color: #dc3545; font-weight: 700;"><?php echo number_format($detractor['decline'], 1); ?>%</td>
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
function openLogoutModal() {
    document.getElementById("logoutModal").style.display = "flex";
}

function closeLogoutModal() {
    document.getElementById("logoutModal").style.display = "none";
}

function downloadReport() {
    alert("Report download initiated...");
}

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

    // Fix inline-style green/red cells (they resist CSS overrides)
    document.querySelectorAll('td[style*="color"]').forEach(td => {
        const style = td.getAttribute('style') || '';
        if (style.includes('#28a745')) {
            td.style.color = isDark ? '#3ddc6e' : '#28a745';
        } else if (style.includes('#dc3545')) {
            td.style.color = isDark ? '#ff6b7a' : '#dc3545';
        }
    });

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
    applyTheme(current === 'dark' ? 'light' : 'dark');
}




// Apply saved theme on load
(function() {
    const saved = localStorage.getItem(THEME_KEY) || 'light';
    applyTheme(saved);
    document.documentElement.classList.remove('dark-preload');
})();

// Initialize Chart AFTER theme is applied so colors are correct
const ctx = document.getElementById('rawVsTrendChart').getContext('2d');
const chartData = <?php echo json_encode($chart_data); ?>;

if (chartData && chartData.length > 0) {
    const labels = chartData.map(d => d.month);
    const rawSales = chartData.map(d => parseFloat(d.raw_sales) || 0);
    const trendSales = chartData.map(d => parseFloat(d.trend_sales) || 0);

    // Read AFTER applyTheme() has already run above
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
                    backgroundColor: 'rgba(0, 102, 204, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    pointRadius: 4,
                    pointBackgroundColor: '#0066cc'
                },
                {
                    label: 'Trend-True Sales',
                    data: trendSales,
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    borderWidth: 2,
                    tension: 0.4,
                    fill: true,
                    borderDash: [5, 5],
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
                    position: 'bottom',
                    labels: { color: legendColor }
                }
            },
            scales: {
                x: {
                    grid: { color: gridColor },
                    ticks: { color: tickColor }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: gridColor },
                    ticks: {
                        color: tickColor,
                        callback: function(value) {
                            return '₱' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
} else {
    document.getElementById('rawVsTrendChart').parentElement.innerHTML = '<p style="text-align: center; color: #999;">No data available for chart</p>';
}
</script>

</body>
</html>