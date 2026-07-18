<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use TsmlForUnity\IntergroupMeetings\TsmlIntergroupMeetingGroupAttendanceTable;
use TsmlForUnity\IntergroupMeetings\TsmlIntergroupMeetingOfficerAttendanceTable;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionViewFactory;

use function add_action;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_sql;
use function esc_url;
use function get_current_screen;
use function sanitize_text_field;
use function wp_create_nonce;
use function wp_unslash;

/**
 * Reports Admin
 *
 * Adds an admin submenu page under Intergroup that lets the user pick an
 * intergroup meeting from a dropdown (using the same data source as the
 * Attendance page) and download two CSV reports for that meeting:
 *   - position.csv — every position with its current member's details and
 *     whether the position was marked as attending.
 *   - group.csv    — every group with its GSR's details and whether the
 *     group (and any proxy) was marked as attending.
 */
class ReportsAdmin
{
    private IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository;
    private IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository;
    private PositionRepository $positionRepository;
    private PositionViewFactory $positionViewFactory;
    private GroupRepository $groupRepository;
    private GroupViewFactory $groupViewFactory;

    private const PAGE_SLUG = 'intergroup-reports';
    private const NONCE_ACTION = 'amber_reports_download';
    private const NONCE_FIELD = '_amber_reports_nonce';

    private const ACTION_DOWNLOAD_POSITIONS = 'download_positions_csv';
    private const ACTION_DOWNLOAD_GROUPS = 'download_groups_csv';

    /**
     * CSV column headers for the position.csv report.
     */
    private const POSITION_CSV_HEADERS = [
        'Position Name',
        'Position Long Name',
        'Position Generic Email',
        'Member Anonymous Name',
        'Member Personal Email',
        'Member Mobile',
        'Position Duration',
        'Started Service',
        'Attended',
    ];

    /**
     * CSV column headers for the group.csv report.
     */
    private const GROUP_CSV_HEADERS = [
        'Group Name',
        'Gsr Name',
        'Gsr Email Personal',
        'Gsr Phone',
        'Attended',
        'Proxy Attended',
        'Proxy Name',
    ];

    /**
     * Constructor
     *
     * @param IntergroupMeetingGroupAttendanceRepository   $groupAttendanceRepository   Group attendance repository
     * @param IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository Officer attendance repository
     * @param PositionRepository                           $positionRepository          Position repository
     * @param PositionViewFactory                          $positionViewFactory         Position view factory
     * @param GroupRepository                              $groupRepository             Group repository
     * @param GroupViewFactory                             $groupViewFactory            Group view factory
     */
    public function __construct(
        IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository,
        IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository,
        PositionRepository $positionRepository,
        PositionViewFactory $positionViewFactory,
        GroupRepository $groupRepository,
        GroupViewFactory $groupViewFactory
    ) {
        $this->groupAttendanceRepository = $groupAttendanceRepository;
        $this->officerAttendanceRepository = $officerAttendanceRepository;
        $this->positionRepository = $positionRepository;
        $this->positionViewFactory = $positionViewFactory;
        $this->groupRepository = $groupRepository;
        $this->groupViewFactory = $groupViewFactory;

        add_action('admin_menu', [$this, 'registerSubmenuPage']);
        add_action('admin_init', [$this, 'maybeHandleDownload']);
        add_action('admin_head', [$this, 'addPageStyles']);
    }

