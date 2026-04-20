<?php
require_once "../includes/auth_check.php";

/* FETCH PER-USER UNLOCK STATUS FROM DATABASE */
require_once "../includes/get_unlock_status.php";
// $user_dataset_loaded    = dataset loaded (shows post-load UI)
// $user_pipeline_executed = pipeline run (unlocks modules)


/* TRACK PAGE ACTIVITY */
$page_name = "Pipeline";

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
    <title>Pipeline: Ingestion & Preparation - PulseKit Dashboard</title>
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
        .sidebar-link.locked {
            opacity: 0.45 !important;
            cursor: not-allowed !important;
            pointer-events: none !important;
        }
        .sidebar-link:not(.locked) .sidebar-lock-icon {
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

        /* =========================
           NEW SIDEBAR STYLES
        ========================= */
        .dashboard-container {
            display: flex;
            height: 100vh;
            background: #f5f6fa;
        }

        /* Sidebar */
        .sidebar {
            width: 260px;
            min-width: 260px;
            height: 100vh;
            background: #ffffff;
            border-right: 1px solid #e8e8e8;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            overflow: hidden;
        }

        .sidebar-inner {
            display: flex;
            flex-direction: column;
            height: 100%;
            overflow-y: auto;
            padding: 20px 0 0 0;
        }

        /* Brand block */
        .sidebar-brand {
            padding: 0 18px 18px 18px;
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
        }
        .sidebar-brand-title {
            font-size: 12px;
            font-weight: 700;
            color: #1a1a2e;
            line-height: 1.4;
            margin-bottom: 4px;
        }
        .sidebar-brand-sub {
            font-size: 11px;
            color: #888;
            font-weight: 500;
        }

        /* Nav links */
        .sidebar-nav {
            flex: 1;
            padding: 4px 10px;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 10px;
            border-radius: 7px;
            text-decoration: none;
            color: #444;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 2px;
            transition: background 0.15s ease, color 0.15s ease;
            position: relative;
        }

        .sidebar-link:hover {
            background: #f0f4ff;
            color: #1c4aa0;
        }

        .sidebar-link.active {
            background: #e8f0fe;
            color: #1c4aa0;
            font-weight: 600;
        }

        .sidebar-link-icon {
            width: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: inherit;
        }

        /* Index number shown left of icon */
        .sidebar-link::before {
            content: attr(data-index);
            font-size: 11px;
            color: #bbb;
            width: 14px;
            text-align: center;
            flex-shrink: 0;
        }

        .sidebar-link-text {
            flex: 1;
            line-height: 1.3;
        }

        .sidebar-lock-icon {
            font-size: 11px;
            opacity: 0.5;
            flex-shrink: 0;
        }

        /* Locked state */
        .sidebar-link.locked {
            opacity: 0.45;
            cursor: not-allowed;
            pointer-events: none;
        }
        .sidebar-link.locked .sidebar-lock-icon {
            opacity: 1;
        }

        /* Analytics locked banner */
        .analytics-locked-banner {
            margin: 12px 10px;
            background: #fffbeb;
            border: 1px solid #f5d76e;
            border-radius: 8px;
            padding: 12px 14px;
        }
        .alb-header {
            font-size: 12px;
            font-weight: 700;
            color: #92650a;
            margin-bottom: 6px;
        }
        .alb-body {
            font-size: 11.5px;
            color: #6b4c0a;
            line-height: 1.4;
            margin-bottom: 6px;
        }
        .alb-note {
            font-size: 11px;
            color: #b07d1a;
            font-style: italic;
            line-height: 1.4;
        }

        /* Footer */
        .sidebar-footer {
            padding: 14px 12px 16px;
            border-top: 1px solid #eee;
            margin-top: auto;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .sidebar-user-avatar {
            width: 32px;
            height: 32px;
            background: #1c4aa0;
            color: #fff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .sidebar-user-name {
            font-size: 13px;
            font-weight: 600;
            color: #1a1a2e;
        }

        .sidebar-user-email {
            font-size: 11px;
            color: #888;
        }

        /* Action buttons row */
        .sidebar-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 10px;
        }

        .sidebar-action-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            transition: background 0.15s, border-color 0.15s;
            padding: 7px 10px;
        }

        .sidebar-reset-btn {
            flex-shrink: 0;
            color: #888;
        }

        .sidebar-reset-btn:hover {
            background: #f0f4ff;
            border-color: #1c4aa0;
            color: #1c4aa0;
        }

        .sidebar-logout-btn {
            flex: 1;
            color: #444;
        }

        .sidebar-logout-btn:hover {
            background: #fff5f5;
            border-color: #dc3545;
            color: #dc3545;
        }

        /* Theme toggle override for new sidebar */
        .theme-toggle-btn {
            width: 100%;
            justify-content: flex-start;
            background: #f5f6fa;
            border: 1px solid #e0e0e0;
            color: #555;
        }

        /* =========================
           PIPELINE PAGE CONTENT
        ========================= */
        .pipeline-welcome-card {
            background: #fff;
            border-radius: 10px;
            padding: 32px 36px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            text-align: center;
        }

        .pipeline-welcome-icon {
            margin-bottom: 14px;
        }

        .pipeline-welcome-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 10px;
        }

        .pipeline-welcome-sub {
            font-size: 13.5px;
            color: #666;
            max-width: 700px;
            margin: 0 auto 28px;
            line-height: 1.6;
        }

        .pipeline-cards-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            text-align: left;
        }

        .pipeline-data-card {
            border: 1px solid #e8e8e8;
            border-radius: 10px;
            padding: 26px 24px;
            background: #fff;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .pipeline-data-card--active {
            border: 2px solid #1c4aa0;
            background: #f6f9ff;
        }

        .pipeline-data-card-icon {
            margin-bottom: 4px;
        }

        .pipeline-data-card-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
        }

        .pipeline-data-card-desc {
            font-size: 13px;
            color: #666;
            line-height: 1.5;
        }

        .pipeline-upload-area {
            border: 2px dashed #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            margin-top: 4px;
        }

        .pipeline-choose-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            color: #444;
            cursor: pointer;
            transition: border-color 0.15s, color 0.15s;
        }

        .pipeline-choose-btn:hover {
            border-color: #1c4aa0;
            color: #1c4aa0;
        }

        .pipeline-upload-note {
            font-size: 11.5px;
            color: #aaa;
            margin-top: 8px;
        }

        .load-sample-btn {
            background: #1c4aa0;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 11px 22px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
            width: 100%;
        }

        .load-sample-btn:hover:not(:disabled) {
            background: #163b7a;
        }

        .load-sample-btn:disabled {
            cursor: default;
        }

        .pipeline-sample-note {
            font-size: 12px;
            color: #999;
            text-align: center;
        }

        /* =========================
           DARK MODE — NEW SIDEBAR
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

        body.dark-mode .pipeline-welcome-card {
            background: #1a1d27 !important;
        }

        body.dark-mode .pipeline-welcome-title { color: #e8eaf0 !important; }
        body.dark-mode .pipeline-welcome-sub   { color: #8892a4 !important; }

        body.dark-mode .pipeline-data-card {
            background: #1a1d27 !important;
            border-color: #2a2f3e !important;
        }

        body.dark-mode .pipeline-data-card--active {
            background: #16203a !important;
            border-color: #1c4aa0 !important;
        }

        body.dark-mode .pipeline-data-card-title { color: #e8eaf0 !important; }
        body.dark-mode .pipeline-data-card-desc  { color: #8892a4 !important; }

        body.dark-mode .pipeline-upload-area {
            border-color: #2a2f3e !important;
            background: #0f1117 !important;
        }

        body.dark-mode .pipeline-choose-btn {
            background: #0f1117 !important;
            border-color: #2a2f3e !important;
            color: #b0b8cc !important;
        }

        body.dark-mode .pipeline-sample-note { color: #6b7a90 !important; }

        /* =========================
           POST-LOAD PIPELINE SECTIONS
        ========================= */
        .pipe-section {
            background: #fff;
            border-radius: 10px;
            padding: 24px 28px;
            margin-bottom: 16px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border: 1px solid #e8e8e8;
        }
        .pipe-section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .pipe-section-title {
            font-size: 16px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 0;
        }
        .ingestion-summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 14px;
        }
        .ingestion-meta-item {
            padding: 14px 18px;
            border-right: 1px solid #e8e8e8;
        }
        .ingestion-meta-item:last-child { border-right: none; }
        .ingestion-meta-label {
            font-size: 11px;
            color: #888;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 5px;
        }
        .ingestion-meta-value {
            font-size: 14px;
            font-weight: 600;
            color: #1a1a2e;
        }
        .ingestion-pass { color: #28a745 !important; }
        .show-preview-link {
            font-size: 13px;
            color: #1c4aa0;
            text-decoration: none;
            font-weight: 500;
        }
        .show-preview-link:hover { text-decoration: underline; }
        .pipe-pass-badge {
            background: #e8f9ee;
            color: #1a7a3c;
            border: 1px solid #b2e8c6;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 12px;
        }
        .quality-metrics-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 12px;
        }
        .quality-metric {
            background: #f8f9fa;
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }
        .qm-value {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 4px;
        }
        .qm-label { font-size: 12px; color: #888; }
        .run-pipeline-btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: #1c4aa0;
            color: #fff;
            border: none;
            border-radius: 7px;
            padding: 9px 20px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s;
        }
        .run-pipeline-btn:hover:not(:disabled) { background: #163b7a; }
        .run-pipeline-btn:disabled { background: #8a9abc; cursor: default; }
        .run-pipeline-btn.complete {
            background: #e8f0fe;
            color: #1c4aa0;
            border: 1px solid #b8cef0;
        }
        .pipeline-stage-list { display: flex; flex-direction: column; }
        .pipeline-stage {
            border: 1px solid #e8e8e8;
            border-radius: 8px;
            margin-bottom: 8px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .pipeline-stage.stage-running  { border-color: #1c4aa0; }
        .pipeline-stage.stage-complete { border-color: #28a745; }
        .stage-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            background: #fff;
        }
        .stage-dot {
            width: 20px; height: 20px;
            border-radius: 50%;
            border: 2px solid #ddd;
            background: #fff;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }
        .stage-dot.running {
            border-color: #1c4aa0;
            background: #1c4aa0;
            animation: pulseDot 1s ease-in-out infinite;
        }
        .stage-dot.complete {
            border-color: #28a745;
            background: #28a745;
            color: #fff;
        }
        .stage-dot.complete::after { content: "✓"; font-size: 11px; font-weight: 700; }
        @keyframes pulseDot {
            0%, 100% { box-shadow: 0 0 0 0 rgba(28,74,160,0.4); }
            50%       { box-shadow: 0 0 0 5px rgba(28,74,160,0); }
        }
        .stage-name { font-size: 14px; font-weight: 600; color: #1a1a2e; flex: 1; }
        .stage-duration { font-size: 12px; color: #888; font-family: monospace; }
        .stage-body {
            padding: 0 18px 14px 50px;
            background: #fafafa;
            border-top: 1px solid #f0f0f0;
        }
        .stage-rowcount { font-size: 12px; color: #666; margin-bottom: 8px; margin-top: 10px; }
        .stage-log {
            font-family: 'Courier New', monospace;
            font-size: 12px; color: #444;
            background: transparent; border: none; margin: 0; padding: 0;
            white-space: pre-wrap; line-height: 1.6;
        }
        /* Dark mode — pipeline sections */
        body.dark-mode .pipe-section { background: #1a1d27 !important; border-color: #2a2f3e !important; }
        body.dark-mode .pipe-section-title { color: #e8eaf0 !important; }
        body.dark-mode .ingestion-summary-grid { border-color: #2a2f3e !important; }
        body.dark-mode .ingestion-meta-item { border-right-color: #2a2f3e !important; }
        body.dark-mode .ingestion-meta-label { color: #6b7a90 !important; }
        body.dark-mode .ingestion-meta-value { color: #e8eaf0 !important; }
        body.dark-mode .pipe-pass-badge { background: rgba(40,167,69,0.12) !important; color: #3ddc6e !important; border-color: rgba(40,167,69,0.25) !important; }
        body.dark-mode .quality-metric { background: #0f1117 !important; }
        body.dark-mode .qm-value { color: #e8eaf0 !important; }
        body.dark-mode .qm-label { color: #6b7a90 !important; }
        body.dark-mode .pipeline-stage { border-color: #2a2f3e !important; }
        body.dark-mode .pipeline-stage.stage-running  { border-color: #1c4aa0 !important; }
        body.dark-mode .pipeline-stage.stage-complete { border-color: #28a745 !important; }
        body.dark-mode .stage-header { background: #1a1d27 !important; }
        body.dark-mode .stage-name   { color: #e8eaf0 !important; }
        body.dark-mode .stage-duration { color: #6b7a90 !important; }
        body.dark-mode .stage-body { background: #0f1117 !important; border-top-color: #2a2f3e !important; }
        body.dark-mode .stage-rowcount { color: #8892a4 !important; }
        body.dark-mode .stage-log { color: #b0b8cc !important; }
        body.dark-mode .show-preview-link { color: #5b8fe8 !important; }
        body.dark-mode .run-pipeline-btn.complete { background: rgba(28,74,160,0.2) !important; color: #7eb3ff !important; border-color: #1c4aa0 !important; }

    </style>
</head>
<body>

<div class="dashboard-container">

    <!-- ===================== NEW SIDEBAR ===================== -->
    <aside class="sidebar" id="appSidebar">
        <div class="sidebar-inner">

            <!-- TOP: branding -->
            <div class="sidebar-brand">
                <div class="sidebar-brand-title">Coherent Nestlé Philippines Sales Forecasting at Southstar Drug</div>
                <div class="sidebar-brand-sub">MSTL · LightGBM · MinT · C2G</div>
            </div>

            <!-- NAV LINKS -->
            <nav class="sidebar-nav">
                <a href="/dashboard/pipeline.php" class="sidebar-link active" data-index="0">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </span>
                    <span class="sidebar-link-text">Pipeline (Ingestion &amp; Prep)</span>
                </a>
                <a href="/dashboard/history.php" class="sidebar-link" data-index="1">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </span>
                    <span class="sidebar-link-text">Login History (Security Audit)</span>
                </a>
                <a href="/dashboard/overview.php" class="sidebar-link" data-index="2" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </span>
                    <span class="sidebar-link-text">Overview</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/seasonality.php" class="sidebar-link" data-index="3" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </span>
                    <span class="sidebar-link-text">Seasonality Profiles (MSTL)</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/trend.php" class="sidebar-link" data-index="4" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>
                    </span>
                    <span class="sidebar-link-text">Trend-True Growth (MoM/YTD)</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/forecast.php" class="sidebar-link" data-index="5" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </span>
                    <span class="sidebar-link-text">Forecasts (Base vs Reconciled)</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/coherence.php" class="sidebar-link" data-index="6" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </span>
                    <span class="sidebar-link-text">Coherence Check</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/c2g.php" class="sidebar-link" data-index="7" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14M4.93 4.93a10 10 0 0 0 0 14.14"/></svg>
                    </span>
                    <span class="sidebar-link-text">C2G Growth Drivers</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/stock.php" class="sidebar-link" data-index="8" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    </span>
                    <span class="sidebar-link-text">Stock Allocation Prescriptions</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
                <a href="/dashboard/dictionary.php" class="sidebar-link" data-index="9" data-locked="true">
                    <span class="sidebar-link-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                    </span>
                    <span class="sidebar-link-text">Data Dictionary/Methodology</span>
                    <span class="sidebar-lock-icon">🔒</span>
                </a>
            </nav>

            <!-- ANALYTICS LOCKED BANNER (shown when locked) -->
            <?php if (!$user_pipeline_executed): ?>
            <div class="analytics-locked-banner">
                <div class="alb-header">⚠ Analytics Locked</div>
                <div class="alb-body">Run the pipeline first to unlock all analytics visualizations and pages.</div>
                <div class="alb-note">Note: Pipeline and Login History are always accessible.</div>
            </div>
            <?php endif; ?>

            <!-- BOTTOM: user info + actions -->
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
                    <!-- Refresh/Reset dataset button -->
                    <button class="sidebar-action-btn sidebar-reset-btn" onclick="confirmResetDataset()" title="Reset dataset">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>
                    </button>
                    <!-- Logout button -->
                    <button class="sidebar-action-btn sidebar-logout-btn" onclick="openLogoutModal()" title="Logout">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                        Logout
                    </button>
                </div>
                <!-- Dark mode toggle -->
                <button class="theme-toggle-btn" onclick="toggleTheme()" id="themeToggleBtn" title="Toggle dark/light mode">
                    <span class="toggle-icon" id="themeIcon">🌙</span>
                    <span class="toggle-label" id="themeLabel">Dark Mode</span>
                    <div class="toggle-track"><div class="toggle-thumb"></div></div>
                </button>
            </div>

        </div>
    </aside>
    <!-- ===================== END SIDEBAR ===================== -->

    <!-- MAIN CONTENT -->
    <main class="content">
        <div class="page-header">
            <h1>Pipeline: Ingestion & Preparation</h1>
            <p>Load your sales data, run data quality checks, engineer features, and execute the full forecasting pipeline.</p>
        </div>

        <!-- WELCOME CARD -->
        <div class="pipeline-welcome-card">
            <div class="pipeline-welcome-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="#1c4aa0" stroke-width="1.5">
                    <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                </svg>
            </div>
            <h2 class="pipeline-welcome-title">Welcome to the Sales Forecasting Dashboard</h2>
            <p class="pipeline-welcome-sub">
                This dashboard operationalizes the thesis pipeline end-to-end: data preparation → MSTL seasonality →
                LightGBM forecasting → MinT coherence → C2G growth drivers → stock prescriptions.
            </p>

            <div class="pipeline-cards-grid">
                <!-- UPLOAD CSV -->
                <div class="pipeline-data-card">
                    <div class="pipeline-data-card-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#888" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    </div>
                    <h3 class="pipeline-data-card-title">Upload Your CSV</h3>
                    <p class="pipeline-data-card-desc">Expected schema: Date | Store_ID | Cluster | Region | SKU | Brand | Category | Sales_Qty</p>
                    <div class="pipeline-upload-area">
                        <input type="file" id="csvFile" class="upload-input" accept=".csv">
                        <label for="csvFile" class="pipeline-choose-btn">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                            Choose File
                        </label>
                        <p class="pipeline-upload-note">(Upload functionality is a UI mock)</p>
                    </div>
                </div>

                <!-- USE SAMPLE DATA -->
                <div class="pipeline-data-card pipeline-data-card--active">
                    <div class="pipeline-data-card-icon">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#1c4aa0" stroke-width="1.5">
                            <ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>
                        </svg>
                    </div>
                    <h3 class="pipeline-data-card-title">Use Sample Data</h3>
                    <p class="pipeline-data-card-desc">Pre-loaded Nestlé Philippines sales data across Milk, Coffee, Food categories with realistic seasonality.</p>
                    <button class="load-sample-btn" id="loadSampleBtn" onclick="loadSampleData()">Load Sample Dataset</button>
                    <?php if ($user_dataset_loaded): ?>
                    <p class="pipeline-sample-note">Sample data is already loaded in the database</p>
                    <?php endif; ?>
                    <?php if ($user_pipeline_executed): ?>
                    <p class="pipeline-sample-note" style="color:#28a745;font-weight:600;">✓ Pipeline complete — all modules unlocked</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- DATA INGESTION SUMMARY — shown after dataset loaded -->
        <div class="pipe-section" id="sectionIngestionSummary" style="display:none;">
            <h3 class="pipe-section-title">Data Ingestion Summary</h3>
            <div class="ingestion-summary-grid">
                <div class="ingestion-meta-item">
                    <div class="ingestion-meta-label">File Name</div>
                    <div class="ingestion-meta-value">sample_nestle_southstar_data.csv</div>
                </div>
                <div class="ingestion-meta-item">
                    <div class="ingestion-meta-label">Total Rows</div>
                    <div class="ingestion-meta-value">73,080</div>
                </div>
                <div class="ingestion-meta-item">
                    <div class="ingestion-meta-label">Date Range</div>
                    <div class="ingestion-meta-value">2024-01-01 to 2025-08-31</div>
                </div>
                <div class="ingestion-meta-item">
                    <div class="ingestion-meta-label">Schema Validation</div>
                    <div class="ingestion-meta-value ingestion-pass">✓ PASS</div>
                </div>
            </div>
            <a href="#" class="show-preview-link" onclick="return false;">Show Data Preview</a>
        </div>

        <!-- DATA QUALITY SUMMARY — shown after dataset loaded -->
        <div class="pipe-section" id="sectionQualitySummary" style="display:none;">
            <div class="pipe-section-header">
                <h3 class="pipe-section-title">Data Quality Summary</h3>
                <span class="pipe-pass-badge">PASS</span>
            </div>
            <div class="quality-metrics-grid">
                <div class="quality-metric"><div class="qm-value">0</div><div class="qm-label">Missing Values</div></div>
                <div class="quality-metric"><div class="qm-value">0</div><div class="qm-label">Duplicates</div></div>
                <div class="quality-metric"><div class="qm-value">0</div><div class="qm-label">Invalid Qty</div></div>
                <div class="quality-metric"><div class="qm-value">0</div><div class="qm-label">Date Gaps</div></div>
                <div class="quality-metric"><div class="qm-value">0</div><div class="qm-label">Outliers</div></div>
            </div>
        </div>

        <!-- PIPELINE EXECUTION — shown after dataset loaded -->
        <div class="pipe-section" id="sectionPipelineExec" style="display:none;">
            <div class="pipe-section-header">
                <h3 class="pipe-section-title">Pipeline Execution</h3>
                <button class="run-pipeline-btn" id="runPipelineBtn" onclick="runPipeline()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    Run Pipeline
                </button>
            </div>

            <!-- Stage rows — pending state by default -->
            <div class="pipeline-stage-list" id="pipelineStageList">
                <div class="pipeline-stage" id="stage-ingestion" data-stage="0">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-ingestion"></span>
                        <span class="stage-name">Ingestion</span>
                        <span class="stage-duration" id="dur-ingestion"></span>
                    </div>
                    <div class="stage-body" id="body-ingestion" style="display:none;">
                        <div class="stage-rowcount">Rows: 0 in → 73,080 out</div>
                        <pre class="stage-log" id="log-ingestion"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-cleaning" data-stage="1">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-cleaning"></span>
                        <span class="stage-name">Cleaning</span>
                        <span class="stage-duration" id="dur-cleaning"></span>
                    </div>
                    <div class="stage-body" id="body-cleaning" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 72,861 out</div>
                        <pre class="stage-log" id="log-cleaning"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-features" data-stage="2">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-features"></span>
                        <span class="stage-name">Feature Engineering</span>
                        <span class="stage-duration" id="dur-features"></span>
                    </div>
                    <div class="stage-body" id="body-features" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 73,080 out</div>
                        <pre class="stage-log" id="log-features"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-mstl" data-stage="3">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-mstl"></span>
                        <span class="stage-name">MSTL Decomposition</span>
                        <span class="stage-duration" id="dur-mstl"></span>
                    </div>
                    <div class="stage-body" id="body-mstl" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 73,080 out</div>
                        <pre class="stage-log" id="log-mstl"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-lightgbm" data-stage="4">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-lightgbm"></span>
                        <span class="stage-name">LightGBM Forecast</span>
                        <span class="stage-duration" id="dur-lightgbm"></span>
                    </div>
                    <div class="stage-body" id="body-lightgbm" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 73,080 out</div>
                        <pre class="stage-log" id="log-lightgbm"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-mint" data-stage="5">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-mint"></span>
                        <span class="stage-name">MinT Reconciliation</span>
                        <span class="stage-duration" id="dur-mint"></span>
                    </div>
                    <div class="stage-body" id="body-mint" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 73,080 out</div>
                        <pre class="stage-log" id="log-mint"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-c2g" data-stage="6">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-c2g"></span>
                        <span class="stage-name">C2G Attribution</span>
                        <span class="stage-duration" id="dur-c2g"></span>
                    </div>
                    <div class="stage-body" id="body-c2g" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 73,080 out</div>
                        <pre class="stage-log" id="log-c2g"></pre>
                    </div>
                </div>
                <div class="pipeline-stage" id="stage-prescriptions" data-stage="7">
                    <div class="stage-header">
                        <span class="stage-dot" id="dot-prescriptions"></span>
                        <span class="stage-name">Prescriptions</span>
                        <span class="stage-duration" id="dur-prescriptions"></span>
                    </div>
                    <div class="stage-body" id="body-prescriptions" style="display:none;">
                        <div class="stage-rowcount">Rows: 73,080 in → 60 out</div>
                        <pre class="stage-log" id="log-prescriptions"></pre>
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
            <a href="/auth/logout.php" class="confirm-btn">Yes, Logout</a>
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
const DATASET_LOADED = <?php echo $user_dataset_loaded ? 'true' : 'false'; ?>;
window.UNLOCKED = UNLOCKED;
window.DATASET_LOADED = DATASET_LOADED;

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
        fetch('reset_dataset.php', {
            method: 'POST',
            credentials: 'same-origin'
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert('Error resetting dataset: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(() => alert('Network error. Please try again.'));
    }
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

function loadSampleData() {
    const btn = document.getElementById('loadSampleBtn');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Loading...'; }

    fetch('load_dataset.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin'
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            if (btn) {
                btn.textContent = '✓ Dataset Loaded — Run Pipeline to Unlock';
                btn.style.background = '#28a745';
            }
            // Show post-load sections (pipeline still needs to run to unlock)
            showPostLoadUI();
        } else {
            if (btn) { btn.disabled = false; btn.textContent = 'Load Sample Dataset'; }
            alert('Error: ' + (data.message || 'Could not load dataset.'));
        }
    })
    .catch(err => {
        if (btn) { btn.disabled = false; btn.textContent = 'Load Sample Dataset'; }
        alert('Network error — please try again.');
        console.error(err);
    });
}

function showPostLoadUI() {
    document.querySelector('.pipeline-welcome-card').style.display = 'none';
    document.getElementById('sectionIngestionSummary').style.display = 'block';
    document.getElementById('sectionQualitySummary').style.display  = 'block';
    document.getElementById('sectionPipelineExec').style.display    = 'block';
}

/* =========================
   PIPELINE SIMULATION
========================= */
const PIPELINE_STAGES = [
    {
        id: 'ingestion',
        duration: '1348ms',
        logs: [
            'Starting Ingestion...',
            '✓ Loaded 73080 rows',
            '✓ Date range: 2024-01-01 to 2025-08-31',
            '✓ Schema validation passed'
        ]
    },
    {
        id: 'cleaning',
        duration: '1032ms',
        logs: [
            'Starting Cleaning...',
            '✓ Checked 73080 rows',
            '✓ Removed 146 duplicates',
            '✓ Removed 73 invalid quantities',
            '✓ No missing values detected',
            '✓ No date gaps found'
        ]
    },
    {
        id: 'features',
        duration: '968ms',
        logs: [
            'Starting Feature Engineering...',
            '✓ Created lag features (7d, 30d)',
            '✓ Created rolling averages (7d, 30d)',
            '✓ Added calendar features (BER_flag)',
            '✓ Categorical encoding prepared'
        ]
    },
    {
        id: 'mstl',
        duration: '830ms',
        logs: [
            'Starting MSTL Decomposition...',
            '✓ MSTL decomposition completed',
            '✓ Extracted trend component',
            '✓ Extracted seasonal indices (monthly)',
            '✓ Computed seasonality profiles by Category/Region'
        ]
    },
    {
        id: 'lightgbm',
        duration: '945ms',
        logs: [
            'Starting LightGBM Forecast...',
            '✓ Trained LightGBM global model',
            '✓ Feature importance: lag_30 (0.32), rolling_mean_30 (0.28)',
            '✓ Generated base forecasts (6-month horizon)',
            '✓ Validation MAPE: 8.4%'
        ]
    },
    {
        id: 'mint',
        duration: '1307ms',
        logs: [
            'Starting MinT Reconciliation...',
            '✓ Built aggregation hierarchy (SKU→Brand→Category)',
            '✓ Built geographic hierarchy (Store→Cluster→Region→National)',
            '✓ MinT reconciliation completed',
            '✓ Coherence check: PASS (bottom sums = reconciled totals)'
        ]
    },
    {
        id: 'c2g',
        duration: '1410ms',
        logs: [
            'Starting C2G Attribution...',
            '✓ Calculated additive contributions to trend growth',
            '✓ Attributed by Region, Cluster, Product',
            '✓ Contributions sum to 100%',
            '✓ Top driver: GMA (+28.3% of growth)'
        ]
    },
    {
        id: 'prescriptions',
        duration: '2014ms',
        logs: [
            'Starting Prescriptions...',
            '✓ Generated stock allocation rules',
            '✓ Actions: 18 Expand, 35 Maintain, 7 De-prioritize',
            '✓ Prioritized by Trend + Forecast + C2G',
            '✓ Recommendations ready for export'
        ]
    }
];

function runPipeline() {
    const btn = document.getElementById('runPipelineBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><polygon points="5 3 19 12 5 21 5 3"/></svg> Running...';

    // Reset all stages
    PIPELINE_STAGES.forEach(stage => {
        const dot  = document.getElementById('dot-' + stage.id);
        const body = document.getElementById('body-' + stage.id);
        const dur  = document.getElementById('dur-' + stage.id);
        const log  = document.getElementById('log-' + stage.id);
        const el   = document.getElementById('stage-' + stage.id);
        if (dot)  { dot.className = 'stage-dot'; dot.textContent = ''; }
        if (body) body.style.display = 'none';
        if (dur)  dur.textContent = '';
        if (log)  log.textContent = '';
        if (el)   { el.classList.remove('stage-running', 'stage-complete'); }
    });

    let delay = 0;
    PIPELINE_STAGES.forEach((stage, i) => {
        // Parse ms from duration string for the actual delay
        const ms = parseInt(stage.duration);

        setTimeout(() => {
            // Mark as running
            const el  = document.getElementById('stage-' + stage.id);
            const dot = document.getElementById('dot-' + stage.id);
            if (el)  el.classList.add('stage-running');
            if (dot) dot.className = 'stage-dot running';

            // After the stage "runs", mark complete and type logs
            setTimeout(() => {
                if (el)  { el.classList.remove('stage-running'); el.classList.add('stage-complete'); }
                if (dot) { dot.className = 'stage-dot complete'; }

                const dur  = document.getElementById('dur-' + stage.id);
                const body = document.getElementById('body-' + stage.id);
                const log  = document.getElementById('log-' + stage.id);
                if (dur)  dur.textContent = stage.duration;
                if (body) body.style.display = 'block';

                // Type-in log lines with small stagger
                if (log) {
                    log.textContent = '';
                    stage.logs.forEach((line, li) => {
                        setTimeout(() => {
                            log.textContent += (li > 0 ? '\n' : '') + line;
                        }, li * 80);
                    });
                }

                // If last stage, mark pipeline complete
                if (i === PIPELINE_STAGES.length - 1) {
                    setTimeout(() => {
                        btn.disabled = false;
                        btn.className = 'run-pipeline-btn complete';
                        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Pipeline Complete';

                        // Persist to DB + unlock nav immediately
                        fetch('run_pipeline.php', {
                            method: 'POST',
                            credentials: 'same-origin'
                        })
                        .then(res => res.json())
                        .then(data => {
                            if (data.success) {
                                // Unlock all analytics nav links
                                document.querySelectorAll('.sidebar-link[data-locked]').forEach(link => {
                                    link.classList.remove('locked');
                                    link.removeAttribute('tabindex');
                                });
                                // Remove locked banner
                                const banner = document.querySelector('.analytics-locked-banner');
                                if (banner) banner.remove();
                            }
                        })
                        .catch(err => console.error('Pipeline persist error:', err));

                    }, stage.logs.length * 80 + 200);
                }
            }, ms);

        }, delay);

        delay += ms + 300; // stage duration + 300ms buffer between stages
    });
}

    // On page load: restore state from DB flags
    (function() {
        // If dataset loaded (with or without pipeline run), show post-load UI
        if (DATASET_LOADED || UNLOCKED) {
            showPostLoadUI();
            const btn = document.getElementById('loadSampleBtn');
            if (btn) {
                btn.disabled = true;
                btn.style.background = '#28a745';
                btn.textContent = UNLOCKED
                    ? '✓ Dataset Loaded — Pipeline Complete'
                    : '✓ Dataset Loaded — Run Pipeline to Unlock';
            }
        }

        // If pipeline was already run, restore all stage rows as complete
        if (UNLOCKED) {
            PIPELINE_STAGES.forEach(stage => {
                const el   = document.getElementById('stage-' + stage.id);
                const dot  = document.getElementById('dot-' + stage.id);
                const body = document.getElementById('body-' + stage.id);
                const dur  = document.getElementById('dur-' + stage.id);
                const log  = document.getElementById('log-' + stage.id);
                if (el)   { el.classList.remove('stage-running'); el.classList.add('stage-complete'); }
                if (dot)  { dot.className = 'stage-dot complete'; }
                if (body) body.style.display = 'block';
                if (dur)  dur.textContent = stage.duration;
                if (log)  log.textContent = stage.logs.join('\n');
            });
            // Mark button as complete
            const btn = document.getElementById('runPipelineBtn');
            if (btn) {
                btn.disabled = false;
                btn.className = 'run-pipeline-btn complete';
                btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Pipeline Complete';
            }
        }
    })();

</script>

</body>
</html>
