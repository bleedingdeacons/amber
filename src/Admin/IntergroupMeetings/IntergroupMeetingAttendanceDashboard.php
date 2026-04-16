<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use TsmlForUnity\IntergroupMeetings\TsmlIntergroupMeetingGroupAttendanceTable;
use TsmlForUnity\IntergroupMeetings\TsmlIntergroupMeetingOfficerAttendanceTable;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;

use function add_action;
use function add_submenu_page;
use function esc_attr;
use function esc_html;
use function get_current_screen;
use function sanitize_text_field;
use function wp_unslash;

/**
 * Intergroup Meeting Attendance Dashboard
 *
 * Adds an admin submenu page showing both group and officer attendance
 * records for a selected intergroup meeting. Users pick a meeting from
 * a dropdown and see two tables: one for group attendance (with GSR
 * and proxy info) and one for officer attendance (with position info).
 */
class IntergroupMeetingAttendanceDashboard
{
    private IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository;
    private IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository;

    private const PAGE_SLUG = 'intergroup-attendance';

    /**
     * Constructor
     *
     * @param IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository Group attendance repository
     * @param IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository Officer attendance repository
     */
    public function __construct(
        IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository,
        IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository
    ) {
        $this->groupAttendanceRepository = $groupAttendanceRepository;
        $this->officerAttendanceRepository = $officerAttendanceRepository;

        add_action('admin_menu', [$this, 'registerSubmenuPage']);
        add_action('admin_head', [$this, 'addPageStyles']);
    }