    /**
     * Register the submenu page under Intergroup
     */
    public function registerSubmenuPage(): void
    {
        add_submenu_page(
            'intergroup',
            'Reports',
            'Reports',
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
        echo '<h1 class="wp-heading-inline">Reports</h1>';

        // Meeting selector
        $this->renderMeetingSelector($labels, $selectedLabel);

        // Download section
        if (!empty($selectedLabel)) {
            $this->renderDownloadSection($selectedLabel);
        }

        echo '</div>';
    }

    /**
     * Render the meeting selector dropdown
     *
     * Uses the same logic as IntergroupMeetingAttendanceDashboard so the two
     * pages always show the same set of meetings in the same order.
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

        echo '<form method="get" action="' . esc_attr(admin_url('admin.php')) . '" class="ig-reports-selector">';
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
     * Render both download sections (positions and groups) for the selected meeting.
     *
     * @param string $meetingLabel Currently selected meeting label
     */
    private function renderDownloadSection(string $meetingLabel): void
    {
        $this->renderPositionsDownload($meetingLabel);
        $this->renderGroupsDownload($meetingLabel);
    }

    /**
     * Render the position CSV download section.
     *
     * @param string $meetingLabel Currently selected meeting label
     */
    private function renderPositionsDownload(string $meetingLabel): void
    {
        $downloadUrl = $this->buildDownloadUrl(self::ACTION_DOWNLOAD_POSITIONS, $meetingLabel);
        $filename    = $this->buildCsvFilename('position', $meetingLabel);

        echo '<h2 class="ig-section-heading">Positions Report</h2>';
        echo '<div class="ig-reports-section">';
        echo '<p>Download a <code>' . esc_html($filename) . '</code> file containing every position, '
            . 'its current member details, and whether the position was marked as '
            . 'attending <strong>' . esc_html($meetingLabel) . '</strong>.</p>';

        echo '<p><a href="' . esc_url($downloadUrl) . '" class="button button-primary">';
        echo '<span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span>';
        echo 'Download ' . esc_html($filename);
        echo '</a></p>';

        echo '</div>';
    }

    /**
     * Render the group CSV download section.
     *
     * @param string $meetingLabel Currently selected meeting label
     */
    private function renderGroupsDownload(string $meetingLabel): void
    {
        $downloadUrl = $this->buildDownloadUrl(self::ACTION_DOWNLOAD_GROUPS, $meetingLabel);
        $filename    = $this->buildCsvFilename('group', $meetingLabel);

        echo '<h2 class="ig-section-heading">Groups Report</h2>';
        echo '<div class="ig-reports-section">';
        echo '<p>Download a <code>' . esc_html($filename) . '</code> file containing every group, '
            . 'its current GSR details, and whether the group (and any proxy) was '
            . 'marked as attending <strong>' . esc_html($meetingLabel) . '</strong>.</p>';

        echo '<p><a href="' . esc_url($downloadUrl) . '" class="button button-primary">';
        echo '<span class="dashicons dashicons-download" style="vertical-align: middle; margin-right: 4px;"></span>';
        echo 'Download ' . esc_html($filename);
        echo '</a></p>';

        echo '</div>';
    }

    /**
     * Build a nonce-protected download URL for the given action and meeting.
     *
     * @param string $action       One of self::ACTION_DOWNLOAD_*
     * @param string $meetingLabel Meeting label to embed in the URL
     */
    private function buildDownloadUrl(string $action, string $meetingLabel): string
    {
        return admin_url('admin.php?' . http_build_query([
            'page'              => self::PAGE_SLUG,
            'amber_action'      => $action,
            'meeting_label'     => $meetingLabel,
            self::NONCE_FIELD   => wp_create_nonce(self::NONCE_ACTION),
        ]));
    }

    /**
     * Handle CSV download requests. Routes to the right streamer based on
     * ?amber_action=. Runs on admin_init so we can stream the file before
     * any output is sent.
     */
    public function maybeHandleDownload(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset($_GET['amber_action']) ? sanitize_text_field(wp_unslash($_GET['amber_action'])) : '';

        if ($action !== self::ACTION_DOWNLOAD_POSITIONS && $action !== self::ACTION_DOWNLOAD_GROUPS) {
            return;
        }

        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to download this report.');
        }

        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified above
        $meetingLabel = isset($_GET['meeting_label']) ? sanitize_text_field(wp_unslash($_GET['meeting_label'])) : '';

        if (empty($meetingLabel)) {
            wp_die('No meeting selected.');
        }

        if ($action === self::ACTION_DOWNLOAD_POSITIONS) {
            $this->streamPositionsCsv($meetingLabel);
        } else {
            $this->streamGroupsCsv($meetingLabel);
        }
    }

    /**
     * Build and stream the position.csv file for the given meeting label.
     *
     * @param string $meetingLabel Meeting label whose attendance is reported
     */
    private function streamPositionsCsv(string $meetingLabel): void
    {
        $rows = $this->buildPositionRows($meetingLabel);
        $this->streamCsv('position', self::POSITION_CSV_HEADERS, $rows, $meetingLabel);
    }

