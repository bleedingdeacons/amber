<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupMeetingAdmin;
use BleedingDeacons\WpMocks\WpState;
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

/*
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
 */

covers(IntergroupMeetingAdmin::class);

const IGM_TYPE        = 'intergroup-meeting';
const IGM_GROUP_TYPE  = 'tsml_group';
const IGM_MEMBER_TYPE = 'intergroup-member';

function igmPost(int $id, string $type): WP_Post
{
    return new WP_Post(['ID' => $id, 'post_type' => $type]);
}

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
        IntergroupMeeting::class => ['POST_TYPE' => IGM_TYPE],
        Group::class                        => ['POST_TYPE' => IGM_GROUP_TYPE],
        Member::class                      => ['POST_TYPE' => IGM_MEMBER_TYPE],
        default                                                      => [],
    });

    /** @var array<string, \PHPUnit\Framework\MockObject\MockObject> */
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

    $this->igm = function (int $id, string $title, string $date, array $groups = [], array $officers = []): IntergroupMeeting {
        $meeting = $this->createMock(IntergroupMeeting::class);
        $meeting->method('getId')->willReturn($id);
        $meeting->method('getTitle')->willReturn($title);
        $meeting->method('getDate')->willReturn($date);
        $meeting->method('getGroupAttendees')->willReturn($groups);
        $meeting->method('getOfficersAttending')->willReturn($officers);

        return $meeting;
    };

    $this->member = function (int $id, string $name, int $position = 0, bool $gsr = false): Member {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);
        $member->method('getIntergroupPosition')->willReturn($position);
        $member->method('isGSR')->willReturn($gsr);

        return $member;
    };
});

// ── columns ──────────────────────────────────────────────────────
describe('columns', function () {
    it('inserts the custom columns after title', function () {
        $columns = $this->admin->addCustomColumns(['cb' => '', 'title' => 'Title', 'date' => 'Date']);

        expect(array_keys($columns))
            ->toBe(['cb', 'title', 'meeting_date', 'group_attendees', 'officers_attending', 'attendee_count', 'date']);
    });

    it('makes the date and count columns sortable', function () {
        $sortable = $this->admin->makeColumnsSortable([]);

        expect($sortable['meeting_date'])->toBe('meeting_date')
            ->and($sortable['attendee_count'])->toBe('attendee_count');
    });

    it('formats the date in the meeting date column', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10'));

        expect($this->capture(fn () => $this->admin->populateCustomColumns('meeting_date', 1)))
            ->toContain('March 10, 2026');
    });

    it('dashes the meeting date column when there is no date', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', ''));

        expect($this->capture(fn () => $this->admin->populateCustomColumns('meeting_date', 1)))->toContain('—');
    });

    it('lists groups with their gsrs in the group attendees column', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [100]));

        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group);

        $gsr = ($this->member)(30, 'Anonymous Bob', 0, true);
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([$gsr]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);
        $this->m['memberRepo']->method('findById')->willReturn($gsr);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1));

        expect($html)->toContain('Tuesday Group')
            ->toContain('Anonymous Bob');
    });

    it('dashes the group attendees column when empty', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', []));

        expect($this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1)))->toContain('—');
    });

    it('lists positions with their holders in the officers column', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [], [200]));

        $holder = ($this->member)(50, 'Anonymous Jo');
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([$holder]);
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);
        $this->m['memberRepo']->method('findById')->willReturn($holder);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        expect($html)->toContain('Treasurer')
            ->toContain('Anonymous Jo');
    });

    it('dashes the officers column when empty', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [], []));

        expect($this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1)))->toContain('—');
    });

    it('shows the position alone in the officers column when no holder is named', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [], [200]));

        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Vacant Role');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([]);
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('');   // no display name
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        expect($html)->toContain('Vacant Role');
    });

    it('falls back to the display name when officers cannot be resolved to members', function () {
        // A position with a display name but no resolvable member records still
        // lists the names from the display string.
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [], [200]));

        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $view = $this->createMock(PositionView::class);
        $view->method('getMembers')->willReturn([]);           // no member ids
        $view->method('getPosition')->willReturn($position);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo, Anonymous Sam');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

        expect($html)->toContain('Anonymous Jo, Anonymous Sam');
    });

    it('dashes the officers column when no position resolves', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [], [200]));
        $this->m['positionViewFac']->method('createFrom')->willReturn(null);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1)))->toContain('—');
    });

    it('skips a group that cannot be resolved in the group attendees column', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [100]));

        // Group view resolves (for GSR lookup) but the group post itself is gone.
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);
        $this->m['groupRepo']->method('findById')->willReturn(null);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1)))->toContain('—');
    });

    it('renders a group whose view is missing in the group attendees column', function () {
        // The group post resolves but its live view (used to find GSRs) does
        // not — the group is still listed, just without GSRs.
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [100]));

        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group);
        $this->m['groupViewFactory']->method('createFrom')->willReturn(null);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1));

        expect($html)->toContain('Tuesday Group');
    });

    it('resolves repeated group ids once and reuses them from cache', function () {
        // A group appearing twice in the attendee list must hit the per-request
        // cache the second time rather than resolving again.
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [100, 100]));

        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->m['groupRepo']->method('findById')->willReturn($group);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([]);
        // createFrom is called exactly once despite two references to group 100.
        $this->m['groupViewFactory']->expects($this->once())->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group_attendees', 1));

        expect(substr_count($html, 'Tuesday Group'))->toBe(2);
    });

    it('totals groups and officers in the attendee count column', function () {
        $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [1, 2], [3]));

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('attendee_count', 1));

        expect($html)->toContain('3')
            ->toContain('2 groups, 1 officers');
    });
});

