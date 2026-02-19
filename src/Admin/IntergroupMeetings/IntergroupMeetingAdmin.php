<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

use TsmlForUnity\Groups\TsmlGroupViewFactory;
use TsmlForUnity\Meetings\TsmlMeetingViewFactory;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Meetings\Interfaces\MeetingViewFactory;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;

use Unity\Positions\Interfaces\PositionRepository;
use WP_Post;
use WP_Query;
use function add_action;
use function add_filter;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function is_admin;
use function update_post_meta;
use function delete_post_meta;
use const DOING_AJAX;
use const DOING_AUTOSAVE;

/**
 * Intergroup Meeting Admin
 *
 * Adds custom columns to the admin table view for intergroup meetings.
 */
class IntergroupMeetingAdmin
{
    private IntergroupMeetingFactory $intergroupMeetingFactory;
    private IntergroupMeetingRepository $intergroupMeetingRepository;
    private GroupRepository $groupRepository;
    private MemberRepository $memberRepository;
    private PositionFactory $positionFactory;
    private PositionRepository $positionRepository;
    private MeetingRepository $meetingRepository;
    private GroupViewFactory $groupViewFactory;
    private readonly array $intergroupMeetingConfig;
    private readonly array $groupConfig;
    private readonly array $memberConfig;

    private MeetingViewFactory $meetingViewFactory;
    /**
     * Constructor
     *
     * @param IntergroupMeetingFactory $intergroupMeetingFactory Intergroup meeting factory
     * @param IntergroupMeetingRepository $intergroupMeetingRepository Intergroup meeting repository
     * @param GroupRepository $groupRepository Group repository
     * @param MemberRepository $memberRepository Member repository
     * @param PositionFactory $positionFactory Position factory
     * @param PositionRepository $positionRepository Member repository
     */
    public function __construct(
        Configuration $configuration,
        IntergroupMeetingFactory $intergroupMeetingFactory,
        IntergroupMeetingRepository $intergroupMeetingRepository,
        GroupRepository $groupRepository,
        MemberRepository $memberRepository,
        PositionFactory $positionFactory,
        PositionRepository $positionRepository,
        MeetingRepository $meetingRepository
    ) {

        $this->intergroupMeetingConfig = $configuration->getConfig(IntergroupMeeting::class);

        $this->groupConfig = $configuration->getConfig(Group::class);
        $this->memberConfig = $configuration->getConfig(Member::class);

        $this->intergroupMeetingFactory = $intergroupMeetingFactory;
        $this->intergroupMeetingRepository = $intergroupMeetingRepository;
        $this->groupRepository = $groupRepository;
        $this->memberRepository = $memberRepository;
        $this->positionFactory = $positionFactory;
        $this->positionRepository = $positionRepository;
        $this->meetingRepository = $meetingRepository;

        // TODO Add to Container
        $this->groupViewFactory = new TsmlGroupViewFactory($this->groupRepository, $this->meetingRepository, $this->memberRepository, $this->meetingRepository);

        add_filter('manage_' . $this->intergroupMeetingConfig['POST_TYPE'] . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . $this->intergroupMeetingConfig['POST_TYPE'] . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . $this->intergroupMeetingConfig['POST_TYPE'] . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_filter('pre_get_posts', [$this, 'handleCustomColumnSorting']);
        add_action('save_post_' . $this->intergroupMeetingConfig['POST_TYPE'], [$this, 'updateIntergroupMeetingMetadataOnSave'], 10, 3);
        add_action('admin_head', [$this, 'addAdminColumnStyles']);
        add_filter('acf/fields/relationship/result',[$this, 'addPositionName'],10, 4);
        add_filter('acf/fields/relationship/result',[$this, 'addGsrsName'],10, 4);
        
    }


    /**
     * add name of position in relationship list.
     *
     * @return int Number of meetings updated
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
     * add name of gsr's in relationship list.
     *
     * @return int Number of meetings updated
     */
    public function addGsrsName($title, $post, $field, $post_id) {

        if ($post->post_type !== $this->groupConfig['POST_TYPE']) {
            return $title;
        }

        $group = $this->groupViewFactory->createFrom($post->ID);

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
            echo esc_html(date('F j, Y', $timestamp));
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
        // TODO: Add GSR Names
        $names = [];
        foreach ($attendeeIds as $id) {
            $group = $this->groupRepository->findById($id);
            if ($group) {
                $editLink = get_edit_post_link($id);
                if ($editLink) {
                    $names[] = '<a href="' . esc_url($editLink) . '">' . esc_html($group->getTitle()) . '</a>';
                } else {
                    $names[] = esc_html($group->getTitle());
                }
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
        $officerIds = $meeting->getOfficersAttending();

        if (empty($officerIds)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        $names = [];
        foreach ($officerIds as $id) {
            $member = $this->memberRepository->find($id);
            if ($member) {
                $displayName = $member->getAnonymousName();
                $positionId = $member->getIntergroupPosition();
                $position = $positionId ? $this->positionFactory->createFromSource($positionId) : null;
                $label = $position
                    ? $displayName . ' (' . $position->getLongName() . ')'
                    : $displayName;
                $editLink = get_edit_post_link($id);
                if ($editLink) {
                    $names[] = '<a href="' . esc_url($editLink) . '">' . esc_html($label) . '</a>';
                } else {
                    $names[] = esc_html($label);
                }
            }
        }

        if (empty($names)) {
            echo '<span style="color: gray;">—</span>';
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
     * Update intergroup meeting metadata when saved
     *
     * @param int $postId The post ID
     * @param WP_Post $post The post object
     * @param bool $update Whether this is an update or a new post
     */
    public function updateIntergroupMeetingMetadataOnSave(int $postId, WP_Post $post, bool $update): void
    {
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