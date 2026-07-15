<?php
/**
 * Amber Help Page Template
 *
 * Rendered by Amber\Core\HelpPage::render().
 * This file was extracted from Plugin::renderHelpPage() to keep
 * the Plugin class focused on lifecycle orchestration.
 *
 * @package Amber
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}
?>
<style>
            /* ── Reset WP admin interference inside our page ── */
            .amber-help-page * { box-sizing: border-box; }
            .amber-help-page a { color: inherit; }

            /* ── Scoped design tokens ── */
            .amber-help-page {
                --bg: #FAFAF8;
                --surface: #FFFFFF;
                --border: #E8E5E0;
                --text: #1A1A18;
                --text-secondary: #6B6860;
                --accent: #D97706;
                --accent-bg: #FEF3C7;
                --accent-dark: #B45309;
                --info: #1D4ED8;
                --info-bg: #DBEAFE;
                --danger: #DC2626;
                --danger-bg: #FEE2E2;
                --success: #16A34A;
                --success-bg: #DCFCE7;
                --purple: #7C3AED;
                --purple-bg: #EDE9FE;
                --code-bg: #F5F3EF;
                --nav-width: 260px;
                --header-height: 56px;
                --radius: 10px;
                --shadow-sm: 0 1px 3px rgba(0,0,0,0.04);
                --shadow-md: 0 4px 12px rgba(0,0,0,0.06);
                font-family: 'DM Sans', sans-serif;
                font-size: 16px;
                line-height: 1.7;
                color: var(--text);
                background: var(--bg);
                -webkit-font-smoothing: antialiased;
                /* Full-bleed: escape WP's #wpcontent padding */
                margin: -10px -20px -10px -20px;
                min-height: calc(100vh - 32px);
            }

            /* ── Inner header ── */
            .amber-help-page .ahp-header {
                position: sticky; top: 32px; z-index: 100;
                height: var(--header-height);
                background: rgba(250,250,248,0.95);
                backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
                border-bottom: 1px solid var(--border);
                display: flex; align-items: center; padding: 0 24px; gap: 16px;
            }
            .amber-help-page .ahp-header .logo {
                display: flex; align-items: center; gap: 10px;
                font-weight: 700; font-size: 20px; color: var(--text);
                text-decoration: none; letter-spacing: -0.3px;
            }
            .amber-help-page .ahp-header .logo span { color: var(--accent); }
            .amber-help-page .ahp-header .version {
                font-size: 12px; font-weight: 500; color: var(--text-secondary);
                background: var(--code-bg); padding: 2px 8px; border-radius: 6px;
            }
            .amber-help-page .ahp-hamburger {
                display: none; background: none; border: none; cursor: pointer;
                width: 36px; height: 36px; flex-shrink: 0;
                border-radius: 8px; align-items: center; justify-content: center;
            }
            .amber-help-page .ahp-hamburger:hover { background: var(--code-bg); }
            .amber-help-page .ahp-hamburger svg { width: 22px; height: 22px; color: var(--text); }

            /* ── Layout ── */
            .amber-help-page .ahp-body {
                display: flex;
            }

            /* ── Sidebar ── */
            .amber-help-page .ahp-sidebar {
                position: sticky;
                top: calc(32px + var(--header-height));
                height: calc(100vh - 32px - var(--header-height));
                width: var(--nav-width);
                overflow-y: auto;
                border-right: 1px solid var(--border);
                background: var(--bg);
                padding: 24px 0;
                flex-shrink: 0;
            }
            .amber-help-page .ahp-sidebar::-webkit-scrollbar { width: 4px; }
            .amber-help-page .ahp-sidebar::-webkit-scrollbar-thumb { background: var(--border); border-radius: 4px; }
            .amber-help-page .nav-section { padding: 0 20px; margin-bottom: 24px; }
            .amber-help-page .nav-section-title {
                font-size: 11px; font-weight: 600; text-transform: uppercase;
                letter-spacing: 1px; color: var(--text-secondary); margin-bottom: 8px; padding-left: 12px;
            }
            .amber-help-page .nav-link {
                display: block; padding: 6px 12px; border-radius: 8px;
                color: var(--text-secondary); text-decoration: none;
                font-size: 14px; font-weight: 400; transition: all 0.15s; line-height: 1.5;
            }
            .amber-help-page .nav-link:hover { color: var(--text); background: var(--surface); }
            .amber-help-page .nav-link.active { color: var(--accent-dark); background: var(--accent-bg); font-weight: 500; }

            /* ── Main content ── */
            .amber-help-page .ahp-main {
                flex: 1;
                padding: 40px 48px 80px;
                max-width: 860px;
            }

            /* ── Typography ── */
            .amber-help-page h1 { font-size: 36px; font-weight: 700; letter-spacing: -0.8px; line-height: 1.2; margin-bottom: 12px; margin-top: 0; padding: 0; border: none; color: var(--text); }
            .amber-help-page h1 .emoji { font-size: 32px; margin-right: 4px; }
            .amber-help-page .subtitle { font-size: 18px; color: var(--text-secondary); font-weight: 400; margin-bottom: 40px; line-height: 1.6; }
            .amber-help-page h2 {
                font-size: 24px; font-weight: 700; letter-spacing: -0.4px;
                margin-top: 56px; margin-bottom: 16px; padding-top: 24px;
                border-top: 1px solid var(--border); color: var(--text);
            }
            .amber-help-page h2:first-of-type { border-top: none; margin-top: 0; padding-top: 0; }
            .amber-help-page h3 { font-size: 18px; font-weight: 600; margin-top: 32px; margin-bottom: 10px; color: var(--text); }
            .amber-help-page p { margin-bottom: 16px; }
            .amber-help-page ul, .amber-help-page ol { margin-bottom: 16px; padding-left: 24px; }
            .amber-help-page li { margin-bottom: 6px; }

            /* ── Cards ── */
            .amber-help-page .card {
                background: var(--surface); border: 1px solid var(--border);
                border-radius: var(--radius); padding: 24px; margin-bottom: 24px;
                box-shadow: var(--shadow-sm);
            }
            .amber-help-page .card h3 { margin-top: 0; }
            .amber-help-page .card p:last-child { margin-bottom: 0; }

            /* ── Callouts ── */
            .amber-help-page .callout {
                border-radius: var(--radius); padding: 16px 20px; margin-bottom: 24px;
                font-size: 15px; display: flex; gap: 12px; align-items: flex-start; line-height: 1.6;
            }
            .amber-help-page .callout-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
            .amber-help-page .callout.info    { background: var(--info-bg);    border-left: 4px solid var(--info); }
            .amber-help-page .callout.warning { background: var(--accent-bg);  border-left: 4px solid var(--accent); }
            .amber-help-page .callout.danger  { background: var(--danger-bg);  border-left: 4px solid var(--danger); }
            .amber-help-page .callout.success { background: var(--success-bg); border-left: 4px solid var(--success); }
            .amber-help-page .callout.purple  { background: var(--purple-bg);  border-left: 4px solid var(--purple); }

            /* ── Code ── */
            .amber-help-page code {
                font-family: 'JetBrains Mono', monospace; font-size: 13.5px;
                background: var(--code-bg); padding: 2px 7px; border-radius: 5px; color: var(--accent-dark);
            }

            /* ── Steps ── */
            .amber-help-page .steps { counter-reset: step; list-style: none; padding-left: 0; }
            .amber-help-page .steps li {
                counter-increment: step; padding-left: 44px; position: relative;
                margin-bottom: 20px; min-height: 32px;
            }
            .amber-help-page .steps li::before {
                content: counter(step); position: absolute; left: 0; top: 0;
                width: 30px; height: 30px; border-radius: 50%;
                background: var(--accent); color: #fff;
                font-weight: 700; font-size: 14px;
                display: flex; align-items: center; justify-content: center;
            }

            /* ── Path / breadcrumb ── */
            .amber-help-page .path {
                display: inline-flex; align-items: center; flex-wrap: wrap; gap: 4px;
                background: var(--code-bg); border: 1px solid var(--border);
                border-radius: 8px; padding: 8px 14px; margin-bottom: 20px;
                font-size: 14px; font-weight: 500;
            }
            .amber-help-page .path .crumb { color: var(--text); }
            .amber-help-page .path .sep { color: var(--text-secondary); margin: 0 2px; }
            .amber-help-page .path .crumb.final { color: var(--accent-dark); font-weight: 600; }

            /* ── Field table ── */
            .amber-help-page .field-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 15px; }
            .amber-help-page .field-table th {
                text-align: left; padding: 10px 16px; font-weight: 600;
                border-bottom: 2px solid var(--border); font-size: 13px;
                text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary);
            }
            .amber-help-page .field-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: top; }
            .amber-help-page .field-table tr:last-child td { border-bottom: none; }
            .amber-help-page .field-table .field-name { font-family: 'JetBrains Mono', monospace; font-size: 13px; color: var(--accent-dark); font-weight: 500; white-space: nowrap; }
            .amber-help-page .field-table .req { color: var(--danger); font-size: 12px; font-weight: 700; margin-left: 4px; }

            /* ── Badges ── */
            .amber-help-page .badge {
                display: inline-block; padding: 2px 10px; border-radius: 20px;
                font-size: 13px; font-weight: 600; white-space: nowrap;
            }
            .amber-help-page .badge-filled  { background: var(--success-bg); color: var(--success); }
            .amber-help-page .badge-soon    { background: var(--accent-bg);  color: var(--accent-dark); }
            .amber-help-page .badge-due     { background: var(--danger-bg);  color: var(--danger); }
            .amber-help-page .badge-overdue { background: var(--danger-bg);  color: var(--danger); }
            .amber-help-page .badge-vacant  { background: #F3F4F6; color: #6B7280; border: 1px dashed #D1D5DB; }
            .amber-help-page .badge-norot   { background: #F3F4F6; color: #6B7280; }
            .amber-help-page .badge-matched { background: var(--success-bg); color: var(--success); }
            .amber-help-page .badge-partial { background: var(--accent-bg);  color: var(--accent-dark); }
            .amber-help-page .badge-possible{ background: var(--info-bg);    color: var(--info); }
            .amber-help-page .badge-missing { background: var(--danger-bg);  color: var(--danger); }
            .amber-help-page .badge-admin   { background: var(--purple-bg);  color: var(--purple); }

            /* ── Status table ── */
            .amber-help-page .status-table { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 15px; }
            .amber-help-page .status-table th {
                text-align: left; padding: 10px 16px; font-weight: 600;
                border-bottom: 2px solid var(--border); font-size: 13px;
                text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary);
            }
            .amber-help-page .status-table td { padding: 10px 16px; border-bottom: 1px solid var(--border); vertical-align: top; }
            .amber-help-page .status-table tr:last-child td { border-bottom: none; }

            /* ── Screen mock ── */
            .amber-help-page .screen-mock {
                background: var(--surface); border: 1px solid var(--border);
                border-radius: var(--radius); overflow: hidden;
                margin-bottom: 24px; box-shadow: var(--shadow-sm);
            }
            .amber-help-page .screen-mock .mock-bar {
                background: #1D2327; padding: 10px 16px;
                display: flex; align-items: center; gap: 10px;
            }
            .amber-help-page .screen-mock .mock-bar .dot { width: 10px; height: 10px; border-radius: 50%; }
            .amber-help-page .screen-mock .mock-bar .dot.r { background: #FF5F57; }
            .amber-help-page .screen-mock .mock-bar .dot.y { background: #FFBD2E; }
            .amber-help-page .screen-mock .mock-bar .dot.g { background: #28C940; }
            .amber-help-page .screen-mock .mock-bar .url {
                flex: 1; background: #2D3748; border-radius: 4px;
                padding: 4px 10px; font-size: 12px; font-family: 'JetBrains Mono', monospace;
                color: #A0AEC0;
            }
            .amber-help-page .screen-mock .mock-body { display: flex; min-height: 200px; }
            .amber-help-page .screen-mock .mock-sidebar {
                width: 180px; background: #F8F8F8; border-right: 1px solid var(--border);
                padding: 16px 0; flex-shrink: 0;
            }
            .amber-help-page .screen-mock .mock-sidebar .mock-menu-item {
                padding: 6px 16px; font-size: 13px; color: var(--text-secondary);
                display: flex; align-items: center; gap: 8px;
            }
            .amber-help-page .screen-mock .mock-sidebar .mock-menu-item.active {
                background: var(--accent-bg); color: var(--accent-dark); font-weight: 600;
                border-left: 3px solid var(--accent);
            }
            .amber-help-page .screen-mock .mock-sidebar .mock-menu-item.parent {
                font-weight: 600; color: var(--text); font-size: 13px;
            }
            .amber-help-page .screen-mock .mock-sidebar .mock-sub-item {
                padding: 5px 16px 5px 32px; font-size: 12px; color: var(--text-secondary);
            }
            .amber-help-page .screen-mock .mock-sidebar .mock-sub-item.active {
                color: var(--accent-dark); font-weight: 500;
            }
            .amber-help-page .screen-mock .mock-content { flex: 1; padding: 20px 24px; }
            .amber-help-page .screen-mock .mock-content .mock-title {
                font-size: 20px; font-weight: 700; margin-bottom: 12px; color: var(--text);
            }
            .amber-help-page .screen-mock .mock-content .mock-button {
                display: inline-block; background: #2271B1; color: #fff;
                padding: 6px 14px; border-radius: 4px; font-size: 13px; font-weight: 500;
                margin-bottom: 16px;
            }
            .amber-help-page .screen-mock .mock-content .mock-row {
                display: flex; gap: 8px; align-items: center;
                padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13px;
            }
            .amber-help-page .screen-mock .mock-content .mock-row:last-child { border-bottom: none; }
            .amber-help-page .screen-mock .mock-content .mock-row .mock-link { color: #2271B1; font-weight: 500; }
            .amber-help-page .screen-mock .mock-content .mock-row .mock-meta { color: var(--text-secondary); font-size: 12px; }
            .amber-help-page .screen-mock .mock-content .mock-field-label {
                font-size: 11px; font-weight: 600; text-transform: uppercase;
                letter-spacing: 0.5px; color: var(--text-secondary); margin-bottom: 4px; margin-top: 12px;
            }
            .amber-help-page .screen-mock .mock-content .mock-input {
                background: #fff; border: 1px solid var(--border); border-radius: 4px;
                padding: 6px 10px; font-size: 13px; color: var(--text); width: 100%; max-width: 320px;
            }
            .amber-help-page .screen-mock .mock-content .mock-input.error { border-color: var(--danger); }
            .amber-help-page .screen-mock .mock-content .mock-error-msg { color: var(--danger); font-size: 12px; margin-top: 4px; }

            /* ── Responsive ── */
            @media (max-width: 860px) {
                .amber-help-page .ahp-sidebar {
                    position: fixed; top: 0; left: 0; bottom: 0; z-index: 200;
                    transform: translateX(-100%); transition: transform 0.25s ease;
                    box-shadow: var(--shadow-md);
                }
                .amber-help-page .ahp-sidebar.open { transform: translateX(0); }
                .amber-help-page .ahp-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 199; }
                .amber-help-page .ahp-overlay.visible { display: block; }
                .amber-help-page .ahp-hamburger { display: flex; }
                .amber-help-page .ahp-main { padding: 24px 20px 60px; }
                .amber-help-page h1 { font-size: 28px; }
                .amber-help-page h2 { font-size: 20px; }
                .amber-help-page .screen-mock .mock-body { flex-direction: column; }
                .amber-help-page .screen-mock .mock-sidebar { width: 100%; border-right: none; border-bottom: 1px solid var(--border); }
            }

            @media print {
                .amber-help-page .ahp-header,
                .amber-help-page .ahp-sidebar,
                .amber-help-page .ahp-overlay { display: none !important; }
                .amber-help-page .ahp-main { padding: 20px; }
            }
        </style>

        <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

        <div class="amber-help-page">

            <header class="ahp-header">
                <button class="ahp-hamburger" id="ahp-toggle" aria-label="Toggle navigation">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <a href="#" class="logo">
                    <span>Intergroup Admin</span>
                </a>
                <span class="version">v1.23.21 · Bristol &amp; District Intergroup</span>
            </header>

            <div class="ahp-body">

                <div class="ahp-overlay" id="ahp-overlay"></div>

                <nav class="ahp-sidebar" id="ahp-sidebar">
                    <div class="nav-section">
                        <div class="nav-section-title">Getting Started</div>
                        <a href="#ahp-overview" class="nav-link active">Overview</a>
                        <a href="#ahp-logging-in" class="nav-link">Logging In</a>
                        <a href="#ahp-menu-structure" class="nav-link">Menu Structure</a>
                        <a href="#ahp-dashboard" class="nav-link">Dashboard Widgets</a>
                    </div>
                    <div class="nav-section">
                        <div class="nav-section-title">Intergroup Meetings</div>
                        <a href="#ahp-ig-create" class="nav-link">Creating a Meeting</a>
                        <a href="#ahp-ig-attendance" class="nav-link">Recording Attendance</a>
                        <a href="#ahp-ig-view-attendance" class="nav-link">Viewing Attendance</a>
                    </div>
                    <div class="nav-section">
                        <div class="nav-section-title">Members</div>
                        <a href="#ahp-member-create" class="nav-link">Adding a Member</a>
                        <a href="#ahp-member-edit" class="nav-link">Editing a Member</a>
                        <a href="#ahp-member-contacts" class="nav-link">Updating Contacts</a>
                        <a href="#ahp-member-gsr" class="nav-link">Setting GSR Status</a>
                        <a href="#ahp-member-responder" class="nav-link">Telephone Responder</a>
                        <a href="#ahp-member-12th" class="nav-link">12th Stepper</a>
                    </div>
                    <div class="nav-section">
                        <div class="nav-section-title">Positions</div>
                        <a href="#ahp-position-create" class="nav-link">Creating a Position</a>
                        <a href="#ahp-position-assign" class="nav-link">Assigning a Member</a>
                        <a href="#ahp-position-vacant" class="nav-link">Marking as Vacant</a>
                        <a href="#ahp-position-dashboard" class="nav-link">Rotation Badges</a>
                    </div>
                    <div class="nav-section">
                        <div class="nav-section-title">Groups &amp; Meetings</div>
                        <a href="#ahp-group-create" class="nav-link">Adding a Group</a>
                        <a href="#ahp-group-contacts" class="nav-link">Group Contact Details</a>
                        <a href="#ahp-meeting-create" class="nav-link">Adding a Meeting</a>
                        <a href="#ahp-meeting-reconcile" class="nav-link">Reconciliation Badges</a>
                    </div>
                    <div class="nav-section">
                        <div class="nav-section-title">Reference</div>
                        <a href="#ahp-shortcodes" class="nav-link">Shortcodes</a>
                        <a href="#ahp-troubleshooting" class="nav-link">Troubleshooting</a>
                    </div>
                </nav>

                <main class="ahp-main">

                    <section id="ahp-overview">
                        <h1>Intergroup Admin</h1>
                        <p class="subtitle">Step-by-step directions for every task in the WordPress admin — managing intergroup meetings, members, positions, groups, and the public directory.</p>
                        <div class="card">
                            <h3>What is Amber?</h3>
                            <p style="margin-bottom:0;">Everything you need to run Bristol &amp; District Intergroup positions, members, group meetings, and intergroup business meeting records.</p>
                        </div>
                        <div class="callout warning">
                            <span class="callout-icon">⚠️</span>
                            <div><strong>GDPR reminder.</strong> The Scrutiny plugin logs every access to personal member data. Use only the information you need, for the purpose you need it for. Do not share personal details held in the system outside of it.</div>
                        </div>
                    </section>

                    <section id="ahp-logging-in">
                        <h2>Logging In</h2>
                        <ol class="steps">
                            <li>Go to your site's WordPress login page — typically <code>yoursite.org/wp-admin</code>.</li>
                            <li>Enter your admin username and password.</li>
                            <li>Click <strong>Log In</strong>. You will land on the WordPress Dashboard.</li>
                            <li>Look for the <strong>Intergroup</strong> menu item in the left-hand sidebar. If you cannot see it, your account may not have the required permissions — contact the site administrator.</li>
                        </ol>
                        <div class="callout info">
                            <span class="callout-icon">💡</span>
                            <div>The admin URL and your login credentials are separate from the public-facing website. Your credentials should be kept private and not shared.</div>
                        </div>
                    </section>

                    <section id="ahp-menu-structure">
                        <h2>Menu Structure</h2>
                        <p>The <strong>Intergroup</strong> top-level menu contains four sub-pages:</p>
                        <div class="screen-mock">
                            <div class="mock-bar">
                                <div class="dot r"></div><div class="dot y"></div><div class="dot g"></div>
                                <div class="url">yoursite.org/wp-admin/</div>
                            </div>
                            <div class="mock-body">
                                <div class="mock-sidebar">
                                    <div class="mock-menu-item" style="margin-bottom:4px;">🏠 Dashboard</div>
                                    <div class="mock-menu-item parent">🟠 Intergroup</div>
                                    <div class="mock-sub-item active">Positions</div>
                                    <div class="mock-sub-item">Members</div>
                                    <div class="mock-sub-item">Groups / Meetings</div>
                                    <div class="mock-sub-item">Intergroup Meetings</div>
                                    <div class="mock-sub-item">Attendance</div>
                                    <div class="mock-menu-item" style="margin-top:8px;">📝 Posts</div>
                                    <div class="mock-menu-item">📄 Pages</div>
                                </div>
                                <div class="mock-content">
                                    <div class="mock-title">Positions</div>
                                    <div class="mock-row"><span class="mock-link">Intergroup Chair</span><span class="mock-meta">Sarah T. · Filled · 8 months remaining</span></div>
                                    <div class="mock-row"><span class="mock-link">Intergroup Secretary</span><span class="mock-meta">James R. · Soon · 2 months remaining</span></div>
                                    <div class="mock-row"><span class="mock-link">Health Liaison Officer</span><span class="mock-meta">— · Vacant</span></div>
                                    <div class="mock-row"><span class="mock-link">Treasurer</span><span class="mock-meta">Helen W. · Filled · 14 months remaining</span></div>
                                </div>
                            </div>
                        </div>
                        <table class="status-table">
                            <thead><tr><th>Sub-page</th><th>What it contains</th></tr></thead>
                            <tbody>
                            <tr><td><strong>Positions</strong></td><td>All intergroup service roles. Shows current holder, rotation status, and position email.</td></tr>
                            <tr><td><strong>Members</strong></td><td>All registered intergroup members and GSRs, with anonymous names and home group assignments.</td></tr>
                            <tr><td><strong>Groups / Meetings</strong></td><td>All AA groups in the intergroup area and their associated weekly meetings.</td></tr>
                            <tr><td><strong>Intergroup Meetings</strong></td><td>The bi-monthly business meeting records, with group and officer attendance.</td></tr>
                            <tr><td><strong>Attendance</strong></td><td>A detail view for attendance at any past intergroup meeting — useful for minutes.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="ahp-dashboard">
                        <h2>Dashboard Widgets</h2>
                        <p>The WordPress Dashboard (the home screen after logging in) shows three at-a-glance widgets. These give you a live summary without opening any sub-page.</p>
                        <div class="card"><h3>Positions &amp; Members</h3><p>Lists every position alphabetically with the current holder's anonymous name and a colour-coded rotation badge. Vacant and overdue positions are immediately visible. Clicking a position title opens its edit screen.</p></div>
                        <div class="card"><h3>Groups &amp; Meetings</h3><p>Lists all meetings sorted by day of the week, then by start time. Each meeting card shows the group name, location, contacts, and a reconciliation badge indicating whether the meeting appears in the national AAGBDB listing. Days can be collapsed by clicking the header.</p></div>
                        <div class="card"><h3>Intergroup Meetings</h3><p style="margin-bottom:0;">Lists past and upcoming intergroup business meetings, sorted most recent first. Each card shows the date, total attendance count, and the groups and officers who attended. Click a date to open the full meeting record.</p></div>
                    </section>

                    <section id="ahp-ig-create">
                        <h2>Creating an Intergroup Meeting Record</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Intergroup Meetings</span><span class="sep">›</span><span class="crumb final">Add New</span></div>
                        <p>Create the meeting record at least a week before the meeting date. This gives time to check which member is currently each group's GSR before attendance is recorded on the day.</p>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Intergroup Meetings</strong> in the sidebar.</li>
                            <li>Click <strong>Add New</strong> at the top of the page.</li>
                            <li>In the <strong>Title</strong> field, enter the meeting name — use the format: <code>Intergroup Meeting — March 2026</code>.</li>
                            <li>Fill in the <strong>Date</strong> field using the date picker.</li>
                            <li>Leave the Group Attendees and Officers Attending fields empty for now.</li>
                            <li>Click <strong>Publish</strong>.</li>
                        </ol>
                        <table class="field-table">
                            <thead><tr><th>Field</th><th>What to enter</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">Title</span><span class="req">*</span></td><td>The meeting name. Recommended: <em>Intergroup Meeting — Month Year</em>.</td></tr>
                            <tr><td><span class="field-name">Date</span><span class="req">*</span></td><td>The date the meeting takes place. Use the date picker to select the correct date.</td></tr>
                            <tr><td><span class="field-name">Group attendees</span></td><td>The AA groups who sent a representative. When you select a group, the GSR's anonymous name appears in brackets automatically — you do not type it.</td></tr>
                            <tr><td><span class="field-name">Officers attending</span></td><td>The officers present. Each officer's current position title appears in brackets in the selector.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="ahp-ig-attendance">
                        <h2>Recording Attendance on the Day</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Intergroup Meetings</span><span class="sep">›</span><span class="crumb final">Edit</span></div>
                        <p>When the meeting begins, open the record you created in advance and update it as people arrive.</p>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Intergroup Meetings</strong>.</li>
                            <li>Find today's meeting in the list and click its title to open the edit screen.</li>
                            <li>In the <strong>Group Attendees</strong> field, click inside the selector and choose each group as its representative arrives. The GSR's anonymous name appears in brackets — this is pulled automatically from the member records.</li>
                            <li>In the <strong>Officers Attending</strong> field, select each officer who is present. Their position title appears alongside their name.</li>
                            <li>Click <strong>Update</strong>. Structured attendance records are created in the background automatically — one per group, one per officer.</li>
                        </ol>
                        <div class="callout success"><span class="callout-icon">✓</span><div><strong>Proxy attendance.</strong> If a group sent a proxy instead of the GSR, record the group as attending and note the proxy name in the attendance detail record. See <a href="#ahp-ig-view-attendance">Viewing Attendance</a>.</div></div>
                        <div class="callout warning"><span class="callout-icon">⚠️</span><div><strong>Officer elected during the meeting?</strong> If someone is voted into a new position during the meeting, update their member record immediately after the vote. The system will update today's attendance record automatically. Historical records from past meetings are not changed.</div></div>
                    </section>

                    <section id="ahp-ig-view-attendance">
                        <h2>Viewing Attendance Detail</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Attendance</span></div>
                        <p>The Attendance page shows full attendance records for any past intergroup meeting. Use this as source data when writing the attendance section of the minutes.</p>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Attendance</strong> in the sidebar.</li>
                            <li>Use the dropdown at the top of the page to select the meeting you want to view.</li>
                            <li>Two tables appear below the selector:
                                <ul>
                                    <li><strong>Group attendance</strong> — group name, GSR anonymous name, whether a proxy attended, and the proxy's name.</li>
                                    <li><strong>Officer attendance</strong> — position title and officer's anonymous name.</li>
                                </ul>
                            </li>
                        </ol>
                        <div class="callout info"><span class="callout-icon">💡</span><div>The attendance data is stored at the point of recording, so if an officer's name or position changes later, the historical record is preserved as it was on the day.</div></div>
                    </section>

                    <section id="ahp-member-create">
                        <h2>Adding a New Member</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">Add New</span></div>
                        <div class="callout purple"><span class="callout-icon">🔒</span><div><strong>Anonymous names only.</strong> Members are stored using their AA service name — not their legal name. Every anonymous name must be unique across all members. The system checks this in real time as you type.</div></div>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Members</strong>.</li>
                            <li>Click <strong>Add New</strong>.</li>
                            <li>In the <strong>Anonymous Name</strong> field, type the member's AA service name. A green tick appears if the name is available. A red message appears if it is already in use — choose a variation.</li>
                            <li>Select the member's <strong>Home Group</strong> from the dropdown.</li>
                            <li>If this member is their group's GSR, tick <strong>Is GSR</strong>.</li>
                            <li>If this member holds an intergroup officer role, select it from <strong>Intergroup Position</strong>.</li>
                            <li>Enter their <strong>Personal Email</strong> and <strong>Mobile Number</strong>. These are stored securely and only visible to users with the <code>scrutiny_view_personal_data</code> capability. Editing requires the <code>scrutiny_edit_personal_data</code> capability.</li>
                            <li>Set the profile visibility toggles as appropriate.</li>
                            <li>Click <strong>Publish</strong>.</li>
                        </ol>
                        <table class="field-table">
                            <thead><tr><th>Field</th><th>What to enter</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">Anonymous name</span><span class="req">*</span></td><td>The member's AA service name. Must be unique. The post title syncs with this field automatically — do not edit the title directly.</td></tr>
                            <tr><td><span class="field-name">Show anonymous name</span></td><td>Toggle on to display this name on the public position directory.</td></tr>
                            <tr><td><span class="field-name">Show member profile</span></td><td>Toggle on to display the anonymous profile text publicly.</td></tr>
                            <tr><td><span class="field-name">Anonymous profile</span></td><td>A short description the member can choose to show publicly.</td></tr>
                            <tr><td><span class="field-name">Home group</span><span class="req">*</span></td><td>The AA group this member attends. Select from the list of registered groups.</td></tr>
                            <tr><td><span class="field-name">Is GSR</span></td><td>Tick if this member is their home group's current Group Service Representative.</td></tr>
                            <tr><td><span class="field-name">Intergroup position</span></td><td>The officer role they hold. Leave blank if they are registered as a GSR only.</td></tr>
                            <tr><td><span class="field-name">Rotation date</span></td><td>When their current term ends. Set this when they take up an officer position.</td></tr>
                            <tr><td><span class="field-name">Personal email</span></td><td>Private. Visible with <code>scrutiny_view_personal_data</code>; editable with <code>scrutiny_edit_personal_data</code>. Used for sending correspondence.</td></tr>
                            <tr><td><span class="field-name">Mobile number</span></td><td>Private. Visible with <code>scrutiny_view_personal_data</code>; editable with <code>scrutiny_edit_personal_data</code>.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="ahp-member-edit">
                        <h2>Editing a Member</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">[Member name]</span></div>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Members</strong>.</li>
                            <li>Find the member using the search box at the top right, or sort the list by clicking <strong>Anonymous Name</strong>, <strong>Service Position</strong>, <strong>Is GSR?</strong>, or <strong>Homegroup</strong> in the column headers.</li>
                            <li>Click the member's name to open their record.</li>
                            <li>Make your changes.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <div class="callout warning"><span class="callout-icon">⚠️</span><div><strong>Changing an anonymous name.</strong> Type the new name in the Anonymous Name field — the system checks it is unique before saving. The post title updates automatically. Positions and attendance records linked to this member will reflect the new name.</div></div>
                    </section>

                    <section id="ahp-member-contacts">
                        <h2>Updating a Member's Contact Details</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">[Member name]</span></div>
                        <ol class="steps">
                            <li>Open the member record (see <a href="#ahp-member-edit">Editing a Member</a>).</li>
                            <li>Scroll down to the <strong>Personal Email</strong> and <strong>Mobile Number</strong> fields.</li>
                            <li>Update as needed.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <div class="callout info"><span class="callout-icon">💡</span><div>Personal contact details are never shown publicly. They are logged by Scrutiny every time they are accessed, in line with GDPR requirements. Updating these fields requires the <code>scrutiny_edit_personal_data</code> capability — without it the fields are read-only.</div></div>
                    </section>

                    <section id="ahp-member-gsr">
                        <h2>Setting or Removing GSR Status</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">[Member name]</span></div>
                        <p>GSR status is separate from holding an intergroup officer position. A member can be a GSR without any officer role, and can hold an officer role without being a GSR.</p>
                        <ol class="steps">
                            <li>Open the member record.</li>
                            <li>Find the <strong>Is GSR</strong> checkbox and tick or untick it.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <p>After saving, the GSR's name will appear in brackets next to their home group when you next open an intergroup meeting record. If there is an intergroup meeting today, the attendance record is updated immediately.</p>
                    </section>

                    <section id="ahp-member-responder">
                        <h2>Setting up a Telephone Responder</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">[Member name]</span></div>
                        <p>A <strong>telephone responder</strong> takes inbound calls to the intergroup helpline. This is a single toggle in the member's <strong>Service</strong> field group and is independent of their 12th-step status — a member can be a responder, a 12th stepper, both, or neither.</p>
                        <div class="callout warning"><span class="callout-icon">⚠️</span><div><strong>Check contact details first.</strong> A responder is only useful if they can be reached. Before turning the toggle on, make sure the member's <strong>Mobile Number</strong> and <strong>Personal Email</strong> are set (see <a href="#ahp-member-contacts">Updating Contacts</a>).</div></div>
                        <ol class="steps">
                            <li>Open the member record (see <a href="#ahp-member-edit">Editing a Member</a>).</li>
                            <li>Confirm the <strong>Mobile Number</strong> and <strong>Personal Email</strong> are filled in — these are how the helpline rota will reach them.</li>
                            <li>In the <strong>Service</strong> group, switch <strong>Telephone Responder</strong> on.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <table class="field-table">
                            <thead><tr><th>Field</th><th>What to enter</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">Telephone responder</span></td><td>Toggle on to mark the member as available to take inbound helpline calls. No other fields are required.</td></tr>
                            </tbody>
                        </table>
                        <div class="callout info"><span class="callout-icon">💡</span><div>A telephone responder is <em>not</em> the same as a 12th stepper. A responder handles inbound helpline calls; a 12th stepper is available to go out and meet a newcomer. The two toggles are separate — set whichever roles apply. See <a href="#ahp-member-12th">Setting up a 12th Stepper</a>.</div></div>
                    </section>

                    <section id="ahp-member-12th">
                        <h2>Setting up a 12th Stepper</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">[Member name]</span></div>
                        <p>A <strong>12th stepper</strong> is a member willing to take a 12th-step call — going out to meet a newcomer in their area. Marking a member as a 12th stepper reveals two extra fields in the <strong>Home</strong> group that say <em>where</em> they can cover and <em>who</em> they will see.</p>
                        <div class="callout warning"><span class="callout-icon">⚠️</span><div><strong>Set the Home Group first.</strong> The <strong>Is Twelfth Stepper?</strong> toggle only appears once a <strong>Home Group</strong> has been selected — the same as the <strong>Is GSR?</strong> toggle beside it. If you cannot see the toggle, pick a home group and the field will appear.</div></div>
                        <ol class="steps">
                            <li>Open the member record (see <a href="#ahp-member-edit">Editing a Member</a>).</li>
                            <li>In the <strong>Home</strong> group, make sure a <strong>Home Group</strong> is selected so the toggle is shown.</li>
                            <li>Switch <strong>Is Twelfth Stepper?</strong> on. Two more fields appear below it — <strong>Areas</strong> and <strong>Accepts</strong>.</li>
                            <li>In <strong>Areas</strong>, enter the areas the member can cover. The format is postcode and place name separated by a pipe, e.g. <code>BS15|Kingswood</code>. List several by repeating the pattern.</li>
                            <li>Under <strong>Accepts</strong>, tick which callers the member is willing to see — <strong>Male</strong>, <strong>Female</strong>, and/or <strong>Non-Binary</strong>.</li>
                            <li>Confirm the <strong>Mobile Number</strong> and <strong>Personal Email</strong> are filled in so the member can be contacted for a call.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <table class="field-table">
                            <thead><tr><th>Field</th><th>What to enter</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">Is twelfth stepper?</span></td><td>Toggle on to mark the member as available for 12th-step calls. Appears only once a <strong>Home Group</strong> is set.</td></tr>
                            <tr><td><span class="field-name">Areas</span><span class="req">*</span></td><td>The areas the member can cover for a call. Postcode and place name separated by a pipe, e.g. <code>BS15|Kingswood</code>. Shown only when <em>Is Twelfth Stepper?</em> is on.</td></tr>
                            <tr><td><span class="field-name">Accepts</span><span class="req">*</span></td><td>The callers the member will see — any combination of <em>Male</em>, <em>Female</em>, and <em>Non-Binary</em>. Shown only when <em>Is Twelfth Stepper?</em> is on.</td></tr>
                            </tbody>
                        </table>
                        <div class="callout info"><span class="callout-icon">💡</span><div>A member can be both a 12th stepper and a telephone responder, or one without the other. The 12th-stepper fields drive who is offered to a newcomer based on area and the callers they accept, so keep <strong>Areas</strong> and <strong>Accepts</strong> accurate.</div></div>
                    </section>

                    <section id="ahp-position-create">
                        <h2>Creating a Position</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Positions</span><span class="sep">›</span><span class="crumb final">Add New</span></div>
                        <p>Position records define each service role — Chair, Secretary, Treasurer, and all liaison positions. Setting these up correctly powers both the rotation dashboard and the public directory.</p>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Positions</strong>.</li>
                            <li>Click <strong>Add New</strong>.</li>
                            <li>In <strong>Long Name</strong>, type the full formal name — e.g. <code>Intergroup Secretary</code>. The system checks for uniqueness as you type.</li>
                            <li>In <strong>Short Description</strong>, enter a brief label — e.g. <code>Secretary</code>. This appears in admin tables and shortcodes.</li>
                            <li>Set <strong>Minimum Sobriety</strong> in months. Core officer roles typically require 24 months.</li>
                            <li>Set <strong>Term Years</strong> — usually 2.</li>
                            <li>Enter the <strong>Position Email</strong> — the generic role-based address shown publicly, e.g. <code>secretary@aa-bristol.org</code>.</li>
                            <li>Write a <strong>Summary</strong> of the role's responsibilities. This appears on the public position page.</li>
                            <li>Click <strong>Publish</strong>.</li>
                        </ol>
                        <table class="field-table">
                            <thead><tr><th>Field</th><th>What to enter</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">Long name</span><span class="req">*</span></td><td>Full formal name. Must be unique. Drives the post title automatically — do not edit the title directly.</td></tr>
                            <tr><td><span class="field-name">Short description</span><span class="req">*</span></td><td>A brief label for admin tables and the position shortcodes.</td></tr>
                            <tr><td><span class="field-name">Summary</span></td><td>Rich-text description of duties. Displayed on the public page via the <code>[position_summary]</code> shortcode.</td></tr>
                            <tr><td><span class="field-name">Position email</span><span class="req">*</span></td><td>The generic role-based email address. This is public — not the officer's personal email.</td></tr>
                            <tr><td><span class="field-name">Minimum sobriety (months)</span><span class="req">*</span></td><td>Minimum continuous sobriety required to hold this position.</td></tr>
                            <tr><td><span class="field-name">Term length (years)</span><span class="req">*</span></td><td>Standard term length. Used to calculate rotation dates.</td></tr>
                            <tr><td><span class="field-name">Current member</span></td><td>The member currently holding the role. Leave blank when first creating the position.</td></tr>
                            <tr><td><span class="field-name">Rotation date</span></td><td>When the current officer's term ends. Set when they are assigned.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="ahp-position-assign">
                        <h2>Assigning a Member to a Position</h2>
                        <p>After a member is elected, link them to their position. You can do this from either the position record or the member record — both work.</p>
                        <h3>From the position record</h3>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Positions</span><span class="sep">›</span><span class="crumb final">[Position name]</span></div>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Positions</strong> and open the position record.</li>
                            <li>In the <strong>Current Member</strong> field, click the selector and choose the new officer. Their anonymous name appears in the picker.</li>
                            <li>Set the <strong>Rotation Date</strong> to the expected end of their term — typically their election date plus the term length in years.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <h3>From the member record</h3>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Members</span><span class="sep">›</span><span class="crumb final">[Member name]</span></div>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Members</strong> and open the member record.</li>
                            <li>In <strong>Intergroup Position</strong>, select the position they now hold.</li>
                            <li>Set the <strong>Rotation Date</strong>.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <div class="callout info"><span class="callout-icon">💡</span><div>Either approach works — the system links positions and members bidirectionally. If you edit both sides in the same session, save one before opening the other.</div></div>
                        <div class="callout warning"><span class="callout-icon">⚠️</span><div><strong>Replacing one officer with another.</strong> Always clear the outgoing officer first, then assign the new one. This prevents a position appearing to have two holders simultaneously.</div></div>
                    </section>

                    <section id="ahp-position-vacant">
                        <h2>Marking a Position as Vacant</h2>
                        <div class="path"><span class="crumb">Intergroup</span><span class="sep">›</span><span class="crumb final">Positions</span><span class="sep">›</span><span class="crumb final">[Position name]</span></div>
                        <p>When an officer leaves and no replacement has been elected, clear the position so it shows correctly on the dashboard and the public directory.</p>
                        <ol class="steps">
                            <li>Go to <strong>Intergroup › Positions</strong> and open the position record.</li>
                            <li>In the <strong>Current Member</strong> field, click the × next to the member's name to remove the link.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <p>The position immediately shows a <span class="badge badge-vacant">Vacant</span> badge on the dashboard and on the public directory. The email link on the public page is removed automatically.</p>
                    </section>

                    <section id="ahp-position-dashboard">
                        <h2>Reading the Rotation Badges</h2>
                        <p>The Positions &amp; Members dashboard widget shows a status badge on every position. Check this at the start of each Intergroup business meeting to identify items that need to go on the agenda.</p>
                        <table class="status-table">
                            <thead><tr><th>Badge</th><th>Meaning</th><th>Action</th></tr></thead>
                            <tbody>
                            <tr><td><span class="badge badge-filled">Filled</span></td><td>Officer in post, more than 3 months remaining.</td><td>No action needed.</td></tr>
                            <tr><td><span class="badge badge-soon">Soon (Xm)</span></td><td>3 months or fewer remaining in current term.</td><td>Add to the agenda as an upcoming rotation. Begin succession planning.</td></tr>
                            <tr><td><span class="badge badge-due">Due</span></td><td>Rotation date is this month.</td><td>Thank the outgoing officer. Hold an election at this meeting or the next.</td></tr>
                            <tr><td><span class="badge badge-overdue">Overdue</span></td><td>Rotation date has passed. Officer may be continuing in an acting capacity.</td><td>Add as a priority agenda item. An election is needed immediately.</td></tr>
                            <tr><td><span class="badge badge-vacant">Vacant</span></td><td>No officer is currently assigned.</td><td>Raise as urgent. The public directory shows this position as vacant.</td></tr>
                            <tr><td><span class="badge badge-norot">No Rotation</span></td><td>Position is filled but no rotation date has been set.</td><td>Ask the officer for their expected end date and update the record.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="ahp-group-create">
                        <h2>Adding a New Group</h2>
                        <p>Groups are stored as a custom post type. Navigate to it using the WordPress sidebar — it may appear under a custom label depending on your site setup, typically alongside or below the Intergroup menu.</p>
                        <ol class="steps">
                            <li>Navigate to the <strong>Groups</strong> post type in the sidebar.</li>
                            <li>Click <strong>Add New</strong>.</li>
                            <li>Enter the group name as the post title.</li>
                            <li>Fill in the contact fields — email address, phone number, and website.</li>
                            <li>Add at least one named contact in the <strong>Contacts</strong> section — name and phone number.</li>
                            <li>Set the <strong>District ID</strong> if applicable.</li>
                            <li>Click <strong>Publish</strong>.</li>
                        </ol>
                    </section>

                    <section id="ahp-group-contacts">
                        <h2>Updating Group Contact Details</h2>
                        <ol class="steps">
                            <li>Navigate to the <strong>Groups</strong> post type and find the group you want to update.</li>
                            <li>Click the group name to open its record.</li>
                            <li>Update the relevant fields.</li>
                            <li>Click <strong>Update</strong>.</li>
                        </ol>
                        <table class="field-table">
                            <thead><tr><th>Field</th><th>What to enter</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">Email</span></td><td>The group's general contact email. Shown on the meeting dashboard.</td></tr>
                            <tr><td><span class="field-name">Phone</span></td><td>Contact phone number.</td></tr>
                            <tr><td><span class="field-name">Website</span></td><td>The group's own website, if they have one.</td></tr>
                            <tr><td><span class="field-name">Contacts</span></td><td>Named contacts — first name and phone number. Up to three are shown on the meeting dashboard card.</td></tr>
                            <tr><td><span class="field-name">Venmo</span></td><td>Venmo handle for 7th Tradition digital contributions, e.g. <code>@GroupName</code>.</td></tr>
                            <tr><td><span class="field-name">PayPal</span></td><td>PayPal username for digital contributions.</td></tr>
                            <tr><td><span class="field-name">Square</span></td><td>Square Cash App cashtag, e.g. <code>$GroupName</code>.</td></tr>
                            <tr><td><span class="field-name">Notes</span></td><td>Internal notes about the group. Admin-only. Never shown publicly.</td></tr>
                            <tr><td><span class="field-name">Last contact</span></td><td>Date of most recent contact with the group. Useful for liaison tracking.</td></tr>
                            </tbody>
                        </table>
                    </section>

                    <section id="ahp-meeting-create">
                        <h2>Adding an AA Meeting</h2>
                        <ol class="steps">
                            <li>Navigate to the <strong>Meetings</strong> post type in the sidebar.</li>
                            <li>Click <strong>Add New</strong>.</li>
                            <li>Enter the meeting name as the post title — e.g. <code>Clifton Vale Thursday Evening</code>.</li>
                            <li>Set the <strong>Day of Week</strong>, <strong>Start Time</strong>, and <strong>End Time</strong>.</li>
                            <li>Link the meeting to its <strong>Group</strong> using the group selector field.</li>
                            <li>Link it to a <strong>Location</strong>, or create a new location record if the venue doesn't exist yet.</li>
                            <li>If the meeting is online, tick <strong>Online Meeting</strong> and enter the Zoom or video link.</li>
                            <li>Select the <strong>Meeting Types</strong> — Open, Closed, Speaker, Step, etc.</li>
                            <li>Click <strong>Publish</strong>.</li>
                        </ol>
                    </section>

                    <section id="ahp-meeting-reconcile">
                        <h2>Reading the Reconciliation Badges</h2>
                        <p>Each meeting card in the Groups &amp; Meetings dashboard shows a badge indicating whether the meeting appears in the national AAGBDB listing. This helps identify meetings that may need attention.</p>
                        <table class="status-table">
                            <thead><tr><th>Badge</th><th>Meaning</th><th>Action</th></tr></thead>
                            <tbody>
                            <tr><td><span class="badge badge-matched">AAGBDB</span></td><td>Confident match — same day, time, and similar name in the national listing.</td><td>No action needed.</td></tr>
                            <tr><td><span class="badge badge-partial">AAGBDB ~</span></td><td>Partial match — day and time match but the name or end time differs slightly.</td><td>Compare your local record with the national listing. Update the name or time if needed.</td></tr>
                            <tr><td><span class="badge badge-possible">AAGBDB ?</span></td><td>Possible match — day and time match but the name is too different to be confident.</td><td>Check whether this is the same meeting as the national entry. May need investigation.</td></tr>
                            <tr><td><span class="badge badge-missing">Not Listed</span></td><td>Local only — not found in the national AAGBDB listing. Expected for online-only meetings.</td><td>For in-person meetings, this may mean registration with AA GB is needed. Contact the GSO.</td></tr>
                            </tbody>
                        </table>
                        <div class="callout info"><span class="callout-icon">💡</span><div>The reconciliation runs automatically each time the dashboard loads. After updating a meeting's name or time, refresh the dashboard to see the updated badge.</div></div>
                    </section>

                    <section id="ahp-shortcodes">
                        <h2>Shortcodes</h2>
                        <p>Amber registers four shortcodes that can be placed in any WordPress page or post. They display live position data — when a position is updated in the admin, the page updates automatically. No manual editing is required.</p>
                        <table class="field-table">
                            <thead><tr><th>Shortcode</th><th>What it displays</th></tr></thead>
                            <tbody>
                            <tr><td><span class="field-name">[directory_list]</span></td><td>A full table of all positions with current holders, email links, and rotation status. Use this on the main Positions or Service Roles page.</td></tr>
                            <tr><td><span class="field-name">[position_header]</span></td><td>The position title, minimum sobriety requirement, term length, and email link for a single position.</td></tr>
                            <tr><td><span class="field-name">[position_state]</span></td><td>A rotation status badge for a single position — for example, a Vacant badge when the role is empty.</td></tr>
                            <tr><td><span class="field-name">[position_summary]</span></td><td>The rich-text summary of a position's duties.</td></tr>
                            </tbody>
                        </table>
                        <div class="callout success"><span class="callout-icon">✓</span><div><strong>No manual website updates needed.</strong> Because shortcodes pull directly from the database, updating a member or position record in the admin is all you need to do. The website reflects the change immediately.</div></div>
                        <p>To find where shortcodes are placed on the site, go to <strong>Pages</strong> in the WordPress sidebar and search for pages containing <code>[position</code> or <code>[directory</code>.</p>
                    </section>

                    <section id="ahp-troubleshooting">
                        <h2>Troubleshooting</h2>
                        <div class="card"><h3>Anonymous name rejected as a duplicate</h3><p>The system requires every anonymous name to be unique. If you see a red warning as you type, the name is already in use by another member record.</p><p style="margin-bottom:0;">Go to <strong>Intergroup › Members</strong>, sort by <em>Anonymous Name</em>, and find who already has that name. Discuss with both members to agree on a variation — for example, adding an initial. Update the record for whichever member is taking the new variation.</p></div>
                        <div class="card"><h3>Position not updating on the public site</h3><p>Check you clicked <strong>Update</strong> rather than navigating away. Clear the WordPress cache if your site uses a caching plugin. Force-refresh the browser on the public page (Ctrl+Shift+R or Cmd+Shift+R).</p><p style="margin-bottom:0;">Also confirm the position record has a member assigned and that the member's post status is <em>Published</em>, not <em>Draft</em>.</p></div>
                        <div class="card"><h3>GSR name not appearing in the meeting picker</h3><p>Open the member record and confirm that <strong>Is GSR</strong> is ticked and <strong>Home Group</strong> is set to the correct group. Click <strong>Update</strong>.</p><p style="margin-bottom:0;">Return to the intergroup meeting record and refresh the page. The GSR name should now appear in brackets next to the group name in the selector.</p></div>
                        <div class="card"><h3>A meeting shows as Not Listed</h3><p>Check the meeting's name, day, and start time against the national AAGB listing. If the meeting is in the national listing but showing as Not Listed, the name, day, or time in your local record may differ. Update the local meeting to match.</p><p style="margin-bottom:0;">If the meeting is genuinely absent from the national listing and is an in-person meeting, it may need to be registered. Contact the GSO or the national web team.</p></div>
                        <div class="card"><h3>Attendance records are missing for a meeting</h3><p>Open the intergroup meeting record and click <strong>Update</strong> — even without making changes. This re-triggers the background sync that creates attendance records.</p><p style="margin-bottom:0;">If a group or officer was added and removed before the first save, re-add them and save again.</p></div>
                        <div class="card"><h3>Rotation date shows as Not Set</h3><p>If a position shows <span class="badge badge-norot">No Rotation</span>, the rotation date has not been entered for the current officer's term.</p><p>Open the position record, enter the expected end date in the <strong>Rotation Date</strong> field — typically the election date plus the term length in years — and click <strong>Update</strong>.</p><p style="margin-bottom:0;">The badge will update to the correct status immediately.</p></div>
                        <div class="card"><h3>The Intergroup menu is missing from the sidebar</h3><p style="margin-bottom:0;">Your user account may not have the required permission level. Contact the site administrator to have your role updated. Alternatively, the Amber plugin may have been deactivated — check <strong>Plugins</strong> in the WordPress admin to confirm it is active alongside Unity and Scrutiny.</p></div>
                    </section>

                </main>
            </div><!-- .ahp-body -->
        </div><!-- .amber-help-page -->

        <script>
            (function () {
                // Hamburger toggle
                document.getElementById('ahp-toggle').addEventListener('click', function () {
                    document.getElementById('ahp-sidebar').classList.toggle('open');
                    document.getElementById('ahp-overlay').classList.toggle('visible');
                });
                document.getElementById('ahp-overlay').addEventListener('click', function () {
                    document.getElementById('ahp-sidebar').classList.remove('open');
                    document.getElementById('ahp-overlay').classList.remove('visible');
                });

                // Active nav link on scroll
                var sections = document.querySelectorAll('.amber-help-page section[id]');
                var navLinks = document.querySelectorAll('.amber-help-page .nav-link');

                function updateActiveLink() {
                    var current = '';
                    var scrollY = window.scrollY + 120;
                    sections.forEach(function (s) {
                        if (s.offsetTop <= scrollY) current = s.getAttribute('id');
                    });
                    navLinks.forEach(function (l) {
                        l.classList.remove('active');
                        if (l.getAttribute('href') === '#' + current) l.classList.add('active');
                    });
                }

                window.addEventListener('scroll', updateActiveLink, { passive: true });
                updateActiveLink();
            })();
        </script>