// ── ACF relationship label filters ───────────────────────────────
describe('ACF relationship label filters', function () {
    it('appends the position name to a member option', function () {
        $member = ($this->member)(5, 'Anonymous Alex', 77);
        $this->m['memberRepo']->method('findById')->willReturn($member);
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Secretary');
        $this->m['positionRepo']->method('findById')->willReturn($position);

        $result = $this->admin->addPositionName('Anonymous Alex', igmPost(5, IGM_MEMBER_TYPE), [], 0);

        expect($result)->toBe('Anonymous Alex (Secretary)');
    });

    it('leaves a member with no intergroup position unlabelled', function () {
        $this->m['memberRepo']->method('findById')->willReturn(($this->member)(5, 'Anonymous Alex', 0));

        expect($this->admin->addPositionName('Anonymous Alex', igmPost(5, IGM_MEMBER_TYPE), [], 0))->toBe('Anonymous Alex');
    });

    it('leaves a non member option untouched by the position filter', function () {
        expect($this->admin->addPositionName('X', igmPost(5, 'page'), [], 0))->toBe('X');
    });

    it('appends the officer name to a position option', function () {
        $view = $this->createMock(PositionView::class);
        $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        $result = $this->admin->addMemberNameToPosition('Treasurer', igmPost(9, 'intergroup-position'), [], 0);

        expect($result)->toBe('Treasurer (Anonymous Jo)');
    });

    it('leaves an unresolved member option unlabelled', function () {
        $this->m['memberRepo']->method('findById')->willReturn(null);

        expect($this->admin->addPositionName('X', igmPost(5, IGM_MEMBER_TYPE), [], 0))->toBe('X');
    });

    it('leaves a position option with no resolvable view unlabelled', function () {
        $this->m['positionViewFac']->method('createFrom')->willReturn(null);

        expect($this->admin->addMemberNameToPosition('Treasurer', igmPost(9, 'intergroup-position'), [], 0))->toBe('Treasurer');
    });

    it('leaves a position option with no officer name unlabelled', function () {
        $view = $this->createMock(PositionView::class);
        $view->method('getOfficerDisplayName')->willReturn('');
        $this->m['positionViewFac']->method('createFrom')->willReturn($view);

        expect($this->admin->addMemberNameToPosition('Treasurer', igmPost(9, 'intergroup-position'), [], 0))->toBe('Treasurer');
    });

    it('leaves a group option with no resolvable view unlabelled', function () {
        $this->m['groupViewFactory']->method('createFrom')->willReturn(null);

        expect($this->admin->addGsrsName('Tuesday Group', igmPost(3, IGM_GROUP_TYPE), [], 0))->toBe('Tuesday Group');
    });

    it('appends the gsr names to a group option', function () {
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([
            ($this->member)(1, 'Anonymous Alex', 0, true),
            ($this->member)(2, 'Not A GSR', 0, false),
        ]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);

        $result = $this->admin->addGsrsName('Tuesday Group', igmPost(3, IGM_GROUP_TYPE), [], 0);

        expect($result)->toBe('Tuesday Group (Anonymous Alex)');
    });

    it('leaves a group with no gsrs unlabelled', function () {
        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([($this->member)(2, 'Not A GSR', 0, false)]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($view);

        expect($this->admin->addGsrsName('Tuesday Group', igmPost(3, IGM_GROUP_TYPE), [], 0))->toBe('Tuesday Group');
    });
});

// ── sorting ──────────────────────────────────────────────────────
describe('sorting', function () {
    it('uses the sortable meta key when sorting by meeting date', function () {
        $this->setScreen('edit-' . IGM_TYPE, 'edit', IGM_TYPE);
        $query = new WP_Query(['orderby' => 'meeting_date']);
        $query->isMainQuery = true;

        $this->admin->handleCustomColumnSorting($query);

        expect($query->get('meta_key'))->toBe('_intergroup_meeting_date_sortable')
            ->and($query->get('orderby'))->toBe('meta_value');
    });

    it('uses a numeric meta sort when sorting by attendee count', function () {
        $this->setScreen('edit-' . IGM_TYPE, 'edit', IGM_TYPE);
        $query = new WP_Query(['orderby' => 'attendee_count']);
        $query->isMainQuery = true;

        $this->admin->handleCustomColumnSorting($query);

        expect($query->get('orderby'))->toBe('meta_value_num');
    });

    it('ignores sorting off the intergroup meeting screen', function () {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['orderby' => 'meeting_date']);
        $query->isMainQuery = true;

        $this->admin->handleCustomColumnSorting($query);

        expect($query->get('orderby'))->toBe('meeting_date');
    });
});

