<?php

declare(strict_types=1);

namespace Amber\Admin;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use TsmlForUnity\IntergroupMeetings\TsmlIntergroupMeetingGroupAttendanceTable;
use TsmlForUnity\IntergroupMeetings\TsmlIntergroupMeetingOfficerAttendanceTable;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberFactory;
use Unity\Members\Interfaces\MemberRepository;

use function add_action;
use function add_submenu_page;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_sql;
use function esc_url;
use function get_current_screen;
use function sanitize_text_field;
use function wp_create_nonce;
use function wp_get_current_user;
use function wp_redirect;
use function wp_safe_redirect;
use function wp_unslash;

/**
 * Developer Dashboard
 *
 * Adds an admin submenu page with developer / maintenance utilities.
 * Currently provides a button to delete all intergroup meeting
 * attendance records (both group and officer tables).
 */
class DeveloperDashboard
{
    private const PAGE_SLUG = 'developer';
    private const NONCE_ACTION = 'amber_developer_action';
    private const NONCE_FIELD = '_amber_developer_nonce';

    private MemberRepository $memberRepository;
    private MemberFactory $memberFactory;

    public function __construct(MemberRepository $memberRepository, MemberFactory $memberFactory)
    {
        $this->memberRepository = $memberRepository;
        $this->memberFactory = $memberFactory;

        add_action('admin_menu', [$this, 'registerSubmenuPage']);
        add_action('admin_init', [$this, 'handleActions']);
        add_action('admin_head', [$this, 'addPageStyles']);
    }

