<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupMeetingAdmin;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupView;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;
use WP_Post;
use WP_Query;

/**
 * Tests for the intergroup-meeting admin list and its attendance sync.
 *
 * Two responsibilities live here. First, the list table: extra columns for the
 * meeting date, the attending groups (with each group's GSRs), the attending
 * officers (with each position's holders), and an eligible-attendee count,
 * plus the meta-key rewrites that make date and count sortable. Second, and the
 * riskier half, the save hook: when a meeting's group/officer relationship
 * fields change, it diffs them against the archived attendance tables and
 * creates rows for the newly added and deletes rows for the removed. The test
 * drives that diff with a meeting that both gains and loses a group and an
 * officer, asserting the factory/save/delete calls land. It also walks the ACF
 * relationship-label filters that annotate each option with its position, GSRs
 * or officer name.
 *
 * @covers \Amber\Admin\IntergroupMeetings\IntergroupMeetingAdmin
 */
class IntergroupMeetingAdminTest extends AmberTestCase
{
    private const IGM_TYPE    = 'intergroup-meeting';
    private const GROUP_TYPE  = 'tsml_group';
    private const MEMBER_TYPE = 'intergroup-member';

    private IntergroupMeetingAdmin $admin;

    /** @var array<string, \PHPUnit\Framework\MockObject\MockObject> */
    private array $m = [];

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
            \Unity\IntergroupMeetings\Interfaces\IntergroupMeeting::class => ['POST_TYPE' => self::IGM_TYPE],
            \Unity\Groups\Interfaces\Group::class                        => ['POST_TYPE' => self::GROUP_TYPE],
            \Unity\Members\Interfaces\Member::class                      => ['POST_TYPE' => self::MEMBER_TYPE],
            default                                                      => [],
        });

        $this->m = [
            'igmFactory'       => $this->createMock(IntergroupMeetingFactory::class),
            'igmRepo'          => $this->createMock(IntergroupMeetingRepository::class),
            'groupAttFactory'  => $this->createMock(IntergroupMeetingGroupAttendanceFactory::class),
            'groupAttRepo'     => $this->createMock(IntergroupMeetingGroupAttendanceRepository::class),
            'offAttFactory'    => $this->createMock(IntergroupMeetingOfficerAttendanceFactory::class),
            'offAttRepo'       => $this->createMock(IntergroupMeetingOfficerAttendanceRepository::class),
            'groupRepo'        => $this->createMock(GroupRepository::class),
            'memberRepo'       => $this->createMock(MemberRepository::class),
            'positionFactory'  => $this->createMock(PositionFactory::class),
            'positionRepo'     => $this->createMock(PositionRepository::class),
            'positionViewFac'  => $this->createMock(PositionViewFactory::class),
            'meetingRepo'      => $this->createMock(MeetingRepository::class),
            'groupViewFactory' => $this->createMock(GroupViewFactory::class),
        ];

        $this->admin = new IntergroupMeetingAdmin(
            $config,
            $this->m['igmFactory'],
            $this->m['igmRepo'],
            $this->m['groupAttFactory'],
            $this->m['groupAttRepo'],
            $this->m['offAttFactory'],
            $this->m['offAttRepo'],
            $this->m['groupRepo'],
            $this->m['memberRepo'],
            $this->m['positionFactory'],
            $this->m['positionRepo'],
            $this->m['positionViewFac'],
            $this->m['meetingRepo'],
            $this->m['groupViewFactory']
        );
    }

    private function igm(int $id, string $title, string $date, array $groups = [], array $officers = []): IntergroupMeeting
    {
        $meeting = $this->createMock(IntergroupMeeting::class);
        $meeting->method('getId')->willReturn($id);
        $meeting->method('getTitle')->willReturn($title);
        $meeting->method('getDate')->willReturn($date);
        $meeting->method('getGroupAttendees')->willReturn($groups);
        $meeting->method('getOfficersAttending')->willReturn($officers);

        return $meeting;
    }

    private function member(int $id, string $name, int $position = 0, bool $gsr = false): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);
        $member->method('getIntergroupPosition')->willReturn($position);
        $member->method('isGSR')->willReturn($gsr);

        return $member;
    }

    private function post(int $id, string $type): WP_Post
    {
        return new WP_Post(['ID' => $id, 'post_type' => $type]);
    }

    // ── columns ──────────────────────────────────────────────────────

    /** @test */
    public function the_custom_columns_are_inserted_after_title(): void
    {
        $columns = $this->admin->addCustomColumns(['cb' => '', 'title' => 'Title', 'date' => 'Date']);

        $this->assertSame(
            ['cb', 'title', 'meeting_date', 'group_attendees', 'officers_attending', 'attendee_count', 'date'],
            array_keys($columns)
        );
    }

    /** @test */
    public function the_date_and_count_columns_are_sortable(): void
    {
        $sortable = $this->admin->makeColumnsSortable([]);

        $this->assertSame('meeting_date', $sortable['meeting_date']);
        $this->assertSame('attendee_count', $sortable['attendee_count']);
    }

    /** @test */
    public function the_meeting_date_column_formats_the_date(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10'));

        $this->assertStringContainsString(
            'March 10, 2026',
            $this->capture(fn () => $this->admin->populateCustomColumns('meeting_date', 1))
        );
    }

    /** @test */
    public function the_meeting_date_column_dashes_when_there_is_no_date(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', ''));

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('meeting_date', 1)));
    }

    /** @test */
    public function the_group_attendees_column_lists_groups_with_their_gsrs(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [100]));

        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group);

        $gsr = $this->member(30, 'Anonymous Bob', 0, true);
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([$gsr]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);
        $this->m['memberRepo']->method('findById')->willReturn($gsr);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1));

        $this->assertStringContainsString('Tuesday Group', $html);
        $this->assertStringContainsString('Anonymous Bob', $html);
    }

    /** @test */
    public function the_group_attendees_column_dashes_when_empty(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', []));

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1)));
    }

    /** @test */
    public function the_officers_column_lists_positions_with_their_holders(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [], [200]));

        $holder = $this->member(50, 'Anonymous Jo');
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([$holder]);
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);
        $this->m['memberRepo']->method('findById')->willReturn($holder);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        $this->assertStringContainsString('Treasurer', $html);
        $this->assertStringContainsString('Anonymous Jo', $html);
    }

    /** @test */
    public function the_officers_column_dashes_when_empty(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [], []));

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1)));
    }

    /** @test */
    public function the_officers_column_shows_the_position_alone_when_no_holder_is_named(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [], [200]));

        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Vacant Role');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([]);
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('');   // no display name
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        $this->assertStringContainsString('Vacant Role', $html);
    }

    /** @test */
    public function officers_fall_back_to_the_display_name_when_members_cannot_be_resolved(): void
    {
        // A position with a display name but no resolvable member records still
        // lists the names from the display string.
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [], [200]));

        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([]);           // no member ids
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo, Anonymous Sam');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        $this->assertStringContainsString('Anonymous Jo, Anonymous Sam', $html);
    }

    /** @test */
    public function officers_column_dashes_when_no_position_resolves(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [], [200]));
        $this->m['positionViewFac']->method('createFrom')->willReturn(null);

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1)));
    }

    /** @test */
    public function the_group_attendees_column_skips_a_group_that_cannot_be_resolved(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [100]));

        // Group view resolves (for GSR lookup) but the group post itself is gone.
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);
        $this->m['groupRepo']->method('findById')->willReturn(null);

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1)));
    }

    /** @test */
    public function the_group_attendees_column_renders_a_group_whose_view_is_missing(): void
    {
        // The group post resolves but its live view (used to find GSRs) does
        // not — the group is still listed, just without GSRs.
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [100]));

        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group);
        $this->m['groupViewFactory']->method('createFrom')->willReturn(null);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1));

        $this->assertStringContainsString('Tuesday Group', $html);
    }

    /** @test */
    public function repeated_group_ids_are_resolved_once_and_reused_from_cache(): void
    {
        // A group appearing twice in the attendee list must hit the per-request
        // cache the second time rather than resolving again.
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [100, 100]));

        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([]);
        // createFrom is called exactly once despite two references to group 100.
        $this->m['groupViewFactory']->expects($this->once())->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1));

        $this->assertSame(2, substr_count($html, 'Tuesday Group'));
    }

    /** @test */
    public function the_attendee_count_column_totals_groups_and_officers(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [1, 2], [3]));

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('attendee_count', 1));

        $this->assertStringContainsString('3', $html);
        $this->assertStringContainsString('2 groups, 1 officers', $html);
    }

    // ── ACF relationship label filters ───────────────────────────────

    /** @test */
    public function the_position_name_is_appended_to_a_member_option(): void
    {
        $member = $this->member(5, 'Anonymous Alex', 77);
        $this->m['memberRepo']->method('findById')->willReturn($member);
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Secretary');
        $this->m['positionRepo']->method('findById')->willReturn($position);

        $result = $this->admin->addPositionName('Anonymous Alex', $this->post(5, self::MEMBER_TYPE), [], 0);

        $this->assertSame('Anonymous Alex (Secretary)', $result);
    }

    /** @test */
    public function a_member_with_no_intergroup_position_is_left_unlabelled(): void
    {
        $this->m['memberRepo']->method('findById')->willReturn($this->member(5, 'Anonymous Alex', 0));

        $this->assertSame('Anonymous Alex', $this->admin->addPositionName('Anonymous Alex', $this->post(5, self::MEMBER_TYPE), [], 0));
    }

    /** @test */
    public function a_non_member_option_is_untouched_by_the_position_filter(): void
    {
        $this->assertSame('X', $this->admin->addPositionName('X', $this->post(5, 'page'), [], 0));
    }

    /** @test */
    public function the_officer_name_is_appended_to_a_position_option(): void
    {
        $view = $this->createMock(PositionView::class);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $result = $this->admin->addMemberNameToPosition('Treasurer', $this->post(9, 'intergroup-position'), [], 0);

        $this->assertSame('Treasurer (Anonymous Jo)', $result);
    }

    /** @test */
    public function an_unresolved_member_option_is_left_unlabelled(): void
    {
        $this->m['memberRepo']->method('findById')->willReturn(null);

        $this->assertSame('X', $this->admin->addPositionName('X', $this->post(5, self::MEMBER_TYPE), [], 0));
    }

    /** @test */
    public function a_position_option_with_no_resolvable_view_is_left_unlabelled(): void
    {
        $this->m['positionViewFac']->method('createFrom')->willReturn(null);

        $this->assertSame('Treasurer', $this->admin->addMemberNameToPosition('Treasurer', $this->post(9, 'intergroup-position'), [], 0));
    }

    /** @test */
    public function a_position_option_with_no_officer_name_is_left_unlabelled(): void
    {
        $view = $this->createMock(PositionView::class);
        $view->method('getOfficerDisplayName')->willReturn('');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $this->assertSame('Treasurer', $this->admin->addMemberNameToPosition('Treasurer', $this->post(9, 'intergroup-position'), [], 0));
    }

    /** @test */
    public function a_group_option_with_no_resolvable_view_is_left_unlabelled(): void
    {
        $this->m['groupViewFactory']->method('createFrom')->willReturn(null);

        $this->assertSame('Tuesday Group', $this->admin->addGsrsName('Tuesday Group', $this->post(3, self::GROUP_TYPE), [], 0));
    }

    /** @test */
    public function the_gsr_names_are_appended_to_a_group_option(): void
    {
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([
            $this->member(1, 'Anonymous Alex', 0, true),
            $this->member(2, 'Not A GSR', 0, false),
        ]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);

        $result = $this->admin->addGsrsName('Tuesday Group', $this->post(3, self::GROUP_TYPE), [], 0);

        $this->assertSame('Tuesday Group (Anonymous Alex)', $result);
    }

    /** @test */
    public function a_group_with_no_gsrs_is_left_unlabelled(): void
    {
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([$this->member(2, 'Not A GSR', 0, false)]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);

        $this->assertSame('Tuesday Group', $this->admin->addGsrsName('Tuesday Group', $this->post(3, self::GROUP_TYPE), [], 0));
    }

    // ── sorting ──────────────────────────────────────────────────────

    /** @test */
    public function sorting_by_meeting_date_uses_the_sortable_meta_key(): void
    {
        $this->setScreen('edit-' . self::IGM_TYPE, 'edit', self::IGM_TYPE);
        $query = new WP_Query(['orderby' => 'meeting_date']);
        $query->isMainQuery = true;

        $this->admin->handleCustomColumnSorting($query);

        $this->assertSame('_intergroup_meeting_date_sortable', $query->get('meta_key'));
        $this->assertSame('meta_value', $query->get('orderby'));
    }

    /** @test */
    public function sorting_by_attendee_count_uses_a_numeric_meta_sort(): void
    {
        $this->setScreen('edit-' . self::IGM_TYPE, 'edit', self::IGM_TYPE);
        $query = new WP_Query(['orderby' => 'attendee_count']);
        $query->isMainQuery = true;

        $this->admin->handleCustomColumnSorting($query);

        $this->assertSame('meta_value_num', $query->get('orderby'));
    }

    /** @test */
    public function sorting_is_ignored_off_the_intergroup_meeting_screen(): void
    {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['orderby' => 'meeting_date']);
        $query->isMainQuery = true;

        $this->admin->handleCustomColumnSorting($query);

        $this->assertSame('meeting_date', $query->get('orderby'));
    }

    // ── save / attendance sync ───────────────────────────────────────

    /** @test */
    public function saving_a_non_intergroup_meeting_post_does_nothing(): void
    {
        WpState::$postTypes[42] = 'page';

        $this->m['igmFactory']->expects($this->never())->method('createFromSource');

        $this->admin->updateIntergroupMeetingMetadataOnSave(42);
    }

    /** @test */
    public function saving_stamps_the_sort_meta_and_syncs_added_and_removed_attendees(): void
    {
        WpState::$postTypes[1] = self::IGM_TYPE;

        // The meeting now lists group 2 & 3 and officer 11 & 12.
        $meeting = $this->igm(1, 'March IG', '2026-03-10', [2, 3], [11, 12]);
        $this->m['igmFactory']->method('createFromSource')->willReturn($meeting);

        // Existing attendance: group 1 & 2, officer 10 & 11 → add 3/12, remove 1/10.
        $groupRec1 = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $groupRec1->method('getGroupId')->willReturn(1);
        $groupRec2 = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $groupRec2->method('getGroupId')->willReturn(2);
        $this->m['groupAttRepo']->method('findByIntergroupMeeting')->willReturn([$groupRec1, $groupRec2]);

        $offRec10 = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $offRec10->method('getOfficerId')->willReturn(10);
        $offRec11 = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $offRec11->method('getOfficerId')->willReturn(11);
        $this->m['offAttRepo']->method('findByIntergroupMeeting')->willReturn([$offRec10, $offRec11]);

        // Added group 3 resolves to a titled group with one GSR.
        $group3 = $this->createMock(Group::class);
        $group3->method('getTitle')->willReturn('Friday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group3);
        $gsrView = $this->createMock(GroupView::class);
        $gsrView->method('getMembers')->willReturn([$this->member(30, 'Anonymous Bob', 0, true)]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($gsrView);

        // Added officer 12 resolves to a position with one holder.
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $offView = $this->createMock(PositionView::class);
        $offView->method('getMembers')->willReturn([$this->member(50, 'Anonymous Jo')]);
        $offView->method('getPosition')->willReturn($position);
        $offView->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->method('createFrom')->willReturn($offView);

        $this->m['memberRepo']->method('findById')->willReturn($this->member(30, 'Anonymous Bob', 0, true));

        // Expect one create+save and one delete on each side.
        $this->m['groupAttFactory']->expects($this->once())->method('createNew')
            ->willReturn($this->createMock(IntergroupMeetingGroupAttendance::class));
        $this->m['groupAttRepo']->expects($this->once())->method('save');
        $this->m['groupAttRepo']->expects($this->once())->method('deleteByIntergroupMeetingAndGroup')->with(1, 1);

        $this->m['offAttFactory']->expects($this->once())->method('createNew')
            ->willReturn($this->createMock(IntergroupMeetingOfficerAttendance::class));
        $this->m['offAttRepo']->expects($this->once())->method('save');
        $this->m['offAttRepo']->expects($this->once())->method('deleteByIntergroupMeetingAndOfficer')->with(1, 10);

        $this->admin->updateIntergroupMeetingMetadataOnSave(1);

        // Sort meta stamped.
        $this->assertSame('2026-03-10', WpState::$postMeta[1]['_intergroup_meeting_date_sortable']);
        $this->assertSame(4, WpState::$postMeta[1]['_intergroup_meeting_attendee_count']);
    }

    /** @test */
    public function a_meeting_saved_without_a_date_clears_the_sort_meta(): void
    {
        WpState::$postTypes[1] = self::IGM_TYPE;
        WpState::$postMeta[1]['_intergroup_meeting_date_sortable'] = 'stale';

        $meeting = $this->igm(1, 'IG', '', [], []);
        $this->m['igmFactory']->method('createFromSource')->willReturn($meeting);
        $this->m['groupAttRepo']->method('findByIntergroupMeeting')->willReturn([]);
        $this->m['offAttRepo']->method('findByIntergroupMeeting')->willReturn([]);

        $this->admin->updateIntergroupMeetingMetadata(1);

        $this->assertArrayNotHasKey('_intergroup_meeting_date_sortable', WpState::$postMeta[1]);
    }

    /** @test */
    public function setup_all_metadata_walks_every_meeting(): void
    {
        $this->m['igmRepo']->method('findAll')->willReturn([
            $this->igm(1, 'A', '2026-01-01'),
            $this->igm(2, 'B', '2026-02-01'),
        ]);
        // createFromSource is used inside updateIntergroupMeetingMetadata.
        $this->m['igmFactory']->method('createFromSource')->willReturnCallback(
            fn (int $id) => $this->igm($id, 'M' . $id, '2026-0' . $id . '-01')
        );
        $this->m['groupAttRepo']->method('findByIntergroupMeeting')->willReturn([]);
        $this->m['offAttRepo']->method('findByIntergroupMeeting')->willReturn([]);

        $this->assertSame(2, $this->admin->setupAllIntergroupMeetingsMetadata());
    }

    // ── meeting label ────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider meetingLabelProvider
     */
    public function the_meeting_label_combines_whatever_it_has(string $title, string $date, string $expected): void
    {
        $method = new \ReflectionMethod(IntergroupMeetingAdmin::class, 'buildMeetingLabel');

        $label = $method->invoke($this->admin, $this->igm(1, $title, $date));

        $this->assertSame($expected, $label);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function meetingLabelProvider(): array
    {
        return [
            'title and date' => ['March IG', '2026-03-10', 'March IG — March 10, 2026'],
            'title only'     => ['March IG', '', 'March IG'],
            'date only'      => ['', '2026-03-10', 'March 10, 2026'],
            'neither'        => ['', '', 'Meeting (ID: 1)'],
        ];
    }

    // ── member position change ───────────────────────────────────────

    /** @test */
    public function an_unchanged_member_does_not_touch_attendance(): void
    {
        $before = $this->member(5, 'Anonymous Alex', 10);
        $after  = $this->member(5, 'Anonymous Alex', 10);

        $this->m['offAttRepo']->expects($this->never())->method('findAll');

        $this->admin->onMemberPositionChanged($after, $before);
    }

    /** @test */
    public function a_member_whose_position_changed_on_meeting_day_has_attendance_updated(): void
    {
        $before = $this->member(5, 'Anonymous Alex', 10);
        $after  = $this->member(5, 'Anonymous Alex', 20);

        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getIntergroupMeetingId')->willReturn(99);
        $this->m['offAttRepo']->method('findAll')->willReturn([$record]);

        // Today's meeting matches the record.
        $today = wp_date('Y-m-d');
        $this->m['igmRepo']->method('findById')->willReturn($this->igm(99, 'Today IG', $today));

        $newPosition = $this->createMock(Position::class);
        $newPosition->method('getLongName')->willReturn('Chair');
        $this->m['positionRepo']->method('findById')->willReturn($newPosition);

        $this->m['offAttRepo']->expects($this->once())->method('updateByMeetingAndOfficer')
            ->with(99, 5, 'Chair', 'Anonymous Alex')
            ->willReturn(1);

        $this->admin->onMemberPositionChanged($after, $before);
    }

    /** @test */
    public function a_member_change_with_no_meeting_today_updates_nothing(): void
    {
        $before = $this->member(5, 'Anonymous Alex', 10);
        $after  = $this->member(5, 'Anonymous Alex', 20);

        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getIntergroupMeetingId')->willReturn(99);
        $this->m['offAttRepo']->method('findAll')->willReturn([$record]);

        // The only attendance record points at a meeting dated in the past.
        $this->m['igmRepo']->method('findById')->willReturn($this->igm(99, 'Old IG', '2000-01-01'));

        $this->m['offAttRepo']->expects($this->never())->method('updateByMeetingAndOfficer');

        $this->admin->onMemberPositionChanged($after, $before);
    }

    /** @test */
    public function a_member_with_no_attendance_records_is_skipped(): void
    {
        $before = $this->member(5, 'Anonymous Alex', 10);
        $after  = $this->member(5, 'Anonymous Bob', 10); // name changed

        $this->m['offAttRepo']->method('findAll')->willReturn([]);
        $this->m['offAttRepo']->expects($this->never())->method('updateByMeetingAndOfficer');

        $this->admin->onMemberPositionChanged($after, $before);
    }

    /** @test */
    public function repeated_officer_ids_are_resolved_once_and_reused_from_cache(): void
    {
        $this->m['igmFactory']->method('createFromSource')->willReturn($this->igm(1, 'IG', '2026-03-10', [], [200, 200]));

        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([$this->member(50, 'Anonymous Jo')]);
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->expects($this->once())->method('createFrom')->willReturn($view);
        $this->m['memberRepo']->method('findById')->willReturn($this->member(50, 'Anonymous Jo'));

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        $this->assertSame(2, substr_count($html, 'Treasurer'));
    }

    // ── today's meeting lookup ───────────────────────────────────────

    /** @test */
    public function todays_meeting_is_the_first_one_dated_today(): void
    {
        $method = new \ReflectionMethod(IntergroupMeetingAdmin::class, 'findTodaysIntergroupMeeting');

        $meeting = $this->igm(5, 'Today IG', wp_date('Y-m-d'));
        $this->m['igmRepo']->method('findAll')->willReturn([$meeting]);

        $this->assertSame($meeting, $method->invoke($this->admin));
    }

    /** @test */
    public function there_is_no_todays_meeting_when_none_is_dated_today(): void
    {
        $method = new \ReflectionMethod(IntergroupMeetingAdmin::class, 'findTodaysIntergroupMeeting');

        $this->m['igmRepo']->method('findAll')->willReturn([]);

        $this->assertNull($method->invoke($this->admin));
    }

    // ── styles ───────────────────────────────────────────────────────

    /** @test */
    public function column_styles_load_only_on_the_intergroup_meeting_screen(): void
    {
        $this->setScreen('edit-' . self::IGM_TYPE, 'edit', self::IGM_TYPE);
        $this->assertStringContainsString('<style>', $this->capture(fn () => $this->admin->addAdminColumnStyles()));

        $this->setScreen('edit-page', 'edit', 'page');
        $this->assertSame('', $this->capture(fn () => $this->admin->addAdminColumnStyles()));
    }
}
