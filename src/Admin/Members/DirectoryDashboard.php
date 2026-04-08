<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionViewFactory;

use function add_action;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function wp_add_dashboard_widget;

/**
 * Directory Dashboard Widget
 *
 * Adds a dashboard panel with two foldable sections:
 *   - Groups:    all members who are currently GSRs
 *   - Positions: all positions with the member(s) who hold them
 */
class DirectoryDashboard
{
    private MemberRepository $memberRepository;
    private GroupFactory $groupFactory;
    private PositionViewFactory $positionViewFactory;
    private PositionRepository $positionRepository;
    private readonly array $member_config;

    /**
     * Constructor
     *
     * @param Configuration       $configuration       Amber configuration
     * @param MemberRepository    $memberRepository    Member repository
     * @param GroupFactory        $groupFactory        Group factory
     * @param PositionViewFactory $positionViewFactory Position view factory
     * @param PositionRepository  $positionRepository  Position repository
     */
    public function __construct(
        Configuration $configuration,
        MemberRepository $memberRepository,
        GroupFactory $groupFactory,
        PositionViewFactory $positionViewFactory,
        PositionRepository $positionRepository
    ) {
        $this->memberRepository    = $memberRepository;
        $this->groupFactory        = $groupFactory;
        $this->positionViewFactory = $positionViewFactory;
        $this->positionRepository  = $positionRepository;
        $this->member_config       = $configuration->getConfig(Member::class);

        // Register hooks
        add_action('wp_dashboard_setup', [$this, 'registerDashboardWidget']);
        add_action('admin_head', [$this, 'addDashboardStyles']);
        add_action('admin_footer', [$this, 'addDashboardScripts']);
    }

    /**
     * Register the dashboard widget
     */
    public function registerDashboardWidget(): void
    {
        wp_add_dashboard_widget(
            'directory_dashboard',
            'Intergroup Directory',
            [$this, 'renderDashboardWidget'],
            null,
            null,
            'side',
            'high'
        );
    }

    /**
     * Render the dashboard widget content
     */
    public function renderDashboardWidget(): void
    {
        echo '<div class="directory-dashboard">';
        $this->renderGroupsSection();
        $this->renderPositionsSection();
        echo '</div>';
    }

    /* ------------------------------------------------------------------
     *  Section 1 — Groups (GSR members)
     * ----------------------------------------------------------------*/