    /**
     * Register the submenu page under Intergroup
     *
     * Visible only to users with the manage_options capability (Administrators).
     * An additional explicit role check is enforced when handling actions.
     */
    public function registerSubmenuPage(): void
    {
        add_submenu_page(
            'intergroup',
            'Developer',
            'Developer',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * Handle form submissions (runs on admin_init, before output)
     */
    public function handleActions(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['page']) || $_GET['page'] !== self::PAGE_SLUG) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_POST['amber_developer_action'])) {
            return;
        }

        // Verify nonce and capability
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        if (!current_user_can('manage_options') || !$this->currentUserIsAdministrator()) {
            wp_die('You do not have permission to perform this action.');
        }

        $action = sanitize_text_field(wp_unslash($_POST['amber_developer_action']));

        if ($action === 'delete_attendance') {
            $result = $this->deleteAllAttendanceRecords();

            // Redirect back with a status message
            $redirectUrl = add_query_arg(
                [
                    'page'              => self::PAGE_SLUG,
                    'amber_action_done' => 'delete_attendance',
                    'group_deleted'     => $result['group'],
                    'officer_deleted'   => $result['officer'],
                ],
                admin_url('admin.php')
            );

            wp_safe_redirect($redirectUrl);
            exit;
        }

        if ($action === 'clear_gdpr') {
            $result = $this->clearAllGdprValues();

            $redirectUrl = add_query_arg(
                [
                    'page'              => self::PAGE_SLUG,
                    'amber_action_done' => 'clear_gdpr',
                    'members_cleared'   => $result['cleared'],
                    'members_total'     => $result['total'],
                ],
                admin_url('admin.php')
            );

            wp_safe_redirect($redirectUrl);
            exit;
        }
    }

    /**
     * Render the full admin page
     */
    public function renderPage(): void
    {
        if (!$this->currentUserIsAdministrator()) {
            wp_die('You do not have permission to access this page.');
        }

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">Developer</h1>';
        echo '<p class="dev-page-description">Maintenance utilities for development and testing. '
            . '<strong>Use with caution</strong> — actions on this page cannot be undone.</p>';

        // Success notice
        $this->renderNotice();

        // Attendance section
        $this->renderDeleteAttendanceSection();

        // GDPR section
        $this->renderClearGdprSection();

        echo '</div>';
    }

    /**
     * Render a success/info notice after an action has been performed
     */
    private function renderNotice(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!isset($_GET['amber_action_done'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = sanitize_text_field(wp_unslash($_GET['amber_action_done']));

        if ($action === 'delete_attendance') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $groupDeleted   = isset($_GET['group_deleted']) ? (int) $_GET['group_deleted'] : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $officerDeleted = isset($_GET['officer_deleted']) ? (int) $_GET['officer_deleted'] : 0;

            $total = $groupDeleted + $officerDeleted;

            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Attendance records deleted.</strong> '
                . esc_html((string) $groupDeleted) . ' group record'
                . ($groupDeleted !== 1 ? 's' : '') . ' and '
                . esc_html((string) $officerDeleted) . ' officer record'
                . ($officerDeleted !== 1 ? 's' : '') . ' removed '
                . '(' . esc_html((string) $total) . ' total).</p>';
            echo '</div>';
        }

        if ($action === 'clear_gdpr') {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $cleared = isset($_GET['members_cleared']) ? (int) $_GET['members_cleared'] : 0;
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $total   = isset($_GET['members_total']) ? (int) $_GET['members_total'] : 0;

            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>GDPR values cleared.</strong> '
                . esc_html((string) $cleared) . ' of '
                . esc_html((string) $total) . ' member'
                . ($total !== 1 ? 's' : '') . ' updated.</p>';
            echo '</div>';
        }
    }

    /**
     * Render the delete-attendance section with confirmation button
     */
    private function renderDeleteAttendanceSection(): void
    {
        $counts = $this->getAttendanceRecordCounts();

        echo '<div class="dev-section">';
        echo '<h2 class="dev-section-heading">Attendance Records</h2>';

        echo '<div class="dev-section-body">';

        echo '<p>Delete <strong>all</strong> group and officer attendance records from the '
            . 'intergroup meeting attendance tables. This removes the archived attendance '
            . 'data but does not affect the intergroup meeting posts themselves.</p>';

        echo '<table class="dev-stats-table">';
        echo '<tr><td>Group attendance records</td><td><strong>'
            . esc_html((string) $counts['group']) . '</strong></td></tr>';
        echo '<tr><td>Officer attendance records</td><td><strong>'
            . esc_html((string) $counts['officer']) . '</strong></td></tr>';
        echo '</table>';

        echo '<form method="post">';
        echo '<input type="hidden" name="amber_developer_action" value="delete_attendance">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $disabled = ($counts['group'] + $counts['officer']) === 0 ? ' disabled' : '';

        echo '<button type="submit" class="button dev-button-danger"' . $disabled
            . ' onclick="return confirm(\'Are you sure you want to delete all attendance records? This cannot be undone.\');">'
            . 'Delete All Attendance Records'
            . '</button>';
        echo '</form>';

        echo '</div>'; // .dev-section-body
        echo '</div>'; // .dev-section
    }

    /**
     * Render the clear-GDPR section with confirmation button
     */
    private function renderClearGdprSection(): void
    {
        $totalMembers = $this->memberRepository->count();
        $withGdpr     = $this->countMembersWithGdprValues();

        echo '<div class="dev-section">';
        echo '<h2 class="dev-section-heading">Member GDPR Values</h2>';

        echo '<div class="dev-section-body">';

        echo '<p>Clear <strong>all</strong> GDPR-related property values on every member: '
            . 'acceptance flag, acceptance timestamp, acceptance version, acceptance method, '
            . 'and acceptance statement. Members are updated through the repository, so '
            . 'change-tracking events fire and the audit log records each change.</p>';

        echo '<table class="dev-stats-table">';
        echo '<tr><td>Total members</td><td><strong>'
            . esc_html((string) $totalMembers) . '</strong></td></tr>';
        echo '<tr><td>Members with GDPR data</td><td><strong>'
            . esc_html((string) $withGdpr) . '</strong></td></tr>';
        echo '</table>';

        echo '<form method="post">';
        echo '<input type="hidden" name="amber_developer_action" value="clear_gdpr">';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $disabled = $withGdpr === 0 ? ' disabled' : '';

        echo '<button type="submit" class="button dev-button-danger"' . $disabled
            . ' onclick="return confirm(\'Are you sure you want to clear all GDPR values on every member? This cannot be undone.\');">'
            . 'Clear All GDPR Values'
            . '</button>';
        echo '</form>';

        echo '</div>'; // .dev-section-body
        echo '</div>'; // .dev-section
    }

    /**
     * Delete all records from both attendance tables
     *
     * @return array{group: int, officer: int} Number of rows deleted from each table
     */
    private function deleteAllAttendanceRecords(): array
    {
        global $wpdb;

        $groupTable   = TsmlIntergroupMeetingGroupAttendanceTable::getTableName();
        $officerTable = TsmlIntergroupMeetingOfficerAttendanceTable::getTableName();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix; cannot be parameterised with prepare()
        $groupDeleted = (int) $wpdb->query("DELETE FROM `" . esc_sql($groupTable) . "`");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix; cannot be parameterised with prepare()
        $officerDeleted = (int) $wpdb->query("DELETE FROM `" . esc_sql($officerTable) . "`");

        return [
            'group'   => $groupDeleted,
            'officer' => $officerDeleted,
        ];
    }

    /**
     * Get the current record counts for both attendance tables
     *
     * @return array{group: int, officer: int}
     */
    private function getAttendanceRecordCounts(): array
    {
        global $wpdb;

        $groupTable   = TsmlIntergroupMeetingGroupAttendanceTable::getTableName();
        $officerTable = TsmlIntergroupMeetingOfficerAttendanceTable::getTableName();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $groupCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($groupTable) . "`");

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $officerCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($officerTable) . "`");

        return [
            'group'   => $groupCount,
            'officer' => $officerCount,
        ];
    }

    /**
     * Clear all GDPR-related property values on every member
     *
     * For each existing member, builds a fresh Member with every non-GDPR
     * field copied across and every GDPR field reset to its empty default,
     * then saves it via the repository. Routing through the repository
     * ensures unity/member_changing fires so the audit/change-tracker
     * pipeline sees the clear.
     *
     * @return array{cleared: int, total: int}
     */
    private function clearAllGdprValues(): array
    {
        $members = $this->memberRepository->findAll();
        $total   = count($members);
        $cleared = 0;

        foreach ($members as $member) {
            // Skip members that already have nothing to clear, so we
            // don't generate redundant audit entries.
            if (!$this->memberHasGdprValues($member)) {
                continue;
            }

            $cleaned = $this->memberFactory->createNew(
                $member->getId(),
                $member->getAnonymousName(),
                $member->showAnonymousName(),
                $member->showMemberProfile(),
                $member->getAnonymousProfile(),
                $member->getIntergroupPosition(),
                $member->getIntergroupPositionRotation(),
                $member->getHomeGroup(),
                $member->isGSR(),
                $member->getMeetingPO(),
                $member->getPersonalEmail(),
                $member->getMobileNumber(),
                false, // gdprAccepted
                '',    // gdprAcceptedAt
                '',    // gdprAcceptanceVersion
                '',    // gdprAcceptanceMethod
                ''     // gdprAcceptanceStatement
            );

            if ($this->memberRepository->save($cleaned)) {
                $cleared++;
            }
        }

        return [
            'cleared' => $cleared,
            'total'   => $total,
        ];
    }

    /**
     * Count members that currently have any GDPR value set
     *
     * @return int
     */
    private function countMembersWithGdprValues(): int
    {
        $count = 0;

        foreach ($this->memberRepository->findAll() as $member) {
            if ($this->memberHasGdprValues($member)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Whether a member has any GDPR value set
     *
     * @param Member $member
     * @return bool
     */
    private function memberHasGdprValues(Member $member): bool
    {
        return $member->isGdprAccepted()
            || $member->getGdprAcceptedAt() !== ''
            || $member->getGdprAcceptanceVersion() !== ''
            || $member->getGdprAcceptanceMethod() !== ''
            || $member->getGdprAcceptanceStatement() !== '';
    }

    /**
     * Check whether the current user has the Administrator role
     *
     * @return bool
     */
    private function currentUserIsAdministrator(): bool
    {
        $user = wp_get_current_user();

        return $user->exists() && in_array('administrator', (array) $user->roles, true);
    }

    /**
     * Add custom styles for the developer page
     */
    public function addPageStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'intergroup_page_' . self::PAGE_SLUG) {
            return;
        }

        echo '<style>
            .dev-page-description {
                font-size: 14px;
                color: #50575e;
                margin: 4px 0 20px;
                max-width: 700px;
            }

            .dev-section {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                margin-bottom: 20px;
                max-width: 700px;
            }

            .dev-section-heading {
                margin: 0;
                padding: 12px 16px;
                font-size: 14px;
                font-weight: 600;
                border-bottom: 1px solid #c3c4c7;
                background: #f9f9f9;
            }

            .dev-section-body {
                padding: 16px;
            }

            .dev-section-body p {
                margin-top: 0;
                color: #50575e;
            }

            .dev-stats-table {
                margin: 12px 0 16px;
                border-collapse: collapse;
            }

            .dev-stats-table td {
                padding: 4px 16px 4px 0;
                font-size: 13px;
                color: #50575e;
            }

            .dev-stats-table td:last-child {
                color: #1d2327;
            }

            .dev-button-danger {
                background: #d63638 !important;
                border-color: #d63638 !important;
                color: #fff !important;
            }

            .dev-button-danger:hover:not(:disabled) {
                background: #b32d2e !important;
                border-color: #b32d2e !important;
            }

            .dev-button-danger:disabled {
                opacity: 0.6;
                cursor: not-allowed !important;
            }

            .dev-button-danger:focus {
                box-shadow: 0 0 0 1px #fff, 0 0 0 3px #d63638 !important;
            }
        </style>';
    }
}