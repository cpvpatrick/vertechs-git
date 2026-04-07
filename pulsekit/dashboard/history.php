<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded is now a PHP bool


/* =========================
   FETCH LOGIN HISTORY
========================= */

$history_stmt = $conn->prepare("
    SELECT username, login_time, logout_time
    FROM login_history
    WHERE user_id = ?
    ORDER BY login_time DESC
");

$history_stmt->bind_param("i", $_SESSION["user_id"]);
$history_stmt->execute();
$result = $history_stmt->get_result();

/* =========================
   TRACK PAGE ACTIVITY
========================= */

$page_name = "Login History";

$activity_stmt = $conn->prepare("
    INSERT INTO user_activity 
    (session_id, user_id, page_name, visited_at)
    VALUES (?, ?, ?, NOW())
");

$activity_stmt->bind_param(
    "sis",
    $_SESSION["session_id"],
    $_SESSION["user_id"],
    $page_name
);

$activity_stmt->execute();
$activity_stmt->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login History</title>
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
           NEW SIDEBAR STYLES
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
        .sidebar-link-text   { flex: 1; line-height: 1.3; }
        .sidebar-lock-icon   { font-size: 11px; opacity: 0.5; flex-shrink: 0; }
        .sidebar-link.locked { opacity: 0.45; cursor: not-allowed; pointer-events: none; }
        .sidebar-link.locked .sidebar-lock-icon { opacity: 1; }

        .analytics-locked-banner { margin: 12px 10px; background: #fffbeb; border: 1px solid #f5d76e; border-radius: 8px; padding: 12px 14px; }
        .alb-header { font-size: 12px; font-weight: 700; color: #92650a; margin-bottom: 6px; }
        .alb-body   { font-size: 11.5px; color: #6b4c0a; line-height: 1.4; margin-bottom: 6px; }
        .alb-note   { font-size: 11px; color: #b07d1a; font-style: italic; line-height: 1.4; }

        .sidebar-footer { padding: 14px 12px 16px; border-top: 1px solid #eee; margin-top: auto; }
        .sidebar-user   { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
        .sidebar-user-avatar { width: 32px; height: 32px; background: #1c4aa0; color: #fff; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
        .sidebar-user-name  { font-size: 13px; font-weight: 600; color: #1a1a2e; }
        .sidebar-user-email { font-size: 11px; color: #888; }
        .sidebar-actions    { display: flex; gap: 8px; margin-bottom: 10px; }
        .sidebar-action-btn { display: flex; align-items: center; justify-content: center; gap: 6px; border: 1px solid #ddd; background: #fff; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; color: #444; transition: background 0.15s, border-color 0.15s; padding: 7px 10px; }
        .sidebar-reset-btn  { flex-shrink: 0; color: #888; }
        .sidebar-reset-btn:hover  { background: #f0f4ff; border-color: #1c4aa0; color: #1c4aa0; }
        .sidebar-logout-btn { flex: 1; color: #444; }
        .sidebar-logout-btn:hover { background: #fff5f5; border-color: #dc3545; color: #dc3545; }
        .theme-toggle-btn   { width: 100%; justify-content: flex-start; background: #f5f6fa; border: 1px solid #e0e0e0; color: #555; }

        /* =========================
           HISTORY PAGE STYLES
        ========================= */
        .content { flex: 1; background: #f5f6fa; overflow-y: auto; padding: 30px 36px; }

        .history-page-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            margin-bottom: 20px;
        }
        .history-page-title { font-size: 28px; font-weight: 700; color: #1a1a2e; margin-bottom: 4px; }
        .history-page-sub   { font-size: 14px; color: #666; }
        .history-shield-icon { margin-top: 4px; opacity: 0.7; }

        .audit-notice {
            display: flex; align-items: flex-start; gap: 12px;
            background: #f0f6ff; border: 1px solid #c8deff;
            border-radius: 8px; padding: 14px 18px;
            font-size: 13px; color: #1a1a2e; margin-bottom: 20px; line-height: 1.5;
        }
        .audit-notice-icon { flex-shrink: 0; margin-top: 2px; }

        .auth-events-card {
            background: #fff; border-radius: 10px;
            border: 1px solid #e8e8e8;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden;
        }

        .auth-events-header {
            display: flex; align-items: flex-start; justify-content: space-between;
            padding: 20px 22px 14px;
            border-bottom: 1px solid #f0f0f0;
        }

        .auth-events-title { font-size: 15px; font-weight: 700; color: #1a1a2e; margin-bottom: 2px; }
        .auth-events-count { font-size: 12px; color: #888; }

        .auth-events-actions { display: flex; gap: 8px; }

        .auth-action-btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 7px 14px; border-radius: 7px; font-size: 12px; font-weight: 600;
            cursor: pointer; border: 1px solid #ddd; background: #fff; color: #444;
            transition: background 0.15s, border-color 0.15s;
        }
        .auth-action-btn:hover { background: #f5f6fa; border-color: #bbb; }
        .auth-action-btn--danger { color: #888; }
        .auth-action-btn--danger:hover { background: #fff5f5; border-color: #dc3545; color: #dc3545; }

        /* Filters */
        .auth-filters {
            display: flex; align-items: center; gap: 10px;
            padding: 12px 22px; border-bottom: 1px solid #f0f0f0; flex-wrap: wrap;
        }
        .auth-search-wrap {
            display: flex; align-items: center; gap: 7px;
            border: 1px solid #ddd; border-radius: 6px; padding: 7px 12px;
            background: #fff; flex: 1; min-width: 160px;
        }
        .auth-search-wrap input {
            border: none; outline: none; font-size: 13px; background: transparent;
            color: #333; width: 100%;
        }
        .auth-filters select, .auth-filters input[type="date"] {
            padding: 7px 10px; border: 1px solid #ddd; border-radius: 6px;
            font-size: 13px; color: #444; background: #fff; cursor: pointer; outline: none;
        }
        .auth-filters select:focus, .auth-filters input[type="date"]:focus {
            border-color: #1c4aa0;
        }

        /* Table */
        .auth-table-wrap { overflow-x: auto; }
        .auth-table {
            width: 100%; border-collapse: collapse; font-size: 13px; min-width: 900px;
        }
        .auth-table thead tr { border-bottom: 1px solid #e8e8e8; background: #fafafa; }
        .auth-table th {
            padding: 11px 14px; text-align: left; font-size: 12px;
            font-weight: 600; color: #666; white-space: nowrap;
        }
        .auth-table td { padding: 11px 14px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .auth-table tbody tr:hover { background: #f8f9ff; }
        .auth-table tbody tr:last-child td { border-bottom: none; }

        .ts-cell       { display: flex; align-items: center; gap: 6px; color: #444; font-size: 12px; white-space: nowrap; }
        .email-cell    { font-size: 12.5px; color: #333; }
        .device-cell   { display: flex; align-items: center; gap: 5px; color: #555; font-size: 12.5px; white-space: nowrap; }
        .location-cell { display: flex; align-items: center; gap: 5px; color: #555; font-size: 12.5px; white-space: nowrap; }
        .ip-cell       { font-family: monospace; font-size: 12px; color: #444; }
        .reason-cell   { font-size: 12.5px; color: #666; }

        /* Event badges */
        .event-badge {
            display: inline-block; padding: 4px 10px; border-radius: 5px;
            font-size: 11.5px; font-weight: 600; white-space: nowrap;
        }

        /* Result badges */
        .result-badge {
            display: inline-flex; align-items: center; gap: 4px;
            font-size: 12.5px; font-weight: 600;
        }
        .result-success { color: #28a745; }
        .result-fail    { color: #dc3545; }

        /* =========================
           DARK MODE — SIDEBAR
        ========================= */
        body.dark-mode .sidebar { background: #1a1d27 !important; border-right-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .sidebar-brand-title { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-brand-sub   { color: #6b7a90 !important; }
        body.dark-mode .sidebar-link        { color: #b0b8cc !important; }
        body.dark-mode .sidebar-link:hover  { background: rgba(100,160,255,0.1) !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-link.active { background: rgba(28,74,160,0.35) !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-link::before { color: #3a4560 !important; }
        body.dark-mode .analytics-locked-banner { background: rgba(245,215,110,0.06) !important; border-color: #4a3c10 !important; }
        body.dark-mode .alb-header { color: #d4a820 !important; }
        body.dark-mode .alb-body   { color: #b08a3a !important; }
        body.dark-mode .alb-note   { color: #8a6820 !important; }
        body.dark-mode .sidebar-footer { border-top-color: #2a2f3e !important; }
        body.dark-mode .sidebar-user-avatar { background: #1c4aa0 !important; }
        body.dark-mode .sidebar-user-name  { color: #e8eaf0 !important; }
        body.dark-mode .sidebar-user-email { color: #6b7a90 !important; }
        body.dark-mode .sidebar-action-btn { background: #0f1117 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .sidebar-reset-btn:hover  { background: rgba(100,160,255,0.1) !important; border-color: #4a7fc1 !important; color: #7eb3ff !important; }
        body.dark-mode .sidebar-logout-btn:hover { background: rgba(220,53,69,0.1) !important; border-color: #dc3545 !important; color: #ff6b7a !important; }
        body.dark-mode .theme-toggle-btn { background: rgba(255,255,255,0.06) !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }

        /* =========================
           DARK MODE — HISTORY PAGE
        ========================= */
        body.dark-mode .content { background: #0f1117 !important; }
        body.dark-mode .history-page-title { color: #e8eaf0 !important; }
        body.dark-mode .history-page-sub   { color: #8892a4 !important; }
        body.dark-mode .audit-notice { background: rgba(28,74,160,0.12) !important; border-color: #1c4aa0 !important; color: #b0b8cc !important; }
        body.dark-mode .auth-events-card { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .auth-events-header { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .auth-events-title { color: #e8eaf0 !important; }
        body.dark-mode .auth-events-count { color: #6b7a90 !important; }
        body.dark-mode .auth-action-btn { background: #0f1117 !important; border-color: #2a2f3e !important; color: #b0b8cc !important; }
        body.dark-mode .auth-action-btn:hover { background: #1a1d27 !important; }
        body.dark-mode .auth-action-btn--danger:hover { background: rgba(220,53,69,0.1) !important; border-color: #dc3545 !important; color: #ff6b7a !important; }
        body.dark-mode .auth-filters { border-bottom-color: #2a2f3e !important; }
        body.dark-mode .auth-search-wrap { background: #0f1117 !important; border-color: #2a2f3e !important; }
        body.dark-mode .auth-search-wrap input { color: #e8eaf0 !important; }
        body.dark-mode .auth-filters select, body.dark-mode .auth-filters input[type="date"] { background: #0f1117 !important; border-color: #2a2f3e !important; color: #e8eaf0 !important; }
        body.dark-mode .auth-table thead tr { background: #0f1117 !important; border-bottom-color: #2a2f3e !important; }
        body.dark-mode .auth-table th { color: #6b7a90 !important; }
        body.dark-mode .auth-table td { border-bottom-color: #1e2233 !important; }
        body.dark-mode .auth-table tbody tr:hover { background: rgba(100,160,255,0.06) !important; }
        body.dark-mode .ts-cell, body.dark-mode .email-cell, body.dark-mode .device-cell,
        body.dark-mode .location-cell, body.dark-mode .ip-cell, body.dark-mode .reason-cell { color: #b0b8cc !important; }
        body.dark-mode .result-success { color: #3ddc6e !important; }
        body.dark-mode .result-fail    { color: #ff6b7a !important; }

    </style>
</head>
<body>

<div class="dashboard-container">

    <!-- ===================== SIDEBAR ===================== -->
    <aside class="sidebar" id="appSidebar">
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
                <a href="/pulsekit/dashboard/history.php" class="sidebar-link active" data-index="1">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                    <span class="sidebar-link-text">Login History (Security Audit)</span>
                </a>
                <a href="/pulsekit/dashboard/overview.php" class="sidebar-link" data-index="2" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></span>
                    <span class="sidebar-link-text">Overview</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
                    <span class="sidebar-link-text">Seasonality Profiles (MSTL)</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/trend.php" class="sidebar-link" data-index="4" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg></span>
                    <span class="sidebar-link-text">Trend-True Growth (MoM/YTD)</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/forecast.php" class="sidebar-link" data-index="5" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                    <span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></span>
                    <span class="sidebar-link-text">Coherence Check</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/c2g.php" class="sidebar-link" data-index="7" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg></span>
                    <span class="sidebar-link-text">C2G Growth Drivers</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/stock.php" class="sidebar-link" data-index="8" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></span>
                    <span class="sidebar-link-text">Stock Allocation Prescriptions</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/pulsekit/dashboard/dictionary.php" class="sidebar-link" data-index="9" data-locked="true">
                    <span class="sidebar-link-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg></span>
                    <span class="sidebar-link-text">Data Dictionary/Methodology</span>
                    <span class="sidebar-lock-icon">🔒</span>
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

        <!-- PAGE HEADER -->
        <div class="history-page-header">
            <div class="history-page-header-left">
                <h1 class="history-page-title">Login History</h1>
                <p class="history-page-sub">Security audit log of all authentication events</p>
            </div>
            <div class="history-shield-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#1c4aa0" stroke-width="1.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
        </div>

        <!-- MOCK AUDIT LOG NOTICE -->
        <div class="audit-notice">
            <span class="audit-notice-icon">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1c4aa0" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </span>
            <div>
                <strong>Local Mock Audit Log:</strong><br>
                This is a demonstration of login history tracking. In a production environment, audit logs would be stored securely server-side with enhanced security measures.
            </div>
        </div>

        <!-- AUTHENTICATION EVENTS TABLE -->
        <div class="auth-events-card">
            <div class="auth-events-header">
                <div class="auth-events-header-left">
                    <h3 class="auth-events-title">Authentication Events</h3>
                    <p class="auth-events-count">Total events: <span id="eventCount">0</span></p>
                </div>
                <div class="auth-events-actions">
                    <button class="auth-action-btn" onclick="exportCSV()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Export CSV
                    </button>
                    <button class="auth-action-btn auth-action-btn--danger" onclick="clearHistory()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                        Clear History
                    </button>
                </div>
            </div>

            <!-- FILTERS -->
            <div class="auth-filters">
                <div class="auth-search-wrap">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#aaa" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    <input type="text" id="searchEmail" placeholder="Search by email" oninput="filterTable()">
                </div>
                <select id="filterEventType" onchange="filterTable()">
                    <option value="">All Events</option>
                    <option value="Login Success">Login Success</option>
                    <option value="Login Attempt">Login Attempt</option>
                    <option value="MFA Sent">MFA Sent</option>
                    <option value="MFA Success">MFA Success</option>
                    <option value="Confirm Email">Confirm Email</option>
                    <option value="Register">Register</option>
                    <option value="Logout">Logout</option>
                </select>
                <select id="filterResult" onchange="filterTable()">
                    <option value="">All Results</option>
                    <option value="Success">Success</option>
                    <option value="Fail">Fail</option>
                </select>
                <input type="date" id="filterDateFrom" onchange="filterTable()" placeholder="dd/mm/yyyy">
                <input type="date" id="filterDateTo" onchange="filterTable()" placeholder="dd/mm/yyyy">
            </div>

            <!-- TABLE -->
            <div class="auth-table-wrap">
                <table class="auth-table" id="authTable">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Event Type</th>
                            <th>Email</th>
                            <th>Device</th>
                            <th>Location</th>
                            <th>IP Address</th>
                            <th>Result</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody id="authTableBody">
                        <!-- Populated by JS from PHP data + mock enrichment -->
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
   MODULE LOCK SYSTEM
========================= */
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
    if (confirm('Reset your dataset? This will re-lock all analytics modules until you load the sample data again.')) {
        fetch('/pulsekit/dashboard/reset_dataset.php', { method: 'POST', credentials: 'same-origin' })
        .then(res => res.json())
        .then(data => { if (data.success) window.location.reload(); })
        .catch(() => alert('Network error. Please try again.'));
    }
}

function showLockedOverlay() {
    const overlay = document.getElementById('lockedOverlay');
    if (overlay) overlay.classList.add('visible');
}

(function() {
    const lockedPages = ['overview.php','seasonality.php','trend.php','forecast.php','coherence.php','c2g.php','stock.php','dictionary.php'];
    const currentFile = window.location.pathname.split('/').pop();
    if (lockedPages.includes(currentFile) && !UNLOCKED) showLockedOverlay();
    applyLockState();
})();

/* =========================
   THEME
========================= */
const THEME_KEY = 'pulsekit-theme';

function applyTheme(theme) {
    const isDark = theme === 'dark';
    document.body.classList.toggle('dark-mode', isDark);
    applyLockState();
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

function openLogoutModal()  { document.getElementById("logoutModal").style.display = "flex"; }
function closeLogoutModal() { document.getElementById("logoutModal").style.display = "none"; }

/* =========================
   AUTHENTICATION EVENTS TABLE
========================= */

// Raw PHP login_history data
const phpRows = <?php
    $rows = [];
    $history_stmt->data_seek(0);
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'login_time'  => $row['login_time'],
            'logout_time' => $row['logout_time'] ?? null,
            'username'    => $row['username']
        ];
    }
    echo json_encode($rows);
?>;

// Mock enrichment data to simulate a full audit log
const MOCK_LOCATIONS = [
    'Cebu, Philippines', 'Makati, Philippines', 'Quezon City, Philippines',
    'Manila, Philippines', 'Davao, Philippines'
];
const MOCK_IPS = [
    '203.177.89.142', '172.16.0.25', '10.0.0.50',
    '192.168.1.100', '203.177.89.143'
];
const EVENT_TYPES = [
    'Login Success', 'Login Attempt', 'MFA Sent', 'MFA Success',
    'Confirm Email', 'Register', 'Logout'
];
const EVENT_COLORS = {
    'Login Success':  { bg: '#111',    color: '#fff' },
    'Login Attempt':  { bg: '#e8f0fe', color: '#1c4aa0' },
    'MFA Sent':       { bg: '#e8f0fe', color: '#1c4aa0' },
    'MFA Success':    { bg: '#111',    color: '#fff' },
    'Confirm Email':  { bg: '#111',    color: '#fff' },
    'Register':       { bg: '#111',    color: '#fff' },
    'Logout':         { bg: '#e8f0fe', color: '#1c4aa0' }
};

// Build enriched rows from phpRows + seed-based mock data
function buildEvents() {
    const events = [];
    phpRows.forEach((row, i) => {
        const seed = i * 7;
        const loc  = MOCK_LOCATIONS[seed % MOCK_LOCATIONS.length];
        const ip   = MOCK_IPS[seed % MOCK_IPS.length];
        const eventType = EVENT_TYPES[seed % EVENT_TYPES.length];
        const isSuccess = eventType.includes('Success') || eventType === 'Register' || eventType === 'Confirm Email' || eventType === 'MFA Sent';
        const result  = isSuccess ? 'Success' : 'Fail';
        const reason  = isSuccess ? '—' : (eventType === 'Confirm Email' ? 'Invalid token' : 'Wrong password');
        const email   = row.username ? row.username + '@' + 'gmail.com' : 'unknown';

        events.push({
            timestamp: row.login_time,
            eventType,
            email: row.username && row.username !== '' ? email : 'unknown',
            device:   'Chrome on Windows',
            location: loc,
            ip,
            result,
            reason
        });

        // Add logout event if present
        if (row.logout_time) {
            const logoutSeed = seed + 3;
            events.push({
                timestamp: row.logout_time,
                eventType: 'Logout',
                email: email,
                device: 'Chrome on Windows',
                location: MOCK_LOCATIONS[logoutSeed % MOCK_LOCATIONS.length],
                ip:       MOCK_IPS[logoutSeed % MOCK_IPS.length],
                result:   'Success',
                reason:   '—'
            });
        }
    });
    // Sort descending by timestamp
    events.sort((a, b) => new Date(b.timestamp) - new Date(a.timestamp));
    return events;
}

let ALL_EVENTS = buildEvents();

function formatTimestamp(ts) {
    if (!ts) return '—';
    const d = new Date(ts);
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    const pad = n => String(n).padStart(2, '0');
    return `${months[d.getMonth()]} ${pad(d.getDate())}, ${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

function renderResultBadge(result) {
    if (result === 'Success') {
        return `<span class="result-badge result-success">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
            Success</span>`;
    } else {
        return `<span class="result-badge result-fail">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            Fail</span>`;
    }
}

function renderEventBadge(eventType) {
    const style = EVENT_COLORS[eventType] || { bg: '#eee', color: '#333' };
    return `<span class="event-badge" style="background:${style.bg};color:${style.color}">${eventType}</span>`;
}

function renderTable(events) {
    const tbody = document.getElementById('authTableBody');
    document.getElementById('eventCount').textContent = events.length;
    if (events.length === 0) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#999;">No events found</td></tr>';
        return;
    }
    tbody.innerHTML = events.map(e => `
        <tr>
            <td class="ts-cell">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                ${formatTimestamp(e.timestamp)}
            </td>
            <td>${renderEventBadge(e.eventType)}</td>
            <td class="email-cell">${e.email}</td>
            <td class="device-cell">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2" ry="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
                ${e.device}
            </td>
            <td class="location-cell">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 2a8 8 0 0 0-8 8c0 5.25 8 14 8 14s8-8.75 8-14a8 8 0 0 0-8-8z"/></svg>
                ${e.location}
            </td>
            <td class="ip-cell">${e.ip}</td>
            <td>${renderResultBadge(e.result)}</td>
            <td class="reason-cell">${e.reason}</td>
        </tr>
    `).join('');
}

function filterTable() {
    const search    = document.getElementById('searchEmail').value.toLowerCase();
    const eventType = document.getElementById('filterEventType').value;
    const resultF   = document.getElementById('filterResult').value;
    const dateFrom  = document.getElementById('filterDateFrom').value;
    const dateTo    = document.getElementById('filterDateTo').value;

    let filtered = ALL_EVENTS.filter(e => {
        if (search    && !e.email.toLowerCase().includes(search)) return false;
        if (eventType && e.eventType !== eventType)               return false;
        if (resultF   && e.result !== resultF)                    return false;
        if (dateFrom) {
            const from = new Date(dateFrom);
            if (new Date(e.timestamp) < from) return false;
        }
        if (dateTo) {
            const to = new Date(dateTo);
            to.setHours(23, 59, 59);
            if (new Date(e.timestamp) > to) return false;
        }
        return true;
    });
    renderTable(filtered);
}

function exportCSV() {
    const headers = ['Timestamp','Event Type','Email','Device','Location','IP Address','Result','Reason'];
    const rows = ALL_EVENTS.map(e => [
        formatTimestamp(e.timestamp), e.eventType, e.email,
        e.device, e.location, e.ip, e.result, e.reason
    ]);
    const csv = [headers, ...rows].map(r => r.map(v => `"${v}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = 'login_history.csv'; a.click();
    URL.revokeObjectURL(url);
}

function clearHistory() {
    if (confirm('Clear displayed history? This only clears the local view.')) {
        ALL_EVENTS = [];
        renderTable([]);
    }
}

// Initial render
renderTable(ALL_EVENTS);

</script>
</body>
</html>