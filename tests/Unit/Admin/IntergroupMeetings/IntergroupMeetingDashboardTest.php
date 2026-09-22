<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupMeetingDashboard;
use BleedingDeacons\WpMocks\WpState;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/*
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
 */

covers(IntergroupMeetingDashboard::class);

beforeEach(function () {
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

    $this->meeting = function (int $id, string $title, string $date, int $groups = 0, int $officers = 0): IntergroupMeeting {
        $meeting = $this->createMock(IntergroupMeeting::class);
        $meeting->method('getId')->willReturn($id);
        $meeting->method('getTitle')->willReturn($title);
        $meeting->method('getDate')->willReturn($date);
        $meeting->method('getGroupAttendees')->willReturn(array_fill(0, $groups, 'g'));
        $meeting->method('getOfficersAttending')->willReturn(array_fill(0, $officers, 'o'));

        return $meeting;
    };

    $this->member = function (int $id, string $name): Member {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    };

    $this->groupRecord = function (string $group, string $gsrName): IntergroupMeetingGroupAttendance {
        $record = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $record->method('getMeetingGroup')->willReturn($group);
        $record->method('getGsrName')->willReturn($gsrName);

        return $record;
    };

    $this->officerRecord = function (string $position, string $officerName): IntergroupMeetingOfficerAttendance {
        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getPositionName')->willReturn($position);
        $record->method('getOfficerName')->willReturn($officerName);

        return $record;
    };
});

// ── empty state ──────────────────────────────────────────────────
it('says so when the archive is empty', function () {
    $this->meetingRepository->method('findAll')->willReturn([]);

    expect($this->capture(fn () => $this->dashboard->renderDashboardWidget()))
        ->toContain('No intergroup meetings found');
});

// ── header label shapes ──────────────────────────────────────────
it('orders meetings newest first with a title and date label', function () {
    $this->meetingRepository->method('findAll')->willReturn([
        ($this->meeting)(1, 'January IG', '2026-01-10'),
        ($this->meeting)(2, 'March IG', '2026-03-10'),
    ]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

    $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

    expect($html)->toContain('January IG')
        ->toContain('March IG');
    // Formatted and sorted so March (later) precedes January.
    expect(strpos($html, 'March'))->toBeLessThan(strpos($html, 'January'))
        ->and($html)->toContain('March 10, 2026');
});

it('flags a meeting with neither title nor date', function () {
    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1, '', '')]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

    expect($this->capture(fn () => $this->dashboard->renderDashboardWidget()))->toContain('No Title or Date');
});

it('totals groups and officers in the eligible badge', function () {
    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1, 'IG', '2026-01-10', 3, 2)]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

    $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

    expect($html)->toContain('3 groups, 2 officers')
        ->toContain('>5<');
});

// ── group attendees ──────────────────────────────────────────────
it('links known gsrs and shows unknown ones as text', function () {
    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1, 'IG', '2026-01-10')]);
    $this->memberRepository->method('findAll')->willReturn([($this->member)(7, 'Anonymous Alex')]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([
        ($this->groupRecord)('Tuesday Group', 'Anonymous Alex, Anonymous Sam'),
        ($this->groupRecord)('Solo Group', ''),
    ]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

    $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

    // Known GSR is linked; unknown one is plain; a group with no GSR just names the group.
    // &#038;, not &: WordPress encodes the separator in an href. The bare
    // & described output this dashboard has never produced, and passed
    // only while the test double returned its input untouched.
    expect($html)->toContain('<a href="https://example.test/wp-admin/post.php?post=7&#038;action=edit">Anonymous Alex</a>')
        ->toContain('Anonymous Sam')
        ->toContain('Solo Group');
});

it('dashes the groups cell for a meeting with no group records', function () {
    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1, 'IG', '2026-01-10')]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

    $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

    expect($html)->toContain('—');
});

// ── officers ─────────────────────────────────────────────────────
it('links known officers and falls back to the position alone', function () {
    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1, 'IG', '2026-01-10')]);
    $this->memberRepository->method('findAll')->willReturn([($this->member)(9, 'Anonymous Jo')]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([
        ($this->officerRecord)('Treasurer', 'Anonymous Jo'),
        ($this->officerRecord)('Secretary', ''),
    ]);

    $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

    expect($html)->toContain('Treasurer')
        ->toContain('Anonymous Jo')
        // Officer position with no named holder still lists the role.
        ->toContain('Secretary');
});

it('says none for a meeting with no officer records', function () {
    $this->meetingRepository->method('findAll')->willReturn([($this->meeting)(1, 'IG', '2026-01-10')]);
    $this->groupAttendance->method('findByIntergroupMeeting')->willReturn([]);
    $this->officerAttendance->method('findByIntergroupMeeting')->willReturn([]);

    expect($this->capture(fn () => $this->dashboard->renderDashboardWidget()))->toContain('None');
});

// ── registration and styles ──────────────────────────────────────
it('registers the widget', function () {
    $this->dashboard->registerDashboardWidget();

    expect(WpState::$widgets)->toHaveKey('intergroup_meetings_dashboard');
});

it('loads styles only on the dashboard', function () {
    $this->setScreen('dashboard', 'dashboard');
    expect($this->capture(fn () => $this->dashboard->addDashboardStyles()))->toContain('<style>');

    $this->setScreen('edit-post', 'edit', 'post');
    expect($this->capture(fn () => $this->dashboard->addDashboardStyles()))->toBe('');
});
