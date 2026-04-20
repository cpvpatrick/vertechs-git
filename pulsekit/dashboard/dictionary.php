<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool


/* TRACK PAGE ACTIVITY */
$page_name = "Dictionary";

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
    <title>Data Dictionary / Methodology - PulseKit Dashboard</title>
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
        body.dark-mode .table-container,
        body.dark-mode .panel {
            background-color: #1a1d27 !important;
            border-color: #2a2f3e !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.4) !important;
        }
        body.dark-mode .chart-title,
        body.dark-mode .table-title { color: #e8eaf0 !important; }

        /* Filter containers */
        body.dark-mode .filter-bar,
        body.dark-mode .action-filter-container {
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

        /* Catch-all for text */
        body.dark-mode p,
        body.dark-mode h1, body.dark-mode h2, body.dark-mode h3,
        body.dark-mode h4, body.dark-mode h5, body.dark-mode h6,
        body.dark-mode td, body.dark-mode th, body.dark-mode label,
        body.dark-mode strong {
            color: #e8eaf0 !important;
        }

        /* Smooth transitions */
        .content, .kpi-card, .chart-container, .panel, .filter-bar, .table-container {
            transition: background-color 0.3s ease, color 0.3s ease,
                        border-color 0.3s ease, box-shadow 0.3s ease !important;
        }

        /* =========================
           SIDEBAR STYLES
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
           DICTIONARY PAGE SPECIFIC
        ========================= */
        .content { flex: 1; overflow-y: auto; padding: 0; background: #f5f6fa; position: relative; }

        .main-inner { padding: 30px 40px; }

        .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; }
        .page-title-box h1 { font-size: 24px; font-weight: 700; color: #1a1a2e; margin: 0 0 5px 0; }
        .page-title-box p { font-size: 14px; color: #888; margin: 0; }

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

        .panel {
            background: #fff;
            padding: 25px;
            border-radius: 12px;
            border: 1px solid #eef0f2;
            box-shadow: 0 1px 3px rgba(0,0,0,0.02);
            margin-bottom: 30px;
        }
        .panel-header { border-bottom: 1px solid #f0f0f0; padding-bottom: 15px; margin-bottom: 20px; }
        .panel-title { font-size: 16px; font-weight: 700; color: #1a1a2e; margin: 0; }

        .methodology-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 25px; }
        .method-item h4 { font-size: 14px; font-weight: 700; color: #1c4aa0; margin: 0 0 10px 0; }
        .method-item p { font-size: 13px; color: #555; line-height: 1.6; margin: 0; }

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
            font-size: 15px; font-weight: 700; color: #1a1a2e;
            padding: 18px 22px 14px;
            border-bottom: 1px solid #f0f0f0;
            margin: 0;
        }

        /* TABLE STYLE MATCHED TO stock.php */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13.5px;
        }
        thead tr {
            background: #fafafa;
            border-bottom: 1px solid #e8e8e8;
        }

        /* ✅ STATIC COLUMN TABS (NO HOVER STATES AT ALL) */
        th {
            padding: 11px 16px;
            text-align: left;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            white-space: nowrap;
            cursor: default !important;
            pointer-events: none !important;
            text-decoration: none !important;
            transition: none !important;
        }
        thead th:hover,
        thead th:active,
        thead th:focus,
        thead th:focus-visible {
            color: #666 !important;
            background: inherit !important;
            text-decoration: none !important;
            box-shadow: none !important;
            outline: none !important;
            cursor: default !important;
        }

        td {
            padding: 12px 16px;
            border-bottom: 1px solid #f5f5f5;
            color: #333;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }

        /* remove zebra / light striping */
        tbody tr,
        tbody tr:nth-child(odd),
        tbody tr:nth-child(even) { background-color: transparent; }

        /* row hover kept (only body rows) */
        tbody tr:hover { background: #f8f9ff !important; }

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
           DARK MODE SIDEBAR (MATCH stock.php)
        ========================= */
        body.dark-mode .sidebar              { background: #1a1d27 !important; border-right-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand        { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand-title  { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-brand-sub    { color: #6b7a90 !important; }
        body.dark-mode .sidebar-link         { color: #b0b8cc !important; }
        body.dark-mode .sidebar-link:hover   { background: rgba(100,160,255,0.1) !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-link.active  { background: rgba(28,74,160,0.35) !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-link::before { color: #3a4560 !important; }
        body.dark-mode .sidebar-footer       { border-top-color: #2a2f3e !important; }
        body.dark-mode .sidebar-user-name    { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-user-email   { color: #6b7a90 !important; }
        body.dark-mode .sidebar-action-btn   { background: #0f1117 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .theme-toggle-btn     { background: rgba(255,255,255,0.06) !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .sidebar-reset-btn:hover  { background: rgba(100,160,255,0.1) !important; border-color: #4a7fc1 !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-logout-btn:hover { background: rgba(220,53,69,0.1) !important; border-color: #dc3545 !important; color: #ff6b7a !important; }

        /* analytics lock banner dark */
        body.dark-mode .analytics-locked-banner { background: rgba(245,215,110,0.06) !important; border-color: #4a3c10 !important; }
        body.dark-mode .alb-header { color: #d4a820 !important; }
        body.dark-mode .alb-body   { color: #b08a3a !important; }
        body.dark-mode .alb-note   { color: #8a6820 !important; }

        /* =========================
           DARK MODE TABLES (MATCH stock.php)
        ========================= */
        body.dark-mode .table-container      { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .table-title          { color: #e8eaf0 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode thead tr              { background: #14171f !important; border-bottom-color: #2a2f3e !important; }

        /* static tabs in dark mode too */
        body.dark-mode th {
            color: #6b7a90 !important;
            cursor: default !important;
            pointer-events: none !important;
            transition: none !important;
            text-decoration: none !important;
        }
        body.dark-mode thead th:hover,
        body.dark-mode thead th:active,
        body.dark-mode thead th:focus,
        body.dark-mode thead th:focus-visible {
            color: #6b7a90 !important;
            background: inherit !important;
            text-decoration: none !important;
            box-shadow: none !important;
            outline: none !important;
            cursor: default !important;
        }

        body.dark-mode td                    { color: #b0b8cc !important; border-bottom-color: #242936 !important; }

        body.dark-mode tbody tr,
        body.dark-mode tbody tr:nth-child(odd),
        body.dark-mode tbody tr:nth-child(even) {
            background-color: transparent !important;
        }
        body.dark-mode tbody tr:hover {
            background: rgba(100,160,255,0.05) !important;
        }

        body.dark-mode .modal-box            { background: #1a1d27 !important; }
        body.dark-mode .modal-box h2         { color: #e8eaf0 !important; }
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
                <a href="/dashboard/pipeline.php" class="sidebar-link" data-index="0">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></span>
                    <span class="sidebar-link-text">Pipeline (Ingestion & Prep)</span>
                </a>
                <a href="/dashboard/history.php" class="sidebar-link" data-index="1">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="sidebar-link-text">Login History (Security Audit)</span>
                </a>
                <a href="/dashboard/overview.php" class="sidebar-link" data-index="2" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/></svg></span>
                    <span class="sidebar-link-text">Overview</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg></span>
                    <span class="sidebar-link-text">Seasonality Profiles</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/trend.php" class="sidebar-link" data-index="4" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                    <span class="sidebar-link-text">Trend-True Growth</span>
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
                <a href="/dashboard/stock.php" class="sidebar-link" data-index="8" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
                    <span class="sidebar-link-text">Stock Allocation Prescriptions</span>
                    <span class="sidebar-lock-icon"></span>
                </a>
                <a href="/dashboard/dictionary.php" class="sidebar-link active" data-index="9" data-locked="true">
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
            <div class="page-header">
                <div class="page-title-box">
                    <h1>Data Dictionary / Methodology</h1>
                    <p>Technical definitions and forecasting logic</p>
                </div>
                <button class="download-report-btn" onclick="downloadReport()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download Report
                </button>
            </div>

            <!-- METHODOLOGY PANEL -->
            <div class="panel">
                <div class="panel-header">
                    <h3 class="panel-title">Forecasting Methodology</h3>
                </div>
                <div class="methodology-grid">
                    <div class="method-item">
                        <h4>MSTL (Multiple Seasonal-Trend decomposition)</h4>
                        <p>Decomposes sales data into trend, multiple seasonal components (weekly, monthly), and remainder. This allows us to isolate the "Trend-True" growth from seasonal noise.</p>
                    </div>
                    <div class="method-item">
                        <h4>LightGBM (Gradient Boosting Machine)</h4>
                        <p>A high-performance gradient boosting framework that uses tree-based learning algorithms. Used for generating base forecasts by incorporating external features and historical patterns.</p>
                    </div>
                    <div class="method-item">
                        <h4>MinT (Minimum Trace Reconciler)</h4>
                        <p>Ensures coherence across the hierarchy (SKU → Cluster → Region → Total). It adjusts base forecasts so that the sum of lower-level forecasts exactly matches the higher-level forecasts.</p>
                    </div>
                    <div class="method-item">
                        <h4>C2G (Contribution to Growth)</h4>
                        <p>Calculates how much each segment (Region, Cluster, or Product) contributes to the overall growth. Helps identify the primary drivers of sales performance.</p>
                    </div>
                </div>
            </div>

            <!-- DATA DICTIONARY TABLE -->
            <div class="table-container">
                <h3 class="table-title">Field Definitions</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Field Name</th>
                            <th>Description</th>
                            <th>Calculation / Source</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Net Sales TY</strong></td>
                            <td>Net sales for the current year (This Year).</td>
                            <td>Sum of net_sales_ty_exvat from fact_sales</td>
                        </tr>
                        <tr>
                            <td><strong>Net Sales LY</strong></td>
                            <td>Net sales for the previous year (Last Year).</td>
                            <td>Sum of net_sales_ly_exvat from fact_sales</td>
                        </tr>
                        <tr>
                            <td><strong>Trend-True Growth</strong></td>
                            <td>Growth rate after removing seasonal effects.</td>
                            <td>(Trend_TY - Trend_LY) / Trend_LY</td>
                        </tr>
                        <tr>
                            <td><strong>Base Forecast</strong></td>
                            <td>Initial forecast generated by LightGBM at each level.</td>
                            <td>LightGBM Model Output</td>
                        </tr>
                        <tr>
                            <td><strong>Reconciled Forecast</strong></td>
                            <td>Forecast after MinT reconciliation for hierarchy coherence.</td>
                            <td>MinT(Base Forecasts)</td>
                        </tr>
                        <tr>
                            <td><strong>Coherence Gap</strong></td>
                            <td>Difference between the sum of children and the parent forecast.</td>
                            <td>Parent - Sum(Children)</td>
                        </tr>
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
            <a href="/auth/logout.php" class="confirm-btn">Yes, Logout</a>
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