    private function renderGroupsSection(): void
    {
        $allMembers = $this->memberRepository->findAll();

        $gsrMembers = [];
        foreach ($allMembers as $member) {
            if ($member->isGsr() && $member->getHomeGroup()) {
                $gsrMembers[] = $member;
            }
        }

        usort($gsrMembers, function (Member $a, Member $b) {
            return strcasecmp(
                $a->getAnonymousName() ?? '',
                $b->getAnonymousName() ?? ''
            );
        });

        $count = count($gsrMembers);

        echo '<div class="directory-section" data-section="groups">';

        // Foldable header
        echo '<div class="directory-section-header">';
        echo '<button type="button" class="directory-section-toggle" aria-expanded="true">';
        echo '<span class="directory-toggle-icon" aria-hidden="true">&#9660;</span> ';
        echo '<span class="directory-section-title">Groups</span>';
        echo '<span class="directory-section-count">' . esc_html((string) $count) . '</span>';
        echo '</button>';
        if (!empty($gsrMembers)) {
            echo '<button type="button" class="button button-small directory-copy-btn" id="gsr-copy-btn" title="Copy all GSR names to clipboard">';
            echo '<span class="directory-copy-label">Copy All</span>';
            echo '</button>';
        }
        echo '</div>';

        // Collapsible body
        echo '<div class="directory-section-body">';

        if (empty($gsrMembers)) {
            echo '<p class="directory-empty">No members are currently marked as GSR.</p>';
        } else {
            // Member list
            echo '<ul class="gsr-member-list" id="gsr-member-list">';
            foreach ($gsrMembers as $member) {
                $memberId      = $member->getId();
                $anonymousName = $member->getAnonymousName() ?: 'Unnamed';
                $editLink      = get_edit_post_link($memberId);
                $personalEmail = $member->getPersonalEmail() ?: '';

                $homegroupId = $member->getHomeGroup();
                $homegroup   = $this->groupFactory->createFromSource($homegroupId);
                $groupName   = $homegroup ? $homegroup->getTitle() : null;

                echo '<li class="gsr-member-item" data-name="' . esc_attr($anonymousName) . '" data-email="' . esc_attr($personalEmail) . '">';

                if ($editLink) {
                    echo '<a href="' . esc_url($editLink) . '" class="gsr-member-name">' . esc_html($anonymousName) . '</a>';
                } else {
                    echo '<span class="gsr-member-name">' . esc_html($anonymousName) . '</span>';
                }

                if (!empty($groupName)) {
                    echo '<span class="gsr-member-group">' . esc_html($groupName) . '</span>';
                }

                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>'; // .directory-section-body
        echo '</div>'; // .directory-section
    }

    /* ------------------------------------------------------------------
     *  Section 2 — Positions (simple list)
     * ----------------------------------------------------------------*/

    private function renderPositionsSection(): void
    {
        $positions = $this->positionRepository->findAll();

        $positionViews = [];
        foreach ($positions as $position) {
            $positionView = $this->positionViewFactory->createFrom($position->getId());
            if ($positionView) {
                $members = $positionView->getMembers();
                if (!$positionView->isVacant() && !empty($members)) {
                    $positionViews[] = $positionView;
                }
            }
        }

        usort($positionViews, function ($a, $b) {
            return strcasecmp($a->getTitle() ?? '', $b->getTitle() ?? '');
        });

        $count = count($positionViews);

        echo '<div class="directory-section" data-section="positions">';

        // Foldable header
        echo '<div class="directory-section-header">';
        echo '<button type="button" class="directory-section-toggle" aria-expanded="true">';
        echo '<span class="directory-toggle-icon" aria-hidden="true">&#9660;</span> ';
        echo '<span class="directory-section-title">Positions</span>';
        echo '<span class="directory-section-count">' . esc_html((string) $count) . '</span>';
        echo '</button>';
        if (!empty($positionViews)) {
            echo '<button type="button" class="button button-small directory-copy-btn" id="positions-copy-btn" title="Copy all position holders to clipboard">';
            echo '<span class="directory-copy-label">Copy All</span>';
            echo '</button>';
        }
        echo '</div>';

        // Collapsible body
        echo '<div class="directory-section-body">';

        if (empty($positionViews)) {
            echo '<p class="directory-empty">No filled positions found.</p>';
        } else {
            echo '<ul class="directory-position-list" id="directory-position-list">';
            foreach ($positionViews as $positionView) {
                $positionTitle    = $positionView->getTitle() ?: 'Untitled Position';
                $positionEditLink = get_edit_post_link($positionView->getPosition()->getId());
                $positionEmail    = $positionView->getPositionEmail() ?: '';
                $members          = $positionView->getMembers();

                $memberNames = [];
                foreach ($members as $member) {
                    $memberNames[] = $member->getAnonymousName();
                }

                echo '<li class="directory-position-item" data-position="' . esc_attr($positionTitle) . '" data-holder="' . esc_attr(implode(', ', $memberNames)) . '" data-email="' . esc_attr($positionEmail) . '">';

                // Position name
                if ($positionEditLink) {
                    echo '<a href="' . esc_url($positionEditLink) . '" class="directory-position-name">' . esc_html($positionTitle) . '</a>';
                } else {
                    echo '<span class="directory-position-name">' . esc_html($positionTitle) . '</span>';
                }

                // Member name(s)
                echo '<span class="directory-position-holder">';
                echo esc_html(implode(', ', $memberNames));
                echo '</span>';

                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>'; // .directory-section-body
        echo '</div>'; // .directory-section
    }

    /* ------------------------------------------------------------------
     *  Styles
     * ----------------------------------------------------------------*/

    /**
     * Add custom styles for the dashboard widget
     */
    public function addDashboardStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        echo '<style>
            /* ── Directory: layout ── */
            .directory-dashboard {
                margin: -6px -12px -12px -12px;
            }

            /* ── Foldable sections ── */
            .directory-section {
                border-bottom: 1px solid #e0e0e0;
            }

            .directory-section:last-child {
                border-bottom: none;
            }

            .directory-section-header {
                display: flex;
                align-items: center;
                background: #f6f7f7;
                gap: 8px;
                padding-right: 12px;
            }

            .directory-section-toggle {
                display: flex;
                align-items: center;
                flex: 1;
                min-width: 0;
                padding: 10px 12px;
                margin: 0;
                border: none;
                background: transparent;
                cursor: pointer;
                font-size: 13px;
                font-weight: 600;
                color: #1d2327;
                text-align: left;
                gap: 6px;
                line-height: 1.4;
            }

            .directory-section-header:hover {
                background: #eff0f1;
            }

            .directory-section-toggle:focus {
                outline: 1px dotted #2271b1;
                outline-offset: -1px;
            }

            .directory-toggle-icon {
                font-size: 10px;
                transition: transform 0.2s;
                display: inline-block;
                width: 14px;
                text-align: center;
                flex-shrink: 0;
            }

            .directory-section.collapsed .directory-toggle-icon {
                transform: rotate(-90deg);
            }

            .directory-section-title {
                flex: 1;
            }

            .directory-section-count {
                background: #ddd;
                color: #555;
                font-size: 11px;
                font-weight: 600;
                padding: 1px 7px;
                border-radius: 10px;
                flex-shrink: 0;
            }

            .directory-section.collapsed .directory-section-body {
                display: none;
            }

            .directory-empty {
                color: #999;
                font-style: italic;
                margin: 0;
                padding: 8px 12px;
            }

            /* ── Copy button in header ── */
            .directory-copy-btn {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                cursor: pointer;
                flex-shrink: 0;
            }

            .directory-copy-btn.copied {
                color: #46b450;
                border-color: #46b450;
            }

            /* ── Groups section (GSR list) ── */
            .gsr-member-list {
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .gsr-member-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 12px;
                border-bottom: 1px solid #f0f0f1;
                gap: 8px;
            }

            .gsr-member-item:last-child {
                border-bottom: none;
            }

            .gsr-member-item:hover {
                background: #f9f9f9;
            }

            .gsr-member-name {
                font-size: 13px;
                color: #2271b1;
                text-decoration: none;
                font-weight: 500;
                flex-shrink: 0;
            }

            a.gsr-member-name:hover {
                color: #135e96;
                text-decoration: underline;
            }

            .gsr-member-group {
                font-size: 12px;
                color: #888;
                text-align: right;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            /* ── Positions section (simple list) ── */
            .directory-position-list {
                margin: 0;
                padding: 0;
                list-style: none;
            }

            .directory-position-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 12px;
                border-bottom: 1px solid #f0f0f1;
                gap: 8px;
            }

            .directory-position-item:last-child {
                border-bottom: none;
            }

            .directory-position-item:hover {
                background: #f9f9f9;
            }

            .directory-position-name {
                font-size: 13px;
                color: #2271b1;
                text-decoration: none;
                font-weight: 500;
                flex-shrink: 0;
            }

            a.directory-position-name:hover {
                color: #135e96;
                text-decoration: underline;
            }

            .directory-position-holder {
                font-size: 12px;
                color: #555;
                text-align: right;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }

            .vacant-indicator {
                color: #999;
                font-style: italic;
            }
        </style>';
    }

    /* ------------------------------------------------------------------
     *  Scripts (fold toggle + clipboard copy)
     * ----------------------------------------------------------------*/

    /**
     * Add the fold toggle and clipboard copy scripts
     */
    public function addDashboardScripts(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        echo '<script>
        (function() {
            /* ── Fold / unfold with persistent state ── */
            var STORAGE_KEY = "amber_directory_collapsed";

            function loadCollapsedState() {
                try {
                    return JSON.parse(localStorage.getItem(STORAGE_KEY)) || {};
                } catch(e) {
                    return {};
                }
            }

            function saveCollapsedState(state) {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                } catch(e) {}
            }

            var savedState = loadCollapsedState();
            var sections = document.querySelectorAll(".directory-section[data-section]");
            for (var s = 0; s < sections.length; s++) {
                var key = sections[s].getAttribute("data-section");
                if (key && savedState[key] === true) {
                    sections[s].classList.add("collapsed");
                    var btn = sections[s].querySelector(".directory-section-toggle");
                    if (btn) btn.setAttribute("aria-expanded", "false");
                }
            }

            var toggles = document.querySelectorAll(".directory-section-toggle");
            for (var i = 0; i < toggles.length; i++) {
                toggles[i].addEventListener("click", function() {
                    var section = this.closest(".directory-section");
                    var isCollapsed = section.classList.toggle("collapsed");
                    this.setAttribute("aria-expanded", isCollapsed ? "false" : "true");

                    var sectionKey = section.getAttribute("data-section");
                    if (sectionKey) {
                        var state = loadCollapsedState();
                        if (isCollapsed) {
                            state[sectionKey] = true;
                        } else {
                            delete state[sectionKey];
                        }
                        saveCollapsedState(state);
                    }
                });
            }

            /* ── Shared copy helpers ── */
            function copyText(text, btn) {
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text).then(function() {
                        showCopied(btn);
                    }).catch(function() {
                        fallbackCopy(text, btn);
                    });
                } else {
                    fallbackCopy(text, btn);
                }
            }

            function fallbackCopy(text, btn) {
                var ta = document.createElement("textarea");
                ta.value = text;
                ta.style.position = "fixed";
                ta.style.left = "-9999px";
                document.body.appendChild(ta);
                ta.select();
                try {
                    document.execCommand("copy");
                    showCopied(btn);
                } catch(e) {}
                document.body.removeChild(ta);
            }

            function showCopied(btn) {
                var label = btn.querySelector(".directory-copy-label");
                btn.classList.add("copied");
                label.textContent = "Copied!";
                setTimeout(function() {
                    btn.classList.remove("copied");
                    label.textContent = "Copy All";
                }, 2000);
            }

            /* ── Copy GSR emails ── */
            var gsrBtn = document.getElementById("gsr-copy-btn");
            if (gsrBtn) {
                gsrBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    var items = document.querySelectorAll("#gsr-member-list .gsr-member-item");
                    var entries = [];
                    for (var i = 0; i < items.length; i++) {
                        var name = items[i].getAttribute("data-name");
                        var email = items[i].getAttribute("data-email");
                        if (name && email) {
                            entries.push("\"" + name + "\" <" + email + ">");
                        }
                    }
                    copyText(entries.join("; "), gsrBtn);
                });
            }

            /* ── Copy position holders ── */
            var posBtn = document.getElementById("positions-copy-btn");
            if (posBtn) {
                posBtn.addEventListener("click", function(e) {
                    e.stopPropagation();
                    var items = document.querySelectorAll("#directory-position-list .directory-position-item");
                    var entries = [];
                    for (var i = 0; i < items.length; i++) {
                        var holder = items[i].getAttribute("data-holder");
                        var email = items[i].getAttribute("data-email");
                        if (holder && email) {
                            entries.push("\"" + holder + "\" <" + email + ">");
                        }
                    }
                    copyText(entries.join("; "), posBtn);
                });
            }
        })();
        </script>';
    }
}