    /**
     * Build and stream the group.csv file for the given meeting label.
     *
     * @param string $meetingLabel Meeting label whose attendance is reported
     */
    private function streamGroupsCsv(string $meetingLabel): void
    {
        $rows = $this->buildGroupRows($meetingLabel);
        $this->streamCsv('group', self::GROUP_CSV_HEADERS, $rows, $meetingLabel);
    }

    /**
     * Send a CSV file to the browser as an attachment.
     *
     * The download filename is "{baseName}_{sanitised meeting label}.csv".
     * Spaces in the meeting label become underscores; characters that aren't
     * safe in a filename are stripped. If the label sanitises to nothing,
     * the filename falls back to "{baseName}.csv".
     *
     * @param string               $baseName     Filename stem (without extension), e.g. "position"
     * @param array<string>        $headers      CSV column headers
     * @param array<array<string>> $rows         CSV rows
     * @param string               $meetingLabel Meeting label appended to the filename
     */
    private function streamCsv(string $baseName, array $headers, array $rows, string $meetingLabel): void
    {
        $filename = $this->buildCsvFilename($baseName, $meetingLabel);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // UTF-8 BOM so Excel renders accented characters correctly
        fwrite($output, "\xEF\xBB\xBF");

        self::writeCsvRow($output, $headers);

        foreach ($rows as $row) {
            self::writeCsvRow($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Escape character for the report CSV.
     *
     * '' writes RFC 4180 CSV, which is what Excel and Sheets read. PHP's legacy
     * escape does not double a quote that follows a backslash — a note
     * containing \"quoted\" was written as "says \"hi\"", which an RFC 4180
     * reader parses as `says \hi\""`: the field ends early and the rest of the
     * row is mangled. Doubling the quotes ("says \""hi\""") round-trips
     * correctly. It is also the PHP 9 default, so this no longer relies on a
     * default that is changing underneath us.
     */
    private const CSV_ESCAPE = '';

    /**
     * Write one CSV record.
     *
     * Extracted so the escape is declared once rather than repeated at each
     * call site — repeating it is how the header and body rows could drift
     * apart — and so the escaping is reachable from a test. streamCsv() itself
     * sends headers, writes to php://output and exits, so it cannot be driven
     * directly.
     *
     * @param resource          $handle
     * @param array<int, mixed> $fields
     */
    private static function writeCsvRow($handle, array $fields): void
    {
        fputcsv($handle, $fields, ',', '"', self::CSV_ESCAPE);
    }

    /**
     * Build a filesystem-safe CSV filename of the form
     * "{baseName}_{sanitised meeting label}.csv".
     *
     * Sanitisation: whitespace runs become "_"; characters outside
     * [A-Za-z0-9_.-] are dropped; repeated underscores are collapsed and
     * stripped from the ends. If the label sanitises to an empty string,
     * the suffix is omitted entirely.
     *
     * @param string $baseName     Filename stem without extension
     * @param string $meetingLabel Raw meeting label
     */
    private function buildCsvFilename(string $baseName, string $meetingLabel): string
    {
        // Replace whitespace runs (incl. tabs, multiple spaces) with a single underscore
        $suffix = preg_replace('/\s+/u', '_', $meetingLabel);

        // Drop characters that aren't letters, digits, underscore, hyphen, or dot
        $suffix = preg_replace('/[^A-Za-z0-9_.-]/u', '', $suffix ?? '');

        // Collapse repeated underscores and trim them from the ends
        $suffix = trim(preg_replace('/_+/', '_', $suffix ?? ''), '_');

        return $suffix === ''
            ? $baseName . '.csv'
            : $baseName . '_' . $suffix . '.csv';
    }

    /**
     * Build CSV rows for every position.
     *
     * Positions with multiple current members produce one row per member.
     * Vacant positions still produce a row (with empty member fields) so the
     * report shows every position, not just filled ones.
     *
     * @param string $meetingLabel Meeting label used to determine "Attended"
     * @return array<array<string>>
     */
    private function buildPositionRows(string $meetingLabel): array
    {
        $attendedPositionIds = $this->getAttendedPositionIds($meetingLabel);
        $positions = $this->positionRepository->findAll();

        $positionViews = [];
        foreach ($positions as $position) {
            $positionView = $this->positionViewFactory->createFrom($position->getId());
            if ($positionView) {
                $positionViews[] = $positionView;
            }
        }

        // Sort alphabetically by position title to match the rest of the admin UI
        usort($positionViews, function ($a, $b) {
            return strcasecmp($a->getTitle() ?? '', $b->getTitle() ?? '');
        });

        $rows = [];

        foreach ($positionViews as $positionView) {
            $position = $positionView->getPosition();
            $positionId = $position->getId();

            $positionName     = $positionView->getTitle() ?? '';
            $positionLongName = $position->getLongName() ?? '';
            $positionEmail    = $positionView->getPositionEmail() ?? '';
            $termYears        = $position->getTermYears();
            $duration         = $this->formatDuration($termYears);
            $startedService   = $this->formatStartedService($positionView->getRotationDate(), $termYears);
            $attended         = in_array($positionId, $attendedPositionIds, true) ? 'Yes' : 'No';

            $members = $positionView->getMembers();

            if (empty($members)) {
                // Vacant position — single row with empty member fields
                $rows[] = [
                    $positionName,
                    $positionLongName,
                    $positionEmail,
                    '',
                    '',
                    '',
                    $duration,
                    $startedService,
                    $attended,
                ];
                continue;
            }

            foreach ($members as $member) {
                $rows[] = [
                    $positionName,
                    $positionLongName,
                    $positionEmail,
                    $member->getAnonymousName() ?? '',
                    $member->getPersonalEmail() ?? '',
                    $member->getMobileNumber() ?? '',
                    $duration,
                    $startedService,
                    $attended,
                ];
            }
        }

        return $rows;
    }

    /**
     * Get the set of position IDs that were marked as attending the meeting.
     *
     * Officer attendance records store the position id in the `officer_id`
     * column (see IntergroupMeetingAdmin::syncOfficerAttendance).
     *
     * @param string $meetingLabel Meeting label to filter by
     * @return array<int>
     */
    private function getAttendedPositionIds(string $meetingLabel): array
    {
        $records = $this->officerAttendanceRepository->findAll(['meeting_label' => $meetingLabel]);

        $positionIds = [];
        foreach ($records as $record) {
            $positionIds[] = (int) $record->getOfficerId();
        }

        return array_values(array_unique($positionIds));
    }

    /**
     * Build CSV rows for every group.
     *
     * Groups with multiple current GSRs produce one row per GSR. Groups with
     * no current GSR still produce a row (with empty GSR fields) so the
     * report shows every group, not just GSR-staffed ones. Proxy info comes
     * from the attendance record for the selected meeting.
     *
     * @param string $meetingLabel Meeting label used to determine "Attended"
     * @return array<array<string>>
     */
    private function buildGroupRows(string $meetingLabel): array
    {
        $attendanceByGroupId = $this->getGroupAttendanceMap($meetingLabel);
        $groups = $this->groupRepository->findAll();

        // Sort alphabetically by group title
        usort($groups, function ($a, $b) {
            return strcasecmp($a->getTitle() ?? '', $b->getTitle() ?? '');
        });

        $rows = [];

        foreach ($groups as $group) {
            $groupId = $group->getId();
            $groupName = $group->getTitle() ?? '';

            // Resolve current GSR member(s) from the live group view
            $gsrMembers = $this->resolveGroupGsrMembers($groupId);

            // Attendance details (one record per group per meeting)
            $attendanceRecord = $attendanceByGroupId[$groupId] ?? null;
            $attended         = $attendanceRecord !== null ? 'Yes' : 'No';
            $proxyAttended    = ($attendanceRecord !== null && $attendanceRecord->isGsrProxy()) ? 'Yes' : 'No';
            $proxyName        = $attendanceRecord !== null ? ($attendanceRecord->getGsrProxyName() ?? '') : '';

            if (empty($gsrMembers)) {
                // Group with no current GSR — single row with empty GSR fields
                $rows[] = [
                    $groupName,
                    '',
                    '',
                    '',
                    $attended,
                    $proxyAttended,
                    $proxyName,
                ];
                continue;
            }

            foreach ($gsrMembers as $gsrMember) {
                $rows[] = [
                    $groupName,
                    $gsrMember->getAnonymousName() ?? '',
                    $gsrMember->getPersonalEmail() ?? '',
                    $gsrMember->getMobileNumber() ?? '',
                    $attended,
                    $proxyAttended,
                    $proxyName,
                ];
            }
        }

        return $rows;
    }

    /**
     * Build a map of groupId => group attendance record for the given meeting.
     *
     * Each group attends a meeting at most once, so a flat map is safe.
     *
     * @param string $meetingLabel Meeting label to filter by
     * @return array<int, mixed>
     */
    private function getGroupAttendanceMap(string $meetingLabel): array
    {
        $records = $this->groupAttendanceRepository->findAll(['meeting_label' => $meetingLabel]);

        $map = [];
        foreach ($records as $record) {
            $map[(int) $record->getGroupId()] = $record;
        }

        return $map;
    }

    /**
     * Resolve the current GSR member(s) for a group, using the live group view.
     *
     * Mirrors the logic in IntergroupMeetingAdmin::resolveGroupGsrs(): a member
     * is a GSR if isGSR() returns true. Returns an empty array if the group
     * view can't be built.
     *
     * @param int $groupId Group post ID
     * @return array<\Unity\Members\Interfaces\Member>
     */
    private function resolveGroupGsrMembers(int $groupId): array
    {
        $groupView = $this->groupViewFactory->createFrom($groupId);
        if (!$groupView) {
            return [];
        }

        $gsrs = [];
        foreach ($groupView->getMembers() as $member) {
            if ($member->isGSR()) {
                $gsrs[] = $member;
            }
        }

        return $gsrs;
    }

    /**
     * Format the position duration as a human-readable string.
     *
     * @param mixed $termYears Term length in years
     */
    private function formatDuration($termYears): string
    {
        if ($termYears === null || $termYears === '' || (int) $termYears <= 0) {
            return '';
        }

        $years = (int) $termYears;
        return $years . ' year' . ($years === 1 ? '' : 's');
    }

    /**
     * Format the "Started Service" date as YYYY-MM-DD.
     *
     * Calculated as rotationDate − termYears. If either input is missing,
     * returns an empty string.
     *
     * @param \DateTimeInterface|null $rotationDate Rotation date
     * @param mixed                   $termYears   Term length in years
     */
    private function formatStartedService($rotationDate, $termYears): string
    {
        if (!$rotationDate instanceof \DateTimeInterface) {
            return '';
        }

        if ($termYears === null || $termYears === '' || (int) $termYears <= 0) {
            return '';
        }

        $start = \DateTimeImmutable::createFromFormat(
            'Y-m-d',
            $rotationDate->format('Y-m-d')
        );

        if (!$start) {
            return '';
        }

        $start = $start->modify('-' . (int) $termYears . ' years');

        return $start->format('Y-m-d');
    }

    /**
     * Get distinct meeting labels from both attendance tables.
     *
     * Mirrors IntergroupMeetingAttendanceDashboard::getDistinctMeetingLabels()
     * so the dropdown shows the same meetings in the same order.
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
     * Add custom styles for the Reports page
     */
    public function addPageStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'intergroup_page_' . self::PAGE_SLUG) {
            return;
        }

        echo '<style>
            .ig-reports-selector {
                margin: 16px 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ig-reports-selector select {
                min-width: 280px;
                max-width: 400px;
            }

            .ig-section-heading {
                margin-top: 24px;
                margin-bottom: 8px;
                padding-bottom: 6px;
                border-bottom: 1px solid #c3c4c7;
            }

            .ig-reports-section {
                margin-top: 4px;
                margin-bottom: 24px;
                padding: 16px;
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }

            .ig-reports-section p {
                margin-top: 0;
            }

            .ig-reports-section code {
                background: #f0f0f1;
                padding: 1px 6px;
                border-radius: 3px;
            }
        </style>';
    }
}
