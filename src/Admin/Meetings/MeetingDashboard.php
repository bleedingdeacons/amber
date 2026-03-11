<?php

declare(strict_types=1);

namespace Amber\Admin\Meetings;

use Amber\Managers\MeetingReconciler;
use Amber\Models\ReconciliationResult;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

use function add_action;
use function esc_attr;
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
 * sorted by day of week and start time. Each meeting card displays
 * a reconciliation status badge indicating whether the meeting was
 * found in the national AAGBDB listing (via MeetingReconciler).
 */
class MeetingDashboard
{
    private MeetingRepository $meetingRepository;
    private GroupRepository $groupRepository;
    private ?MeetingReconciler $meetingReconciler;

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
     * @param MeetingRepository   $meetingRepository  Meeting repository
     * @param GroupRepository     $groupRepository    Group repository
     * @param MeetingReconciler|null $meetingReconciler  Reconciler (nullable if Concordance is unavailable)
     */
    public function __construct(
        MeetingRepository $meetingRepository,
        GroupRepository $groupRepository,
        ?MeetingReconciler $meetingReconciler = null
    ) {
        $this->meetingRepository   = $meetingRepository;
        $this->groupRepository     = $groupRepository;
        $this->meetingReconciler   = $meetingReconciler;

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

        // Run reconciliation to build a per-meeting status lookup
        $reconLookup = $this->buildReconciliationLookup();

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

        // Group meetings by day
        $meetingsByDay = [];
        foreach ($meetingRows as $row) {
            $day = $row['meeting']->getDay();
            if (!isset($meetingsByDay[$day])) {
                $meetingsByDay[$day] = [];
            }
            $meetingsByDay[$day][] = $row;
        }

        echo '<div class="meeting-dashboard-widget">';

        foreach ($meetingsByDay as $day => $rows) {
            $this->renderDaySection($day, $rows, $reconLookup);
        }

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
     * Render a day section with all meetings for that day
     *
     * @param int   $day         Day of week (0-6)
     * @param array $rows        Array of meeting/group pairs
     * @param array $reconLookup Reconciliation status keyed by meeting ID
     */
    private function renderDaySection(int $day, array $rows, array $reconLookup): void
    {
        $dayName = self::DAY_NAMES[$day] ?? 'Unknown';
        $count = count($rows);

        echo '<details class="meeting-day-section" open>';
        echo '<summary class="meeting-day-header">';
        echo '<span class="meeting-day-header-title">' . esc_html($dayName) . '</span>';
        echo '<span class="meeting-day-header-count">' . $count . '</span>';
        echo '</summary>';

        echo '<div class="meeting-day-body">';
        foreach ($rows as $row) {
            $meetingId = $row['meeting']->getId();
            $reconStatus = $reconLookup[$meetingId] ?? null;
            $this->renderMeetingCard($row['meeting'], $row['group'], $reconStatus);
        }
        echo '</div>';

        echo '</details>';
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
     * Render a single meeting card
     *
     * @param Meeting    $meeting     Meeting object
     * @param Group|null $group       Group object or null
     * @param array|null $reconStatus Reconciliation status for this meeting, or null if unavailable
     */
    private function renderMeetingCard(Meeting $meeting, ?Group $group, ?array $reconStatus): void
    {
        echo '<div class="meeting-card">';

        // Header with meeting name, time, and reconciliation status
        echo '<div class="meeting-card-header">';

        // Meeting name with online badge
        echo '<div class="meeting-card-title">';
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
        echo '</div>';

        // Time
        echo '<div class="meeting-card-time">';
        $this->renderTime($meeting);
        echo '</div>';

        // Reconciliation status badge
        $this->renderReconciliationBadge($reconStatus);

        echo '</div>'; // .meeting-card-header

        // Content area with grid layout
        echo '<div class="meeting-card-content">';

        // Group
        echo '<div class="meeting-card-field">';
        echo '<div class="field-label">Group</div>';
        echo '<div class="field-value copyable">';
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
            echo '<span class="no-data">—</span>';
        }
        echo '</div>';
        echo '</div>';

        // Location
        echo '<div class="meeting-card-field">';
        echo '<div class="field-label">Location</div>';
        echo '<div class="field-value copyable">';
        $this->renderLocation($meeting);
        echo '</div>';
        echo '</div>';

        // National Listing (show matched national name when available)
        echo '<div class="meeting-card-field">';
        echo '<div class="field-label">National Listing</div>';
        echo '<div class="field-value">';
        $this->renderNationalMatch($reconStatus);
        echo '</div>';
        echo '</div>';

        // Contacts
        echo '<div class="meeting-card-field">';
        echo '<div class="field-label">Contacts</div>';
        echo '<div class="field-value">';
        $this->renderContacts($meeting, $group);
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .meeting-card-content

        echo '</div>'; // .meeting-card
    }

    /**
     * Build a per-meeting reconciliation status lookup.
     *
     * Returns an associative array keyed by local meeting ID with a status
     * entry for each meeting. If the reconciler is unavailable (Concordance
     * not active) or the API call fails, returns an empty array so the
     * dashboard degrades gracefully.
     *
     * Status array shape:
     *   'type'           => 'matched' | 'possible' | 'local_only'
     *   'national_name'  => string|null
     *   'national_id'    => int|null
     *   'score'          => float|null   (name similarity, matched only)
     *   'notes'          => string[]     (e.g. "Weak name match", "End time mismatch")
     *
     * @return array<int, array>
     */
    private function buildReconciliationLookup(): array
    {
        if ($this->meetingReconciler === null) {
            return [];
        }

        try {
            $result = $this->meetingReconciler->reconcile();
        } catch (\Throwable $e) {
            error_log('MeetingDashboard: Reconciliation failed — ' . $e->getMessage());
            return [];
        }

        $lookup = [];

        // Confident matches
        foreach ($result->getMatches() as $match) {
            $lookup[$match['local_id']] = [
                'type'          => 'matched',
                'national_name' => $match['national_name'],
                'national_id'   => $match['national_id'],
                'score'         => $match['score'],
                'notes'         => $match['notes'],
            ];
        }

        // Possible matches (day + time only, name diverges)
        foreach ($result->getPossibles() as $possible) {
            // A meeting can appear in multiple possible rows; keep the first
            if (!isset($lookup[$possible['local_id']])) {
                $lookup[$possible['local_id']] = [
                    'type'          => 'possible',
                    'national_name' => $possible['national_name'],
                    'national_id'   => $possible['national_id'],
                    'score'         => null,
                    'notes'         => [],
                ];
            }
        }

        // Local-only (unmatched)
        foreach ($result->getLocalOnly() as $local) {
            if (!isset($lookup[$local['id']])) {
                $lookup[$local['id']] = [
                    'type'          => 'local_only',
                    'national_name' => null,
                    'national_id'   => null,
                    'score'         => null,
                    'notes'         => [$local['reason']],
                ];
            }
        }

        return $lookup;
    }

    /**
     * Render a small reconciliation status badge next to the meeting name.
     *
     * @param array|null $reconStatus Status entry from the lookup, or null
     */
    private function renderReconciliationBadge(?array $reconStatus): void
    {
        if ($reconStatus === null) {
            return;
        }

        $type = $reconStatus['type'];
        $notes = $reconStatus['notes'] ?? [];
        $tooltip = '';

        switch ($type) {
            case 'matched':
                $label = 'AAGBDB';
                $class = 'recon-matched';
                if (!empty($notes)) {
                    $label = 'AAGBDB ~';
                    $class = 'recon-partial';
                    $tooltip = implode('; ', $notes);
                }
                break;

            case 'possible':
                $label = 'AAGBDB ?';
                $class = 'recon-possible';
                $tooltip = 'Day & time match only — name differs';
                break;

            case 'local_only':
                $label = 'Not Listed';
                $class = 'recon-missing';
                $tooltip = implode('; ', $notes);
                break;

            default:
                return;
        }

        echo ' <span class="meeting-badge ' . esc_attr($class) . '"';
        if ($tooltip !== '') {
            echo ' title="' . esc_attr($tooltip) . '"';
        }
        echo '>' . esc_html($label) . '</span>';
    }

    /**
     * Render the national listing field value inside a meeting card.
     *
     * @param array|null $reconStatus Status entry from the lookup, or null
     */
    private function renderNationalMatch(?array $reconStatus): void
    {
        if ($reconStatus === null) {
            echo '<span class="no-data">—</span>';
            return;
        }

        $type = $reconStatus['type'];
        $nationalName = $reconStatus['national_name'] ?? null;

        switch ($type) {
            case 'matched':
                echo esc_html($nationalName ?? 'Unknown');
                $notes = $reconStatus['notes'] ?? [];
                if (!empty($notes)) {
                    echo '<br><span class="recon-note">' . esc_html(implode('; ', $notes)) . '</span>';
                }
                break;

            case 'possible':
                echo '<span class="recon-note-possible">' . esc_html($nationalName ?? 'Unknown') . '</span>';
                echo '<br><span class="recon-note">Possible match — name differs</span>';
                break;

            case 'local_only':
                $reason = $reconStatus['notes'][0] ?? 'Not found';
                echo '<span class="recon-note-missing">' . esc_html($reason) . '</span>';
                break;

            default:
                echo '<span class="no-data">—</span>';
        }
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

            .meeting-day-section {
                margin-bottom: 20px;
            }

            .meeting-day-section:last-child {
                margin-bottom: 0;
            }

            .meeting-day-header {
                background: #2271b1;
                color: white;
                font-weight: 600;
                font-size: 13px;
                padding: 8px 16px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                margin: 0 0 8px 0;
                border-radius: 3px;
                cursor: pointer;
                list-style: none;
                display: flex;
                align-items: center;
                justify-content: space-between;
                user-select: none;
            }

            .meeting-day-header::-webkit-details-marker {
                display: none;
            }

            .meeting-day-header::before {
                content: "▾";
                margin-right: 8px;
                font-size: 12px;
                transition: transform 0.2s;
                flex-shrink: 0;
            }

            .meeting-day-section:not([open]) > .meeting-day-header::before {
                transform: rotate(-90deg);
            }

            .meeting-day-header:hover {
                background: #135e96;
            }

            .meeting-day-header-title {
                flex: 1;
            }

            .meeting-day-header-count {
                background: rgba(255, 255, 255, 0.25);
                padding: 1px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 700;
                margin-left: 8px;
                flex-shrink: 0;
            }

            .meeting-day-body {
                padding-top: 0;
            }

            .meeting-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                margin: 0 0 8px 0;
                transition: box-shadow 0.2s;
            }

            .meeting-card:hover {
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .meeting-card:last-child {
                margin-bottom: 0;
            }

            .meeting-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: #f9f9f9;
                border-bottom: 1px solid #e0e0e0;
                border-radius: 4px 4px 0 0;
                gap: 12px;
            }

            .meeting-card-title {
                flex: 1;
                min-width: 0;
                font-size: 14px;
            }

            .meeting-card-title a {
                text-decoration: none;
                color: #2271b1;
            }

            .meeting-card-title a:hover {
                color: #135e96;
            }

            .meeting-card-time {
                flex-shrink: 0;
                font-size: 13px;
                color: #333;
                white-space: nowrap;
                font-weight: 500;
            }

            .meeting-card-content {
                padding: 12px 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
            }

            .meeting-card-field {
                min-width: 0;
            }

            .meeting-card-field-full {
                grid-column: 1 / -1;
            }

            .field-label {
                font-size: 10px;
                text-transform: uppercase;
                color: #666;
                font-weight: 600;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }

            .field-value {
                font-size: 13px;
                line-height: 1.5;
                word-wrap: break-word;
            }

            .field-value a {
                color: #2271b1;
                text-decoration: none;
            }

            .field-value a:hover {
                color: #135e96;
                text-decoration: underline;
            }

            .meeting-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                font-size: 10px;
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

            .no-data,
            .no-time,
            .no-location,
            .no-contacts {
                color: #999;
                font-style: italic;
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

            /* Reconciliation badges */
            .meeting-badge.recon-matched {
                background: #00a32a;
                color: white;
            }

            .meeting-badge.recon-partial {
                background: #dba617;
                color: white;
            }

            .meeting-badge.recon-possible {
                background: #72aee6;
                color: white;
            }

            .meeting-badge.recon-missing {
                background: #d63638;
                color: white;
            }

            .recon-note {
                font-size: 11px;
                color: #996800;
                font-style: italic;
            }

            .recon-note-possible {
                font-style: italic;
            }

            .recon-note-missing {
                color: #d63638;
                font-size: 12px;
            }

            .meeting-location-address {
                font-size: 12px;
                color: #666;
                display: block;
                margin-top: 2px;
            }

            @media (max-width: 600px) {
                .meeting-card-content {
                    grid-template-columns: 1fr;
                }
            }
        </style>';
    }
}