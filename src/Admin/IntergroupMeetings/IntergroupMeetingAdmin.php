<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionViewFactory;
use WP_Query;
use function add_action;
use function add_filter;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function get_post_type;
use function is_admin;
use function update_post_meta;
use function delete_post_meta;
use function wp_doing_ajax;
use const DOING_AUTOSAVE;
use const WP_DEBUG;

/**
 * Intergroup Meeting Admin
 *
 * Adds custom columns to the admin table view for intergroup meetings.
 */
class IntergroupMeetingAdmin
{
    private IntergroupMeetingFactory $intergroupMeetingFactory;
    private IntergroupMeetingRepository $intergroupMeetingRepository;
    private IntergroupMeetingGroupAttendanceFactory $groupAttendanceFactory;
    private IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository;
    private IntergroupMeetingOfficerAttendanceFactory $officerAttendanceFactory;
    private IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository;
    private GroupRepository $groupRepository;
    private MemberRepository $memberRepository;
    private PositionFactory $positionFactory;
    private PositionRepository $positionRepository;
    private PositionViewFactory $positionViewFactory;
    private MeetingRepository $meetingRepository;
    private GroupViewFactory $groupViewFactory;
    private readonly array $intergroupMeetingConfig;
    private readonly array $groupConfig;
    private readonly array $memberConfig;

    /** @var array<int, array<int>> Per-request cache for resolveGroupGsrs */
    private array $groupGsrCache = [];

    /** @var array<int, array{positionName: string, officerDisplayName: string, memberIds: array<int>}|null> Per-request cache for resolvePositionOfficers */
    private array $positionOfficerCache = [];