    /**
     * Register the submenu page under Intergroup
     */
    public function registerSubmenuPage(): void
    {
        add_submenu_page(
            'intergroup',
            'Intergroup Meeting Attendance',
            'Attendance',
            'edit_posts',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Render the full admin page
     */
    public function renderPage(): void
    {
        $labels = $this->getDistinctMeetingLabels();

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selectedLabel = isset($_GET['meeting_label']) ? sanitize_text_field(wp_unslash($_GET['meeting_label'])) : '';

        // Default to the first label if none selected
        if (empty($selectedLabel) && !empty($labels)) {
            $selectedLabel = $labels[0];
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Intergroup Meeting Attendance</h1>';

        // Meeting selector form
        $this->renderMeetingSelector($labels, $selectedLabel);

        // Attendance tables
        if (!empty($selectedLabel)) {
            $this->renderGroupAttendanceTable($selectedLabel);
            $this->renderOfficerAttendanceTable($selectedLabel);
        }

        echo '</div>';
    }

    /**
     * Render the meeting selector dropdown
     *
     * @param array<string> $labels        Distinct meeting labels
     * @param string        $selectedLabel Currently selected meeting label
     */
    private function renderMeetingSelector(array $labels, string $selectedLabel): void
    {
        if (empty($labels)) {
            echo '<p>No attendance records found.</p>';
            return;
        }

        echo '<form method="get" action="' . esc_attr(admin_url('admin.php')) . '" class="ig-attendance-selector">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<label for="meeting_label"><strong>Meeting:</strong></label> ';
        echo '<select name="meeting_label" id="meeting_label" onchange="this.form.submit()">';

        foreach ($labels as $label) {
            $selected = $label === $selectedLabel ? ' selected' : '';
            echo '<option value="' . esc_attr($label) . '"' . $selected . '>'
                . esc_html($label)
                . '</option>';
        }

        echo '</select>';
        echo '<noscript><button type="submit" class="button">View</button></noscript>';
        echo '</form>';
    }

    /**
     * Render the group attendance table for a given meeting label
     *
     * @param string $meetingLabel Meeting label to filter by
     */
    private function renderGroupAttendanceTable(string $meetingLabel): void
    {
        $records = $this->groupAttendanceRepository->findAll(['meeting_label' => $meetingLabel]);

        echo '<h2 class="ig-section-heading">Group Attendance</h2>';
        echo '<div class="ig-attendance-table-wrap">';

        if (empty($records)) {
            echo '<p class="ig-attendance-empty">No group attendance records for this meeting.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped ig-attendance-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Group</th>';
        echo '<th>GSR Name</th>';
        echo '<th>Proxy</th>';
        echo '<th>Proxy Name</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($records as $record) {
            echo '<tr>';

            // Group
            echo '<td>' . esc_html($record->getMeetingGroup()) . '</td>';

            // GSR Name
            echo '<td>' . esc_html($record->getGsrName()) . '</td>';

            // Proxy
            echo '<td>';
            if ($record->isGsrProxy()) {
                echo '<span class="ig-proxy-yes">Yes</span>';
            } else {
                echo '<span class="ig-proxy-no">No</span>';
            }
            echo '</td>';

            // Proxy Name
            echo '<td>';
            $proxyName = $record->getGsrProxyName();
            if (!empty($proxyName)) {
                echo esc_html($proxyName);
            } else {
                echo '<span class="ig-empty-cell">—</span>';
            }
            echo '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Summary
        $total = count($records);
        $proxyCount = 0;
        foreach ($records as $record) {
            if ($record->isGsrProxy()) {
                $proxyCount++;
            }
        }

        echo '<p class="ig-attendance-summary">';
        echo '<strong>' . esc_html((string) $total) . '</strong> group record'
            . ($total !== 1 ? 's' : '');
        if ($proxyCount > 0) {
            echo ' &middot; <strong>' . esc_html((string) $proxyCount) . '</strong> proxy'
                . ($proxyCount !== 1 ? ' attendees' : '');
        }
        echo '</p>';

        echo '</div>';
    }

    /**
     * Render the officer attendance table for a given meeting label
     *
     * @param string $meetingLabel Meeting label to filter by
     */
    private function renderOfficerAttendanceTable(string $meetingLabel): void
    {
        $records = $this->officerAttendanceRepository->findAll(['meeting_label' => $meetingLabel]);

        echo '<h2 class="ig-section-heading">Officer Attendance</h2>';
        echo '<div class="ig-attendance-table-wrap">';

        if (empty($records)) {
            echo '<p class="ig-attendance-empty">No officer attendance records for this meeting.</p>';
            echo '</div>';
            return;
        }

        // Group officer names by position
        $positionGroups = [];
        foreach ($records as $record) {
            $positionName = $record->getPositionName();
            $key = !empty($positionName) ? $positionName : '';
            $positionGroups[$key][] = $record->getOfficerName();
        }

        echo '<table class="widefat striped ig-attendance-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Position</th>';
        echo '<th>Officer Name</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($positionGroups as $positionName => $officerNames) {
            echo '<tr>';

            // Position
            echo '<td>';
            if (!empty($positionName)) {
                echo esc_html($positionName);
            } else {
                echo '<span class="ig-empty-cell">—</span>';
            }
            echo '</td>';

            // Officer Name(s), comma-separated
            echo '<td>' . esc_html(implode(', ', $officerNames)) . '</td>';

            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Summary
        $total = count($records);

        echo '<p class="ig-attendance-summary">';
        echo '<strong>' . esc_html((string) $total) . '</strong> officer record'
            . ($total !== 1 ? 's' : '');
        echo '</p>';

        echo '</div>';
    }

    /**
     * Get distinct meeting labels from both attendance tables
     *
     * Returns labels ordered by their intergroup meeting ID descending
     * so that the most recently created meetings appear first.
     *
     * @return array<string>
     */
    private function getDistinctMeetingLabels(): array
    {
        global $wpdb;

        $groupTable = TsmlIntergroupMeetingGroupAttendanceTable::getTableName();
        $officerTable = TsmlIntergroupMeetingOfficerAttendanceTable::getTableName();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from constants; cannot be parameterised with prepare()
        $labels = $wpdb->get_col(
            "SELECT meeting_label FROM ("
            . "SELECT meeting_label, intergroup_meeting_id FROM `" . esc_sql($groupTable) . "` WHERE meeting_label != '' "
            . "UNION "
            . "SELECT meeting_label, intergroup_meeting_id FROM `" . esc_sql($officerTable) . "` WHERE meeting_label != ''"
            . ") AS combined "
            . "GROUP BY meeting_label ORDER BY MAX(intergroup_meeting_id) DESC"
        );

        return is_array($labels) ? $labels : [];
    }

    /**
     * Add custom styles for the attendance page
     */
    public function addPageStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'intergroup_page_' . self::PAGE_SLUG) {
            return;
        }

        echo '<style>
            .ig-attendance-selector {
                margin: 16px 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ig-attendance-selector select {
                min-width: 280px;
                max-width: 400px;
            }

            .ig-section-heading {
                margin-top: 24px;
                margin-bottom: 8px;
                padding-bottom: 6px;
                border-bottom: 1px solid #c3c4c7;
            }

            .ig-attendance-table-wrap {
                margin-top: 4px;
                margin-bottom: 24px;
            }

            .ig-attendance-table th {
                font-weight: 600;
                padding: 10px 12px;
            }

            .ig-attendance-table td {
                padding: 10px 12px;
                vertical-align: middle;
            }

            .ig-proxy-yes {
                display: inline-block;
                padding: 2px 8px;
                background: #f0f6fc;
                color: #135e96;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
            }

            .ig-proxy-no {
                color: #999;
            }

            .ig-empty-cell {
                color: #ccc;
            }

            .ig-attendance-empty {
                color: #666;
                font-style: italic;
                padding: 20px 0;
            }

            .ig-attendance-summary {
                margin-top: 12px;
                color: #666;
            }

            .ig-attendance-table tr:hover {
                background: #f9f9f9;
            }
        </style>';
    }
}