<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;

use function add_action;
use function add_submenu_page;
use function esc_attr;
use function esc_html;
use function get_current_screen;

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
    private IntergroupMeetingRepository $intergroupMeetingRepository;
    private IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository;
    private IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository;

    private const PAGE_SLUG = 'intergroup-attendance';

    /**
     * Constructor
     *
     * @param IntergroupMeetingRepository $intergroupMeetingRepository Intergroup meeting repository
     * @param IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository Group attendance repository
     * @param IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository Officer attendance repository
     */
    public function __construct(
        IntergroupMeetingRepository $intergroupMeetingRepository,
        IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository,
        IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository
    ) {
        $this->intergroupMeetingRepository = $intergroupMeetingRepository;
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
        $meetings = $this->intergroupMeetingRepository->findAll();

        // Sort by date descending (most recent first)
        usort($meetings, function (IntergroupMeeting $a, IntergroupMeeting $b) {
            $dateA = $a->getDate();
            $dateB = $b->getDate();

            if (empty($dateA) && empty($dateB)) {
                return 0;
            }
            if (empty($dateA)) {
                return 1;
            }
            if (empty($dateB)) {
                return -1;
            }

            return strcmp($dateB, $dateA);
        });

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $selectedMeetingId = isset($_GET['meeting_id']) ? (int) $_GET['meeting_id'] : 0;

        // Default to the most recent meeting if none selected
        if ($selectedMeetingId === 0 && !empty($meetings)) {
            $selectedMeetingId = $meetings[0]->getId();
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Intergroup Meeting Attendance</h1>';

        // Meeting selector form
        $this->renderMeetingSelector($meetings, $selectedMeetingId);

        // Attendance tables
        if ($selectedMeetingId > 0) {
            $this->renderGroupAttendanceTable($selectedMeetingId);
            $this->renderOfficerAttendanceTable($selectedMeetingId);
        }

        echo '</div>';
    }

    /**
     * Render the meeting selector dropdown
     *
     * @param array<IntergroupMeeting> $meetings   All intergroup meetings
     * @param int                      $selectedId Currently selected meeting ID
     */
    private function renderMeetingSelector(array $meetings, int $selectedId): void
    {
        if (empty($meetings)) {
            echo '<p>No intergroup meetings found.</p>';
            return;
        }

        echo '<form method="get" action="' . esc_attr(admin_url('admin.php')) . '" class="ig-attendance-selector">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::PAGE_SLUG) . '">';
        echo '<label for="meeting_id"><strong>Intergroup Meeting:</strong></label> ';
        echo '<select name="meeting_id" id="meeting_id" onchange="this.form.submit()">';

        foreach ($meetings as $meeting) {
            $id = $meeting->getId();
            $date = $meeting->getDate();
            $title = $meeting->getTitle();

            $formattedDate = !empty($date) ? $this->formatDate($date) : '';

            if (!empty($title) && !empty($formattedDate)) {
                $label = $title . ' — ' . $formattedDate;
            } elseif (!empty($title)) {
                $label = $title;
            } elseif (!empty($formattedDate)) {
                $label = $formattedDate;
            } else {
                $label = 'Meeting (ID: ' . $id . ')';
            }

            $selected = $id === $selectedId ? ' selected' : '';
            echo '<option value="' . esc_attr((string) $id) . '"' . $selected . '>'
                . esc_html($label)
                . '</option>';
        }

        echo '</select>';
        echo '<noscript><button type="submit" class="button">View</button></noscript>';
        echo '</form>';
    }

    /**
     * Render the group attendance table for a given intergroup meeting
     *
     * @param int $meetingId Intergroup meeting ID
     */
    private function renderGroupAttendanceTable(int $meetingId): void
    {
        $records = $this->groupAttendanceRepository->findByIntergroupMeeting($meetingId);

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
     * Render the officer attendance table for a given intergroup meeting
     *
     * @param int $meetingId Intergroup meeting ID
     */
    private function renderOfficerAttendanceTable(int $meetingId): void
    {
        $records = $this->officerAttendanceRepository->findByIntergroupMeeting($meetingId);

        echo '<h2 class="ig-section-heading">Officer Attendance</h2>';
        echo '<div class="ig-attendance-table-wrap">';

        if (empty($records)) {
            echo '<p class="ig-attendance-empty">No officer attendance records for this meeting.</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped ig-attendance-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Officer Name</th>';
        echo '<th>Position</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($records as $record) {
            echo '<tr>';

            // Officer Name
            echo '<td>' . esc_html($record->getOfficerName()) . '</td>';

            // Position
            echo '<td>';
            $positionName = $record->getPositionName();
            if (!empty($positionName)) {
                echo esc_html($positionName);
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

        echo '<p class="ig-attendance-summary">';
        echo '<strong>' . esc_html((string) $total) . '</strong> officer record'
            . ($total !== 1 ? 's' : '');
        echo '</p>';

        echo '</div>';
    }

    /**
     * Format a date string for display
     *
     * @param string $date Date string (Y-m-d)
     * @return string Formatted date or original string on failure
     */
    private function formatDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('F j, Y', $timestamp);
        }
        return $date;
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
