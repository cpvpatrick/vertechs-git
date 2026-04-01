<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool


/* TRACK PAGE ACTIVITY */
$page_name = "Seasonality"; // change per page

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
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seasonality Profiles</title>
    <link rel="stylesheet" href="/pulsekit/assets/main.css">
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
            <!-- LOGO -->
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
            <a href="/pulsekit/dashboard/stock.php" data-locked="true">Stock Allocation<br>Prescriptions</a>
            <a href="/pulsekit/dashboard/dictionary.php" class="active" data-locked="true">Data Dictionary /<br>Methodology</a>
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

    <main class="content">
        <!-- Seasonality content here -->
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
</script>
</body>
</html>