    /**
     * Constructor
     *
     * @param Configuration $configuration Configuration
     * @param IntergroupMeetingFactory $intergroupMeetingFactory Intergroup meeting factory
     * @param IntergroupMeetingRepository $intergroupMeetingRepository Intergroup meeting repository
     * @param IntergroupMeetingGroupAttendanceFactory $groupAttendanceFactory Group attendance factory
     * @param IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository Group attendance repository
     * @param IntergroupMeetingOfficerAttendanceFactory $officerAttendanceFactory Officer attendance factory
     * @param IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository Officer attendance repository
     * @param GroupRepository $groupRepository Group repository
     * @param MemberRepository $memberRepository Member repository
     * @param PositionFactory $positionFactory Position factory
     * @param PositionRepository $positionRepository Position repository
     * @param PositionViewFactory $positionViewFactory Position view factory
     * @param MeetingRepository $meetingRepository Meeting repository
     * @param GroupViewFactory $groupViewFactory Group view factory
     */
    public function __construct(
        Configuration $configuration,
        IntergroupMeetingFactory $intergroupMeetingFactory,
        IntergroupMeetingRepository $intergroupMeetingRepository,
        IntergroupMeetingGroupAttendanceFactory $groupAttendanceFactory,
        IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository,
        IntergroupMeetingOfficerAttendanceFactory $officerAttendanceFactory,
        IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository,
        GroupRepository $groupRepository,
        MemberRepository $memberRepository,
        PositionFactory $positionFactory,
        PositionRepository $positionRepository,
        PositionViewFactory $positionViewFactory,
        MeetingRepository $meetingRepository,
        GroupViewFactory $groupViewFactory
    ) {

        $this->intergroupMeetingConfig = $configuration->getConfig(IntergroupMeeting::class);

        $this->groupConfig = $configuration->getConfig(Group::class);
        $this->memberConfig = $configuration->getConfig(Member::class);

        $this->intergroupMeetingFactory = $intergroupMeetingFactory;
        $this->intergroupMeetingRepository = $intergroupMeetingRepository;
        $this->groupAttendanceFactory = $groupAttendanceFactory;
        $this->groupAttendanceRepository = $groupAttendanceRepository;
        $this->officerAttendanceFactory = $officerAttendanceFactory;
        $this->officerAttendanceRepository = $officerAttendanceRepository;
        $this->groupRepository = $groupRepository;
        $this->memberRepository = $memberRepository;
        $this->positionFactory = $positionFactory;
        $this->positionRepository = $positionRepository;
        $this->positionViewFactory = $positionViewFactory;
        $this->meetingRepository = $meetingRepository;
        $this->groupViewFactory = $groupViewFactory;

        add_filter('manage_' . $this->intergroupMeetingConfig['POST_TYPE'] . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . $this->intergroupMeetingConfig['POST_TYPE'] . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . $this->intergroupMeetingConfig['POST_TYPE'] . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_filter('pre_get_posts', [$this, 'handleCustomColumnSorting']);
        add_action('acf/save_post', [$this, 'updateIntergroupMeetingMetadataOnSave'], 20);
        add_action('admin_head', [$this, 'addAdminColumnStyles']);
        add_filter('acf/fields/relationship/result',[$this, 'addPositionName'],10, 4);
        add_filter('acf/fields/relationship/result',[$this, 'addMemberNameToPosition'],10, 4);
        add_filter('acf/fields/relationship/result',[$this, 'addGsrsName'],10, 4);
        add_action('unity/member_changing', [$this, 'onMemberPositionChanged'], 10, 2);

    }

    /**
     * add name of position in relationship list.
     *
     * @return string Modified title
     */
    public function addPositionName($title, $post, $field, $post_id) {

        if ($post->post_type !== $this->memberConfig['POST_TYPE']) {
            return $title;
        }

        $member = $this->memberRepository->findById($post->ID);

        if ($member === null) {
            return $title;
        }

        $intergroupPosition = $member->getIntergroupPosition();

        if ($intergroupPosition === 0) { return $title; }

        $position = $this->positionRepository->findById($intergroupPosition);

        if ($position === null) { return $title; }

        return $title . ' (' . $position->getLongName() . ')';

    }

    /**
     * Add member name to intergroup-position relationship list items.
     *
     * Uses the same latest-rotation-date logic as syncOfficerAttendance:
     * if multiple members share the latest rotation date all their names
     * are shown.
     *
     * @return string Modified title
     */
    public function addMemberNameToPosition($title, $post, $field, $post_id) {

        if ($post->post_type !== 'intergroup-position') {
            return $title;
        }

        $positionView = $this->positionViewFactory->createFrom($post->ID);

        if (!$positionView) {
            return $title;
        }

        $officerName = $positionView->getOfficerDisplayName();

        if ($officerName === '') {
            return $title;
        }

        return $title . ' (' . $officerName . ')';

    }

    /**
     * add name of gsr's in relationship list.
     *
     * @return int Number of meetings updated
     */
    public function addGsrsName($title, $post, $field, $post_id) {

        if ($post->post_type !== $this->groupConfig['POST_TYPE']) {
            return $title;
        }

        $group = $this->groupViewFactory->createFrom($post->ID);

        if ($group === null) {
            return $title;
        }

        $gsrs = [];

        foreach ($group->getMembers() as $member) {
            if ($member->isGSR()) {
                $gsrs[] = $member->getAnonymousName();
            }
        }

        $result = implode(', ', $gsrs);

        if ($result !== '') {
            return $title . ' (' . $result . ')';
        } else {
            return $title;
        }

    }

    /**
     * Handle unity/member_changing hook to update officer attendance records
     * when a member's service position or name changes on the day of an
     * intergroup meeting.
     *
     * Only the attendance record for today's intergroup meeting is updated.
     * Historical attendance records are left unchanged.
     *
     * @param Member $updatedMember  The member after changes
     * @param Member $originalMember The member before changes
     * @return void
     */
    public function onMemberPositionChanged(Member $updatedMember, Member $originalMember): void
    {
        $positionChanged = $updatedMember->getIntergroupPosition() !== $originalMember->getIntergroupPosition();
        $nameChanged = $updatedMember->getAnonymousName() !== $originalMember->getAnonymousName();

        if (!$positionChanged && !$nameChanged) {
            return;
        }

        $officerId = $updatedMember->getId();

        // Find all officer attendance records for this member, then filter
        // to today's meeting. This avoids relying on _intergroup_meeting_date_sortable
        // meta which may not exist or may be in a different date format.
        $attendanceRecords = $this->officerAttendanceRepository->findAll([
            'officer_id' => $officerId,
        ]);

        if (empty($attendanceRecords)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                \Amber\Plugin::logError('No officer attendance records found for member ID: ' . $officerId . ' — skipping update');
            }
            return;
        }

        $todayDate = wp_date('Y-m-d');
        $todaysMeetingId = null;

        foreach ($attendanceRecords as $record) {
            $meeting = $this->intergroupMeetingRepository->findById($record->getIntergroupMeetingId());

            if (!$meeting) {
                continue;
            }

            $meetingDate = $meeting->getDate();

            if (empty($meetingDate)) {
                continue;
            }

            $meetingTimestamp = strtotime($meetingDate);

            if ($meetingTimestamp !== false && wp_date('Y-m-d', $meetingTimestamp) === $todayDate) {
                $todaysMeetingId = $meeting->getId();
                break;
            }
        }

        if (!$todaysMeetingId) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                \Amber\Plugin::logError('No intergroup meeting found for today — skipping attendance update for member ID: ' . $officerId);
            }
            return;
        }

        $officerName = $updatedMember->getAnonymousName();
        $positionName = '';

        $positionId = $updatedMember->getIntergroupPosition();
        if ($positionId) {
            $position = $this->positionRepository->findById($positionId);
            if ($position) {
                $positionName = $position->getLongName();
            }
        }

        $rowsUpdated = $this->officerAttendanceRepository->updateByMeetingAndOfficer(
            $todaysMeetingId,
            $officerId,
            $positionName,
            $officerName
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            \Amber\Plugin::logError('Officer attendance updated for member ID: ' . $officerId
                . ' at meeting ID: ' . $todaysMeetingId
                . ' — ' . $rowsUpdated . ' record(s) updated'
                . ' — new position: "' . $positionName . '"'
                . ($positionChanged ? ' [position changed]' : '')
                . ($nameChanged ? ' [name changed]' : ''));
        }
    }

    /**
     * Find the intergroup meeting scheduled for today.
     *
     * Queries published intergroup meetings whose date matches today's date.
     *
     * @return IntergroupMeeting|null The today's meeting, or null if none found
     */
    private function findTodaysIntergroupMeeting(): ?IntergroupMeeting
    {
        $today = wp_date('Y-m-d');

        $meetings = $this->intergroupMeetingRepository->findAll([
            'meta_key'   => '_intergroup_meeting_date_sortable',
            'meta_value' => $today,
        ]);

        if (empty($meetings)) {
            return null;
        }

        return $meetings[0];
    }

    /**
     * Set up metadata for all intergroup meetings
     *
     * @return int Number of meetings updated
     */
    public function setupAllIntergroupMeetingsMetadata(): int
    {
        $meetings = $this->intergroupMeetingRepository->findAll();
        $count = 0;

        foreach ($meetings as $meeting) {
            $this->updateIntergroupMeetingMetadata($meeting->getId());
            $count++;
        }

        return $count;
    }

    /**
     * Add custom columns to the intergroup meetings admin table
     *
     * @param array $columns Current admin columns
     * @return array Modified admin columns
     */
    public function addCustomColumns(array $columns): array
    {
        $newColumns = [];

        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;

            if ($key === 'title') {
                $newColumns['meeting_date'] = 'Meeting Date';
                $newColumns['group_attendees'] = 'Group Attendees';
                $newColumns['officers_attending'] = 'Officers Attending';
                $newColumns['attendee_count'] = 'Eligible';
            }
        }

        return $newColumns;
    }

    /**
     * Populate the custom columns with data
     *
     * @param string $columnName Name of the column
     * @param int $postId Post ID
     */
    public function populateCustomColumns(string $columnName, int $postId): void
    {
        $meeting = $this->intergroupMeetingFactory->createFromSource($postId);

        if (!$meeting) {
            return;
        }

        switch ($columnName) {
            case 'meeting_date':
                $this->displayMeetingDate($meeting);
                break;

            case 'group_attendees':
                $this->displayGroupAttendees($meeting);
                break;

            case 'officers_attending':
                $this->displayOfficersAttending($meeting);
                break;

            case 'attendee_count':
                $this->displayAttendeeCount($meeting);
                break;
        }
    }

    /**
     * Make certain columns sortable
     *
     * @param array $columns Current sortable columns
     * @return array Modified sortable columns
     */
    public function makeColumnsSortable(array $columns): array
    {
        $columns['meeting_date'] = 'meeting_date';
        $columns['attendee_count'] = 'attendee_count';
        return $columns;
    }

    /**
     * Display the meeting date
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function displayMeetingDate(IntergroupMeeting $meeting): void
    {
        $date = $meeting->getDate();

        if (empty($date)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            echo esc_html(wp_date('F j, Y', $timestamp));
        } else {
            echo esc_html($date);
        }
    }

    /**
     * Display the group attendees from the meeting's ACF relationship field
     *
     * Resolves group names and GSR names directly from the group and
     * member repositories so the column does not depend on the
     * attendance tables.
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function displayGroupAttendees(IntergroupMeeting $meeting): void
    {
        $groupIds = $meeting->getGroupAttendees();

        if (empty($groupIds)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        $names = [];
        $groupGsrs = $this->resolveGroupGsrs($groupIds);

        foreach ($groupIds as $groupId) {
            $group = $this->groupRepository->findById($groupId);
            if (!$group) {
                continue;
            }

            $groupName = $group->getTitle();
            $editLink = get_edit_post_link($groupId);
            $display = $editLink
                ? '<a href="' . esc_url($editLink) . '">' . esc_html($groupName) . '</a>'
                : esc_html($groupName);

            $gsrMemberIds = $groupGsrs[$groupId] ?? [];
            if (!empty($gsrMemberIds)) {
                $gsrLinks = [];
                foreach ($gsrMemberIds as $gsrMemberId) {
                    $gsrMember = $this->memberRepository->findById($gsrMemberId);
                    if (!$gsrMember) {
                        continue;
                    }
                    $memberEditLink = get_edit_post_link($gsrMemberId);
                    if ($memberEditLink) {
                        $gsrLinks[] = '<a href="' . esc_url($memberEditLink) . '">' . esc_html($gsrMember->getAnonymousName()) . '</a>';
                    } else {
                        $gsrLinks[] = esc_html($gsrMember->getAnonymousName());
                    }
                }
                if (!empty($gsrLinks)) {
                    $display .= ' (' . implode(', ', $gsrLinks) . ')';
                }
            }

            $names[] = $display;
        }

        echo !empty($names) ? implode('<br>', $names) : '<span style="color: gray;">—</span>';
    }

    /**
     * Display the officers attending from the meeting's ACF relationship field
     *
     * Resolves position and officer names directly from the position
     * view factory so the column does not depend on the attendance
     * tables.
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function displayOfficersAttending(IntergroupMeeting $meeting): void
    {
        $officerIds = $meeting->getOfficersAttending();

        if (empty($officerIds)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        $entries = [];
        $positionData = $this->resolvePositionOfficers($officerIds);

        foreach ($officerIds as $officerId) {
            $data = $positionData[$officerId] ?? null;
            if (!$data) {
                continue;
            }

            $positionLabel = esc_html($data['positionName']);

            if (empty($data['officerDisplayName'])) {
                $entries[] = $positionLabel;
                continue;
            }

            $memberLinks = [];

            if (!empty($data['memberIds'])) {
                foreach ($data['memberIds'] as $memberId) {
                    $member = $this->memberRepository->findById($memberId);
                    if (!$member) {
                        continue;
                    }
                    $memberEditLink = get_edit_post_link($memberId);
                    if ($memberEditLink) {
                        $memberLinks[] = '<a href="' . esc_url($memberEditLink) . '">' . esc_html($member->getAnonymousName()) . '</a>';
                    } else {
                        $memberLinks[] = esc_html($member->getAnonymousName());
                    }
                }
            } else {
                // Fallback to display name string if members can't be resolved
                $names = array_map('trim', explode(',', $data['officerDisplayName']));
                foreach ($names as $name) {
                    $memberLinks[] = esc_html($name);
                }
            }

            $entries[] = $positionLabel . ' (' . implode(', ', $memberLinks) . ')';
        }

        echo !empty($entries) ? implode('<br>', $entries) : '<span style="color: gray;">—</span>';
    }

    /**
     * Display the total attendee count
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function displayAttendeeCount(IntergroupMeeting $meeting): void
    {
        $groupCount = count($meeting->getGroupAttendees());
        $officerCount = count($meeting->getOfficersAttending());
        $total = $groupCount + $officerCount;

        echo '<span title="' . esc_html($groupCount . ' groups, ' . $officerCount . ' officers') . '">';
        echo esc_html((string)$total);
        echo '</span>';
    }

    /**
     * Handle custom column sorting
     *
     * @param WP_Query $query WordPress query object
     * @return WP_Query Modified query object
     */
    public function handleCustomColumnSorting(WP_Query $query): WP_Query
    {
        if (!is_admin() || !$query->is_main_query()) {
            return $query;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== $this->intergroupMeetingConfig['POST_TYPE']) {
            return $query;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'meeting_date':
                $query->set('meta_key', '_intergroup_meeting_date_sortable');
                $query->set('orderby', 'meta_value');
                break;

            case 'attendee_count':
                $query->set('meta_key', '_intergroup_meeting_attendee_count');
                $query->set('orderby', 'meta_value_num');
                break;
        }

        return $query;
    }

    /**
     * Update intergroup meeting metadata when saved via ACF
     *
     * Hooked to acf/save_post at priority 20 so ACF fields are
     * already persisted when we read them.
     *
     * @param int $postId The post ID
     */
    public function updateIntergroupMeetingMetadataOnSave(int $postId): void
    {
        if (get_post_type($postId) !== $this->intergroupMeetingConfig['POST_TYPE']) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_doing_ajax()) return;

        $this->updateIntergroupMeetingMetadata($postId);
    }

    /**
     * Update all metadata for an intergroup meeting
     *
     * @param int $meetingId The intergroup meeting ID
     */
    public function updateIntergroupMeetingMetadata(int $meetingId): void
    {
        $meeting = $this->intergroupMeetingFactory->createFromSource($meetingId);
        if (!$meeting) {
            return;
        }

        // Update date metadata for sorting
        $date = $meeting->getDate();
        if (!empty($date)) {
            update_post_meta($meetingId, '_intergroup_meeting_date_sortable', $date);
        } else {
            delete_post_meta($meetingId, '_intergroup_meeting_date_sortable');
        }

        // Update attendee count metadata for sorting
        $totalAttendees = count($meeting->getGroupAttendees()) + count($meeting->getOfficersAttending());
        update_post_meta($meetingId, '_intergroup_meeting_attendee_count', $totalAttendees);

        // Sync attendance records with the current attendee lists
        $this->syncGroupAttendance($meeting);
        $this->syncOfficerAttendance($meeting);
    }

    /**
     * Sync group attendance records with the current group attendees list.
     *
     * Creates attendance records for newly added groups and removes records
     * for groups that are no longer in the attendees list.
     *
     * @param IntergroupMeeting $meeting The intergroup meeting
     */
    private function syncGroupAttendance(IntergroupMeeting $meeting): void
    {
        $meetingId = $meeting->getId();
        $currentGroupIds = $meeting->getGroupAttendees();

        // Get existing attendance records for this meeting
        $existingRecords = $this->groupAttendanceRepository->findByIntergroupMeeting($meetingId);

        // Build a map of existing groupId => attendance record
        $existingByGroupId = [];
        foreach ($existingRecords as $record) {
            $existingByGroupId[$record->getGroupId()] = $record;
        }

        // Determine which groups were added and which were removed
        $existingGroupIds = array_keys($existingByGroupId);
        $addedGroupIds = array_diff($currentGroupIds, $existingGroupIds);
        $removedGroupIds = array_diff($existingGroupIds, $currentGroupIds);

        // Create attendance records for newly added groups
        $groupGsrs = $this->resolveGroupGsrs($addedGroupIds);

        foreach ($addedGroupIds as $groupId) {
            $group = $this->groupRepository->findById($groupId);
            if (!$group) {
                continue;
            }

            $groupTitle = $group->getTitle();
            $gsrMemberIds = $groupGsrs[$groupId] ?? [];
            $gsrNames = [];
            foreach ($gsrMemberIds as $gsrMemberId) {
                $gsrMember = $this->memberRepository->findById($gsrMemberId);
                if ($gsrMember) {
                    $gsrNames[] = $gsrMember->getAnonymousName();
                }
            }
            $gsrName = implode(', ', $gsrNames);
            $meetingLabel = $this->buildMeetingLabel($meeting);

            $attendance = $this->groupAttendanceFactory->createNew(
                $meetingId,
                $meetingLabel,
                $groupId,
                0,           // member_id — not applicable when adding from admin
                $groupTitle,
                $gsrName
            );

            $this->groupAttendanceRepository->save($attendance);
        }

        // Remove attendance records for groups no longer attending
        foreach ($removedGroupIds as $groupId) {
            $this->groupAttendanceRepository->deleteByIntergroupMeetingAndGroup($meetingId, $groupId);
        }
    }

    /**
     * Sync officer attendance records with the current officers attending list.
     *
     * Creates attendance records for newly added officers and removes records
     * for officers that are no longer in the attendees list.
     *
     * @param IntergroupMeeting $meeting The intergroup meeting
     */
    private function syncOfficerAttendance(IntergroupMeeting $meeting): void
    {
        $meetingId = $meeting->getId();
        $currentOfficerIds = $meeting->getOfficersAttending();

        // Get existing officer attendance records for this meeting
        $existingRecords = $this->officerAttendanceRepository->findByIntergroupMeeting($meetingId);

        // Build a map of existing officerId => attendance record
        $existingByOfficerId = [];
        foreach ($existingRecords as $record) {
            $existingByOfficerId[$record->getOfficerId()] = $record;
        }

        // Determine which officers were added and which were removed
        $existingOfficerIds = array_keys($existingByOfficerId);
        $addedOfficerIds = array_diff($currentOfficerIds, $existingOfficerIds);
        $removedOfficerIds = array_diff($existingOfficerIds, $currentOfficerIds);

        // Create attendance records for newly added officers
        $positionData = $this->resolvePositionOfficers($addedOfficerIds);

        foreach ($addedOfficerIds as $officerId) {
            $data = $positionData[$officerId] ?? null;
            if (!$data) {
                continue;
            }

            $positionName = $data['positionName'];
            $officerName = $data['officerDisplayName'];
            $meetingLabel = $this->buildMeetingLabel($meeting);

            $attendance = $this->officerAttendanceFactory->createNew(
                $meetingId,
                $meetingLabel,
                $officerId,
                $positionName,
                $officerName
            );

            $this->officerAttendanceRepository->save($attendance);
        }

        // Remove attendance records for officers no longer attending
        foreach ($removedOfficerIds as $officerId) {
            $this->officerAttendanceRepository->deleteByIntergroupMeetingAndOfficer($meetingId, $officerId);
        }
    }

    /**
     * Resolve GSR member IDs for a list of groups.
     *
     * Returns an associative array keyed by group ID, where each value
     * is an array of GSR member IDs belonging to that group.
     *
     * @param array<int> $groupIds Group IDs to resolve
     * @return array<int, array<int>> Map of groupId => [gsrMemberId, ...]
     */
    private function resolveGroupGsrs(array $groupIds): array
    {
        $result = [];

        foreach ($groupIds as $groupId) {
            if (array_key_exists($groupId, $this->groupGsrCache)) {
                $result[$groupId] = $this->groupGsrCache[$groupId];
                continue;
            }

            $groupView = $this->groupViewFactory->createFrom($groupId);
            if (!$groupView) {
                $this->groupGsrCache[$groupId] = [];
                $result[$groupId] = [];
                continue;
            }

            $gsrIds = [];
            foreach ($groupView->getMembers() as $member) {
                if ($member->isGSR()) {
                    $gsrIds[] = $member->getId();
                }
            }

            $this->groupGsrCache[$groupId] = $gsrIds;
            $result[$groupId] = $gsrIds;
        }

        return $result;
    }

    /**
     * Resolve position and officer details for a list of position IDs.
     *
     * Returns an associative array keyed by position ID. Each value is
     * either null (position not found) or an array with:
     *   - positionName:       string   The position long name
     *   - officerDisplayName: string   The officer display name (may be empty)
     *   - memberIds:          int[]    IDs of members holding the position
     *
     * @param array<int> $positionIds Position IDs to resolve
     * @return array<int, array{positionName: string, officerDisplayName: string, memberIds: array<int>}|null>
     */
    private function resolvePositionOfficers(array $positionIds): array
    {
        $result = [];

        foreach ($positionIds as $positionId) {
            if (array_key_exists($positionId, $this->positionOfficerCache)) {
                $result[$positionId] = $this->positionOfficerCache[$positionId];
                continue;
            }

            $positionView = $this->positionViewFactory->createFrom($positionId);
            if (!$positionView) {
                $this->positionOfficerCache[$positionId] = null;
                $result[$positionId] = null;
                continue;
            }

            $memberIds = [];
            foreach ($positionView->getMembers() as $member) {
                $memberIds[] = $member->getId();
            }

            $data = [
                'positionName'       => $positionView->getPosition()->getLongName(),
                'officerDisplayName' => $positionView->getOfficerDisplayName(),
                'memberIds'          => $memberIds,
            ];

            $this->positionOfficerCache[$positionId] = $data;
            $result[$positionId] = $data;
        }

        return $result;
    }

    /**
     * Build a human-readable label for an intergroup meeting
     *
     * Combines the meeting title and formatted date into a single string
     * suitable for display and filtering in attendance records.
     *
     * Format: "Title — Month Day, Year" (or just the title or date when
     * only one is available).
     *
     * @param IntergroupMeeting $meeting
     * @return string
     */
    private function buildMeetingLabel(IntergroupMeeting $meeting): string
    {
        $title = $meeting->getTitle();
        $date  = $meeting->getDate();

        $formattedDate = '';
        if (!empty($date)) {
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $formattedDate = gmdate('F j, Y', $timestamp);
            } else {
                $formattedDate = $date;
            }
        }

        if (!empty($title) && !empty($formattedDate)) {
            return $title . ' — ' . $formattedDate;
        }

        if (!empty($title)) {
            return $title;
        }

        if (!empty($formattedDate)) {
            return $formattedDate;
        }

        return 'Meeting (ID: ' . $meeting->getId() . ')';
    }

    /**
     * Add custom styles for the admin columns
     */
    public function addAdminColumnStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== $this->intergroupMeetingConfig['POST_TYPE']) {
            return;
        }

        echo '<style>
            .column-title { width: 20%; }
            .column-meeting_date { width: 10%; }
            .column-group_attendees { width: 28%; }
            .column-officers_attending { width: 28%; }
            .column-attendee_count { width: 8%; text-align: center; }
        </style>';
    }
}