// ── save / attendance sync ───────────────────────────────────────
describe('save and attendance sync', function () {
    it('does nothing when saving a non intergroup meeting post', function () {
        WpState::$postTypes[42] = 'page';

        $this->m['igmFactory']->expects($this->never())->method('createFromSource');

        $this->admin->updateIntergroupMeetingMetadataOnSave(42);
    });

    it('stamps the sort meta and syncs added and removed attendees on save', function () {
        WpState::$postTypes[1] = IGM_TYPE;

        // The meeting now lists group 2 & 3 and officer 11 & 12.
        $meeting = ($this->igm)(1, 'March IG', '2026-03-10', [2, 3], [11, 12]);
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
        $gsrView->method('getMembers')->willReturn([($this->member)(30, 'Anonymous Bob', 0, true)]);
        $this->m['groupViewFactory']->method('createFrom')->willReturn($gsrView);

        // Added officer 12 resolves to a position with one holder.
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn('Treasurer');
        $offView = $this->createMock(PositionView::class);
        $offView->method('getMembers')->willReturn([($this->member)(50, 'Anonymous Jo')]);
        $offView->method('getPosition')->willReturn($position);
        $offView->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
        $this->m['positionViewFac']->method('createFrom')->willReturn($offView);

        $this->m['memberRepo']->method('findById')->willReturn(($this->member)(30, 'Anonymous Bob', 0, true));

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
        expect(WpState::$postMeta[1]['_intergroup_meeting_date_sortable'])->toBe('2026-03-10')
            ->and(WpState::$postMeta[1]['_intergroup_meeting_attendee_count'])->toBe(4);
    });

    it('clears the sort meta for a meeting saved without a date', function () {
        WpState::$postTypes[1] = IGM_TYPE;
        WpState::$postMeta[1]['_intergroup_meeting_date_sortable'] = 'stale';

        $meeting = ($this->igm)(1, 'IG', '', [], []);
        $this->m['igmFactory']->method('createFromSource')->willReturn($meeting);
        $this->m['groupAttRepo']->method('findByIntergroupMeeting')->willReturn([]);
        $this->m['offAttRepo']->method('findByIntergroupMeeting')->willReturn([]);

        $this->admin->updateIntergroupMeetingMetadata(1);

        expect(WpState::$postMeta[1])->not->toHaveKey('_intergroup_meeting_date_sortable');
    });

    it('walks every meeting when setting up all metadata', function () {
        $this->m['igmRepo']->method('findAll')->willReturn([
            ($this->igm)(1, 'A', '2026-01-01'),
            ($this->igm)(2, 'B', '2026-02-01'),
        ]);
        // createFromSource is used inside updateIntergroupMeetingMetadata.
        $this->m['igmFactory']->method('createFromSource')->willReturnCallback(
            fn (int $id) => ($this->igm)($id, 'M' . $id, '2026-0' . $id . '-01')
        );
        $this->m['groupAttRepo']->method('findByIntergroupMeeting')->willReturn([]);
        $this->m['offAttRepo']->method('findByIntergroupMeeting')->willReturn([]);

        expect($this->admin->setupAllIntergroupMeetingsMetadata())->toBe(2);
    });
});

// ── meeting label ────────────────────────────────────────────────
it('combines whatever it has into the meeting label', function (string $title, string $date, string $expected) {
    $method = new \ReflectionMethod(IntergroupMeetingAdmin::class, 'buildMeetingLabel');

    $label = $method->invoke($this->admin, ($this->igm)(1, $title, $date));

    expect($label)->toBe($expected);
})->with([
    'title and date' => ['March IG', '2026-03-10', 'March IG — March 10, 2026'],
    'title only'     => ['March IG', '', 'March IG'],
    'date only'      => ['', '2026-03-10', 'March 10, 2026'],
    'neither'        => ['', '', 'Meeting (ID: 1)'],
]);

