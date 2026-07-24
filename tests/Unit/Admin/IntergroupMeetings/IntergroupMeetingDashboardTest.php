<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupMeetingDashboard;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Tests for the Intergroup Meetings dashboard widget.
 *
 * The widget reads the archived attendance tables and draws one card per
 * intergroup meeting, newest first, listing the groups and officers who were
 * eligible. The interesting work is in the two attendee renderers: each GSR or
 * officer name is turned into a link only when it resolves to a known member,
 * and falls back to plain text otherwise — the mechanism by which a name typed
 * into the register but not matching a member record still shows. The header's
 * title/date label has four shapes (both, title-only, date-only, neither) that
 * decide whether the card is even identifiable.
 *
 * @covers \Amber\Admin\IntergroupMeetings\IntergroupMeetingDashboard
 */
class IntergroupMeetingDashboardTest extends AmberTestCase
{
    /** @var IntergroupMeetingRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $meetingRepository;

    /** @var IntergroupMeetingGroupAttendanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $groupAttendance;

    /** @var IntergroupMeetingOfficerAttendanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $officerAttendance;

    /** @var MemberRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $memberRepository;

    private IntergroupMeetingDashboard $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->meetingRepository = $this->createMock(IntergroupMeetingRepository::class);
        $this->groupAttendance   = $this->createMock(IntergroupMeetingGroupAttendanceRepository::class);
        $this->officerAttendance = $this->createMock(IntergroupMeetingOfficerAttendanceRepository::class);
        $this->memberRepository  = $this->createMock(MemberRepository::class);

        $this->dashboard = new IntergroupMeetingDashboard(
            $this->meetingRepository,
            $this->groupAttendance,
            $this->officerAttendance,
            $this->memberRepository
        );
    }

    private function meeting(int $id, string $title, string $date, int $groups = 0, int $officers = 0): IntergroupMeeting
    {
        $meeting = $this->createMock(IntergroupMeeting::class);
        $meeting->method('getId')->willReturn($id);
        $meeting->method('getTitle')->willReturn($title);
        $meeting->method('getDate')->willReturn($date);
        $meeting->method('getGroupAttendees')->willReturn(array_fill(0, $groups, 'g'));
        $meeting->method('getOfficersAttending')->willReturn(array_fill(0, $officers, 'o'));

        return $meeting;
    }

    private function member(int $id, string $name): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    }

    private function groupRecord(string $group, string $gsrName): IntergroupMeetingGroupAttendance
    {
        $record = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $record->method('getMeetingGroup')->willReturn($group);
        $record->method('getGsrName')->willReturn($gsrName);

        return $record;
    }

    private function officerRecord(string $position, string $officerName): IntergroupMeetingOfficerAttendance
    {
        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getPositionName')->willReturn($position);
        $record->method('getOfficerName')->willReturn($officerName);

        return $record;
    }

    // ── empty state ──────────────────────────────────────────────────

    /** @test */
    public function an_empty_archive_says_so(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([]);

        $this->assertStringContainsString(
            'No intergroup meetings found',
            $this->capture(fn () => $this->dashboard->renderDashboardWidget())
        );
    }

    // ── header label shapes ──────────────────────────────────────────

    /** @test */
    public function meetings_are_ordered_newest_first_with_a_title_and_date_label(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([
            $this->meeting(1, 'January IG', '2026-01-10'),
            $this->meeting(2, 'March IG', '2026-03-10'),
        ]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('January IG', $html);
        $this->assertStringContainsString('March IG', $html);
        // Formatted and sorted so March (later) precedes January.
        $this->assertLessThan(strpos($html, 'January'), strpos($html, 'March'));
        $this->assertStringContainsString('March 10, 2026', $html);
    }

    /** @test */
    public function a_meeting_with_neither_title_nor_date_is_flagged(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1, '', '')]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

        $this->assertStringContainsString('No Title or Date', $this->capture(fn () => $this->dashboard->renderDashboardWidget()));
    }

    /** @test */
    public function the_eligible_badge_totals_groups_and_officers(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1, 'IG', '2026-01-10', 3, 2)]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('3 groups, 2 officers', $html);
        $this->assertStringContainsString('>5<', $html);
    }

    // ── group attendees ──────────────────────────────────────────────

    /** @test */
    public function group_attendees_link_known_gsrs_and_show_unknown_ones_as_text(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1, 'IG', '2026-01-10')]);
        $this->memberRepository->method('findAll')->willReturn([$this->member(7, 'Anonymous Alex')]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([
            $this->groupRecord('Tuesday Group', 'Anonymous Alex, Anonymous Sam'),
            $this->groupRecord('Solo Group', ''),
        ]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        // Known GSR is linked; unknown one is plain; a group with no GSR just names the group.
        $this->assertStringContainsString('<a href="https://example.test/wp-admin/post.php?post=7&action=edit">Anonymous Alex</a>', $html);
        $this->assertStringContainsString('Anonymous Sam', $html);
        $this->assertStringContainsString('Solo Group', $html);
    }

    /** @test */
    public function a_meeting_with_no_group_records_dashes_the_groups_cell(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1, 'IG', '2026-01-10')]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('—', $html);
    }

    // ── officers ─────────────────────────────────────────────────────

    /** @test */
    public function officers_link_known_members_and_fall_back_to_the_position_alone(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1, 'IG', '2026-01-10')]);
        $this->memberRepository->method('findAll')->willReturn([$this->member(9, 'Anonymous Jo')]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([
            $this->officerRecord('Treasurer', 'Anonymous Jo'),
            $this->officerRecord('Secretary', ''),
        ]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('Treasurer', $html);
        $this->assertStringContainsString('Anonymous Jo', $html);
        // Officer position with no named holder still lists the role.
        $this->assertStringContainsString('Secretary', $html);
    }

    /** @test */
    public function a_meeting_with_no_officer_records_says_none(): void
    {
        $this->meetingRepository->method('findAll')->willReturn([$this->meeting(1, 'IG', '2026-01-10')]);
        $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
        $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

        $this->assertStringContainsString('None', $this->capture(fn () => $this->dashboard->renderDashboardWidget()));
    }

    // ── registration and styles ──────────────────────────────────────

    /** @test */
    public function the_widget_is_registered(): void
    {
        $this->dashboard->registerDashboardWidget();

        $this->assertArrayHasKey('intergroup_meetings_dashboard', WpState::$widgets);
    }

    /** @test */
    public function styles_load_only_on_the_dashboard(): void
    {
        $this->setScreen('dashboard', 'dashboard');
        $this->assertStringContainsString('<style>', $this->capture(fn () => $this->dashboard->addDashboardStyles()));

        $this->setScreen('edit-post', 'edit', 'post');
        $this->assertSame('', $this->capture(fn () => $this->dashboard->addDashboardStyles()));
    }
}
