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
use const DOING_AJAX;
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

        $member = $this->memberRepository->find($post->ID);

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

        $allMembers = $this->memberRepository->findAll();
        $officerName = $this->resolveOfficerNameForPosition($post->ID, $allMembers);

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
            $meeting = $this->intergroupMeetingRepository->find($record->getIntergroupMeetingId());

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
                $newColumns['attendee_count'] = 'Total Attendees';
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
     * Display the group attendees as a list of group names
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function displayGroupAttendees(IntergroupMeeting $meeting): void
    {
        $attendeeIds = $meeting->getGroupAttendees();

        if (empty($attendeeIds)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        $names = [];

        foreach ($attendeeIds as $id) {
            $groupView = $this->groupViewFactory->createFrom($id);
            if ($groupView) {
                $editLink = get_edit_post_link($id);
                $groupName = $editLink
                    ? '<a href="' . esc_url($editLink) . '">' . esc_html($groupView->getTitle()) . '</a>'
                    : esc_html($groupView->getTitle());

                // Find GSR members for this group
                $gsrNames = [];
                foreach ($groupView->getMembers() as $member) {
                    if ($member->isGSR()) {
                        $memberEditLink = get_edit_post_link($member->getId());
                        $gsrNames[] = $memberEditLink
                            ? '<a href="' . esc_url($memberEditLink) . '">' . esc_html($member->getAnonymousName()) . '</a>'
                            : esc_html($member->getAnonymousName());
                    }
                }

                if (!empty($gsrNames)) {
                    $groupName .= ' (' . implode(', ', $gsrNames) . ')';
                }

                $names[] = $groupName;
            }
        }

        if (empty($names)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        echo implode(', ', $names);
    }

    /**
     * Display the officers attending as a list of member names
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function displayOfficersAttending(IntergroupMeeting $meeting): void
    {
        $positionIds = $meeting->getOfficersAttending();

        if (empty($positionIds)) {
            echo '—';
            return;
        }

        $names = [];
        foreach ($positionIds as $positionId) {
            $positionView = $this->positionViewFactory->createFrom($positionId);
            if (!$positionView) {
                continue;
            }

            $positionLabel = esc_html($positionView->getPosition()->getShortDescription());
            $member = $positionView->getMember();

            if ($member && !$positionView->isVacant()) {
                $memberId = $member->getId();
                $editLink = get_edit_post_link($memberId);
                $nameHtml = $editLink
                    ? '<a href="' . esc_url($editLink) . '">' . esc_html($member->getAnonymousName()) . '</a>'
                    : esc_html($member->getAnonymousName());

                $names[] = $positionLabel . ' (' . $nameHtml . ')';
            } else {
                $names[] = $positionLabel;
            }
        }

        if (empty($names)) {
            echo '—';
            return;
        }

        echo implode(', ', $names);
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
        if (defined('DOING_AJAX') && DOING_AJAX) return;

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
        foreach ($addedGroupIds as $groupId) {
            $group = $this->groupRepository->findById($groupId);
            if (!$group) {
                continue;
            }

            $groupTitle = $group->getTitle();
            $gsrName = $this->resolveGsrNameForGroup($groupId);

            $attendance = $this->groupAttendanceFactory->createNew(
                $meetingId,
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

        // Load all members once for resolving names across all added positions
        $allMembers = $this->memberRepository->findAll();

        // Create attendance records for newly added officers
        foreach ($addedOfficerIds as $officerId) {
            $positionView = $this->positionViewFactory->createFrom($officerId);
            if (!$positionView) {
                continue;
            }

            $positionName = $positionView->getPosition()->getLongName();

            // Resolve the officer name(s) from all members assigned to this
            // position, using the latest rotation date. If multiple members
            // share the same latest date they are all included.
            $officerName = $this->resolveOfficerNameForPosition($officerId, $allMembers);

            $attendance = $this->officerAttendanceFactory->createNew(
                $meetingId,
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
     * Resolve the officer display name for a position from the member list.
     *
     * Returns the anonymous name of the member with the latest rotation date.
     * If multiple members share the same latest rotation date all their names
     * are returned comma-separated. This mirrors the logic used by
     * TsmlPositionViewFactory::findMemberWithLatestRotationDate but extends it
     * to include ties.
     *
     * @param int            $positionId The position CPT post ID
     * @param array<Member>  $allMembers All loaded members
     * @return string Comma-separated anonymous name(s), or empty string if none found
     */
    private function resolveOfficerNameForPosition(int $positionId, array $allMembers): string
    {
        // Filter to members holding this position
        $matchingMembers = array_filter($allMembers, function (Member $member) use ($positionId): bool {
            return $member->getIntergroupPosition() === $positionId;
        });

        if (empty($matchingMembers)) {
            return '';
        }

        $matchingMembers = array_values($matchingMembers);

        if (count($matchingMembers) === 1) {
            return $matchingMembers[0]->getAnonymousName();
        }

        // Parse rotation dates and find the latest
        $latestDateStr = null;
        $parsed = []; // memberId => normalised Y-m-d string

        foreach ($matchingMembers as $member) {
            $rotationDateStr = $member->getIntergroupPositionRotation();

            if (empty($rotationDateStr)) {
                continue;
            }

            $dt = \DateTime::createFromFormat('Y-m-d', $rotationDateStr)
                ?: \DateTime::createFromFormat('d/m/Y', $rotationDateStr);

            if (!$dt) {
                continue;
            }

            $normalised = $dt->format('Y-m-d');
            $parsed[$member->getId()] = $normalised;

            if ($latestDateStr === null || $normalised > $latestDateStr) {
                $latestDateStr = $normalised;
            }
        }

        // No parseable rotation dates — fall back to the first member
        if ($latestDateStr === null) {
            return $matchingMembers[0]->getAnonymousName();
        }

        // Collect all members whose rotation date matches the latest
        $names = [];
        foreach ($matchingMembers as $member) {
            if (isset($parsed[$member->getId()]) && $parsed[$member->getId()] === $latestDateStr) {
                $names[] = $member->getAnonymousName();
            }
        }

        return implode(', ', $names);
    }

    /**
     * Resolve the GSR name for a group.
     *
     * Looks up the group's members and returns the anonymous name of
     * the first member marked as a GSR, or an empty string if none found.
     *
     * @param int $groupId The group ID
     * @return string GSR anonymous name or empty string
     */
    private function resolveGsrNameForGroup(int $groupId): string
    {
        $groupView = $this->groupViewFactory->createFrom($groupId);
        if (!$groupView) {
            return '';
        }

        foreach ($groupView->getMembers() as $member) {
            if ($member->isGSR()) {
                return $member->getAnonymousName();
            }
        }

        return '';
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
            .column-meeting_date { width: 15%; }
            .column-group_attendees { width: 25%; }
            .column-officers_attending { width: 25%; }
            .column-attendee_count { width: 10%; text-align: center; }
        </style>';
    }
}