// ── member position change ───────────────────────────────────────
describe('member position change', function () {
    it('does not touch attendance for an unchanged member', function () {
        $before = ($this->member)(5, 'Anonymous Alex', 10);
        $after  = ($this->member)(5, 'Anonymous Alex', 10);

        $this->m['offAttRepo']->expects($this->never())->method('findAll');

        $this->admin->onMemberPositionChanged($after, $before);
    });

    it('updates attendance for a member whose position changed on meeting day', function () {
        $before = ($this->member)(5, 'Anonymous Alex', 10);
        $after  = ($this->member)(5, 'Anonymous Alex', 20);

        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getIntergroupMeetingId')->willReturn(99);
        $this->m['offAttRepo']->method('findAll')->willReturn([$record]);

        // Today's meeting matches the record.
        $today = wp_date('Y-m-d');
        $this->m['igmRepo']->method('findById')->willReturn(($this->igm)(99, 'Today IG', $today));

        $newPosition = $this->createMock(Position::class);
        $newPosition->method('getLongName')->willReturn('Chair');
        $this->m['positionRepo']->method('findById')->willReturn($newPosition);

        $this->m['offAttRepo']->expects($this->once())->method('updateByMeetingAndOfficer')
            ->with(99, 5, 'Chair', 'Anonymous Alex')
            ->willReturn(1);

        $this->admin->onMemberPositionChanged($after, $before);
    });

    it('updates nothing for a member change with no meeting today', function () {
        $before = ($this->member)(5, 'Anonymous Alex', 10);
        $after  = ($this->member)(5, 'Anonymous Alex', 20);

        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getIntergroupMeetingId')->willReturn(99);
        $this->m['offAttRepo']->method('findAll')->willReturn([$record]);

        // The only attendance record points at a meeting dated in the past.
        $this->m['igmRepo']->method('findById')->willReturn(($this->igm)(99, 'Old IG', '2000-01-01'));

        $this->m['offAttRepo']->expects($this->never())->method('updateByMeetingAndOfficer');

        $this->admin->onMemberPositionChanged($after, $before);
    });

    it('skips a member with no attendance records', function () {
        $before = ($this->member)(5, 'Anonymous Alex', 10);
        $after  = ($this->member)(5, 'Anonymous Bob', 10); // name changed

        $this->m['offAttRepo']->method('findAll')->willReturn([]);
        $this->m['offAttRepo']->expects($this->never())->method('updateByMeetingAndOfficer');

        $this->admin->onMemberPositionChanged($after, $before);
    });
});

it('resolves repeated officer ids once and reuses them from cache', function () {
    $this->m['igmFactory']->method('createFromSource')->willReturn(($this->igm)(1, 'IG', '2026-03-10', [], [200, 200]));

    $position = $this->createMock(Position::class);
    $position->method('getLongName')->willReturn('Treasurer');
    $view = $this->createMock(PositionView::class);
    $view->method('getMembers')->willReturn([($this->member)(50, 'Anonymous Jo')]);
    $view->method('getPosition')->willReturn($position);
    $view->method('getOfficerDisplayName')->willReturn('Anonymous Jo');
    $this->m['positionViewFac']->expects($this->once())->method('createFrom')->willReturn($view);
    $this->m['memberRepo']->method('findById')->willReturn(($this->member)(50, 'Anonymous Jo'));

    $html = $this->capture(fn () => $this->admin->populateCustomColumns('officers_attending', 1));

    expect(substr_count($html, 'Treasurer'))->toBe(2);
});

// ── today's meeting lookup ───────────────────────────────────────
describe("today's meeting lookup", function () {
    it('finds the first meeting dated today', function () {
        $method = new \ReflectionMethod(IntergroupMeetingAdmin::class, 'findTodaysIntergroupMeeting');

        $meeting = ($this->igm)(5, 'Today IG', wp_date('Y-m-d'));
        $this->m['igmRepo']->method('findAll')->willReturn([$meeting]);

        expect($method->invoke($this->admin))->toBe($meeting);
    });

    it('finds nothing when no meeting is dated today', function () {
        $method = new \ReflectionMethod(IntergroupMeetingAdmin::class, 'findTodaysIntergroupMeeting');

        $this->m['igmRepo']->method('findAll')->willReturn([]);

        expect($method->invoke($this->admin))->toBeNull();
    });
});

// ── styles ───────────────────────────────────────────────────────
it('loads column styles only on the intergroup meeting screen', function () {
    $this->setScreen('edit-' . IGM_TYPE, 'edit', IGM_TYPE);
    expect($this->capture(fn () => $this->admin->addAdminColumnStyles()))->toContain('<style>');

    $this->setScreen('edit-page', 'edit', 'page');
    expect($this->capture(fn () => $this->admin->addAdminColumnStyles()))->toBe('');
});
