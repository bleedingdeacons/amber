<?php

declare(strict_types=1);

namespace Amber\Admin\Meetings;

use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

use function add_action;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function get_option;
use function wp_add_dashboard_widget;

/**
 * Meeting Dashboard Widget
 *
 * Adds a dashboard panel listing all meetings with their groups,
 * sorted by day of week and start time.
 */
class MeetingDashboard
{
    private MeetingRepository $meetingRepository;
    private GroupRepository $groupRepository;

    /**
     * Day order mapping: Sunday (0) through Saturday (6)
     * Meeting::getDay() returns 0-6 where Sunday=0
     */
    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    /**
     * Constructor
     *
     * @param MeetingRepository $meetingRepository Meeting repository
     * @param GroupRepository $groupRepository Group repository
     */
    public function __construct(
        MeetingRepository $meetingRepository,
        GroupRepository $groupRepository
    ) {
        $this->meetingRepository = $meetingRepository;
        $this->groupRepository = $groupRepository;

        // Register hooks
        add_action('wp_dashboard_setup', [$this, 'registerDashboardWidget']);
        add_action('admin_head', [$this, 'addDashboardStyles']);
    }

    /**
     * Register the dashboard widget
     */
    public function registerDashboardWidget(): void
    {
        wp_add_dashboard_widget(
            'groups_meetings_dashboard',
            'Groups & Meetings',
            [$this, 'renderDashboardWidget'],
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render the dashboard widget content
     */
    public function renderDashboardWidget(): void
    {
        // Get ALL meetings directly — no limit
        $meetings = $this->meetingRepository->findAll([
            'posts_per_page' => -1,
        ]);

        if (empty($meetings)) {
            echo '<p>No meetings found.</p>';
            return;
        }

        // Build a group cache keyed by group ID to avoid repeated lookups
        $groupCache = [];

        // Build meeting rows with their associated group
        $meetingRows = [];
        foreach ($meetings as $meeting) {
            $group = $this->resolveGroup($meeting, $groupCache);
            $meetingRows[] = [
                'meeting' => $meeting,
                'group' => $group,
            ];
        }

        // Sort by day of week (relative to WordPress start_of_week), then by start time
        $startOfWeek = (int) get_option('start_of_week', 0);
        usort($meetingRows, function (array $a, array $b) use ($startOfWeek) {
            $dayA = ($a['meeting']->getDay() - $startOfWeek + 7) % 7;
            $dayB = ($b['meeting']->getDay() - $startOfWeek + 7) % 7;

            if ($dayA !== $dayB) {
                return $dayA - $dayB;
            }

            return strcmp($a['meeting']->getTime(), $b['meeting']->getTime());
        });

        echo '<div class="meeting-dashboard-widget">';
        echo '<table class="widefat striped meeting-schedule-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Day</th>';
        echo '<th>Group</th>';
        echo '<th>Meeting</th>';
        echo '<th>Time</th>';
        echo '<th>Location</th>';
        echo '<th>Contacts</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        $currentDay = -1;
        foreach ($meetingRows as $row) {
            $this->renderMeetingRow($row['meeting'], $row['group'], $currentDay);
            $currentDay = $row['meeting']->getDay();
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Resolve the group for a meeting using the group_id meta field
     *
     * @param Meeting $meeting Meeting object
     * @param array<int, Group|null> &$cache Group cache to avoid repeated lookups
     * @return Group|null The group or null if not assigned
     */
    private function resolveGroup(Meeting $meeting, array &$cache): ?Group
    {
        $groupId = $this->getGroupIdForMeeting($meeting);

        if ($groupId === null) {
            return null;
        }

        if (array_key_exists($groupId, $cache)) {
            return $cache[$groupId];
        }

        $group = $this->groupRepository->findById($groupId);
        $cache[$groupId] = $group;

        return $group;
    }

    /**
     * Get the group ID for a meeting from its meta data
     *
     * @param Meeting $meeting Meeting object
     * @return int|null Group ID or null if not assigned
     */
    private function getGroupIdForMeeting(Meeting $meeting): ?int
    {
        $meta = $meeting->getMeta();

        // Meta values from get_post_custom are stored as arrays
        $groupId = $meta['group_id'][0] ?? ($meta['group_id'] ?? null);

        if (empty($groupId)) {
            return null;
        }

        $id = (int) $groupId;
        return $id > 0 ? $id : null;
    }

    /**
     * Render a single meeting row
     *
     * @param Meeting $meeting Meeting object
     * @param Group|null $group Group object or null
     * @param int $previousDay The day value of the previous row (-1 for first row)
     */
    private function renderMeetingRow(
        Meeting $meeting,
        ?Group $group,
        int $previousDay
    ): void {
        $day = $meeting->getDay();
        $isNewDay = ($day !== $previousDay);

        $rowClass = $isNewDay ? ' class="meeting-day-start"' : '';
        echo '<tr' . $rowClass . '>';

        // Day column — show day name only on first occurrence
        echo '<td class="meeting-day">';
        if ($isNewDay) {
            $dayName = self::DAY_NAMES[$day] ?? 'Unknown';
            echo '<strong>' . esc_html($dayName) . '</strong>';
        }
        echo '</td>';

        // Group name column
        echo '<td class="meeting-group copyable">';
        if ($group !== null) {
            $groupEditLink = get_edit_post_link($group->getId());
            if ($groupEditLink) {
                echo '<a href="' . esc_url($groupEditLink) . '">';
                echo esc_html($group->getTitle());
                echo '</a>';
            } else {
                echo esc_html($group->getTitle());
            }
        } else {
            echo '<span class="no-group">—</span>';
        }
        echo '</td>';

        // Meeting name column
        echo '<td class="meeting-name copyable">';
        $meetingEditLink = get_edit_post_link($meeting->getId());
        if ($meetingEditLink) {
            echo '<a href="' . esc_url($meetingEditLink) . '">';
            echo '<strong>' . esc_html($meeting->getName()) . '</strong>';
            echo '</a>';
        } else {
            echo '<strong>' . esc_html($meeting->getName()) . '</strong>';
        }
        if ($meeting->isOnline()) {
            echo ' <span class="meeting-badge meeting-online">Online</span>';
        }
        echo '</td>';

        // Time column
        echo '<td class="meeting-time">';
        $this->renderTime($meeting);
        echo '</td>';

        // Location column
        echo '<td class="meeting-location copyable">';
        $this->renderLocation($meeting);
        echo '</td>';

        // Contacts column — prefer group contacts, fall back to meeting contacts
        echo '<td class="meeting-contacts">';
        $this->renderContacts($meeting, $group);
        echo '</td>';

        echo '</tr>';
    }

    /**
     * Render the time cell content
     *
     * @param Meeting $meeting Meeting object
     */
    private function renderTime(Meeting $meeting): void
    {
        $startTime = $meeting->getTime();
        $endTime = $meeting->getEndTime();

        if (empty($startTime)) {
            echo '<span class="no-time">—</span>';
            return;
        }

        echo esc_html($this->formatTime($startTime));

        if (!empty($endTime)) {
            echo ' – ' . esc_html($this->formatTime($endTime));
        }
    }

    /**
     * Format a time string for display
     *
     * Converts 24h time (e.g. "19:00") to 12h format (e.g. "7:00 PM")
     *
     * @param string $time Time string
     * @return string Formatted time
     */
    private function formatTime(string $time): string
    {
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return $time;
        }
        return date('g:i A', $timestamp);
    }

    /**
     * Render the location cell content
     *
     * @param Meeting $meeting Meeting object
     */
    private function renderLocation(Meeting $meeting): void
    {
        $location = $meeting->getLocation();

        if ($location === null) {
            if ($meeting->isOnline()) {
                $onlineLink = $meeting->getOnlineLink();
                if (!empty($onlineLink)) {
                    echo '<a href="' . esc_url($onlineLink) . '" target="_blank" rel="noopener">Online Meeting</a>';
                } else {
                    echo '<span class="meeting-online-label">Online</span>';
                }
            } else {
                echo '<span class="no-location">—</span>';
            }
            return;
        }

        echo '<strong>' . esc_html($location->getName()) . '</strong>';

        $address = $location->getFormattedAddress();
        if (!empty($address)) {
            echo '<br><span class="meeting-location-address copyable">' . esc_html($address) . '</span>';
        }
    }

    /**
     * Render the contacts cell content
     *
     * Prefers group contacts over meeting contacts. Displays up to 3 contacts
     * showing name and phone number.
     *
     * @param Meeting $meeting Meeting object
     * @param Group|null $group Group object or null
     */
    private function renderContacts(Meeting $meeting, ?Group $group): void
    {
        $contacts = [];

        // Prefer group contacts if available
        if ($group !== null) {
            $contacts = $group->getContacts();
        }

        // Fall back to meeting contacts if group has none
        if (empty($contacts)) {
            $contacts = $meeting->getContacts();
        }

        if (empty($contacts)) {
            echo '<span class="no-contacts">—</span>';
            return;
        }

        // Display up to 3 contacts
        $contacts = array_slice($contacts, 0, 3);

        foreach ($contacts as $index => $contact) {
            if ($index > 0) {
                echo '<br>';
            }

            $name = $contact->getName();
            $phone = $contact->getPhone();

            if (!empty($name)) {
                echo '<span class="contact-name copyable">' . esc_html($name) . '</span>';
            }

            if (!empty($phone)) {
                if (!empty($name)) {
                    echo ' ';
                }
                echo '<span class="contact-phone copyable">' . esc_html($phone) . '</span>';
            }
        }
    }

    /**
     * Add custom styles for the dashboard widget
     */
    public function addDashboardStyles(): void
    {
        $screen = get_current_screen();

        // Only add styles on the dashboard page
        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        echo '<style>
            .meeting-dashboard-widget {
                margin: -12px -12px 0 -12px;
            }

            .meeting-schedule-table {
                margin: 0;
                border: none;
            }

            .meeting-schedule-table th {
                background: #f9f9f9;
                font-weight: 600;
                padding: 8px 10px;
            }

            .meeting-schedule-table td {
                padding: 8px 10px;
                vertical-align: top;
            }

            .meeting-schedule-table .meeting-day {
                width: 10%;
                white-space: nowrap;
            }

            .meeting-schedule-table .meeting-group {
                width: 18%;
            }

            .meeting-schedule-table .meeting-name {
                width: 20%;
            }

            .meeting-schedule-table .meeting-time {
                width: 14%;
                white-space: nowrap;
            }

            .meeting-schedule-table .meeting-location {
                width: 18%;
            }

            .meeting-schedule-table .meeting-contacts {
                width: 20%;
            }

            .meeting-schedule-table tr.meeting-day-start td {
                border-top: 2px solid #ddd;
            }

            .meeting-badge {
                display: inline-block;
                padding: 1px 6px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.3px;
                vertical-align: middle;
                margin-left: 4px;
            }

            .meeting-badge.meeting-online {
                background: #2271b1;
                color: white;
            }

            .no-group,
            .no-time,
            .no-location,
            .no-contacts {
                color: #ccc;
            }

            .contact-name {
                font-weight: 500;
            }

            .contact-phone {
                font-size: 12px;
                color: #666;
            }

            .meeting-online-label {
                color: #2271b1;
                font-style: italic;
            }

            .meeting-location-address {
                font-size: 12px;
                color: #666;
            }

            .meeting-schedule-table tr:hover {
                background: #f9f9f9;
            }
        </style>';
    }
}