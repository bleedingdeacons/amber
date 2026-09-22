<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\ReportsAdmin;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use DateTime;
use ReflectionMethod;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupView;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/*
 * Tests for the CSV reports admin page.
 *
 * The page builds two downloadable reports for a chosen meeting: one row per
 * position (with the holder's contact details and whether the position was
 * marked attending) and one per group (with the GSR's details and proxy info).
 * The actual download streams to php://output and ends in exit(), which cannot
 * run inside the test process, so the row-builders are exercised through
 * reflection while the download entry point is tested for its guards only — the
 * page/action gate, the permission wp_die, and the "no meeting" wp_die. What
 * matters in the rows: a vacant position and a GSR-less group still appear (one
 * row, blank member fields), a filled one produces a row per holder, and the
 * derived Duration / Started-Service / Attended fields are correct.
 */

covers(ReportsAdmin::class);

beforeEach(function () {
    $this->groupAttendance     = $this->createMock(IntergroupMeetingGroupAttendanceRepository::class);
    $this->officerAttendance   = $this->createMock(IntergroupMeetingOfficerAttendanceRepository::class);
    $this->positionRepository  = $this->createMock(PositionRepository::class);
    $this->positionViewFactory = $this->createMock(PositionViewFactory::class);
    $this->groupRepository     = $this->createMock(GroupRepository::class);
    $this->groupViewFactory    = $this->createMock(GroupViewFactory::class);

    $this->reports = new ReportsAdmin(
        $this->groupAttendance,
        $this->officerAttendance,
        $this->positionRepository,
        $this->positionViewFactory,
        $this->groupRepository,
        $this->groupViewFactory
    );

    /** @return mixed */
    $this->callPrivate = function (string $method, array $args = []) {
        return (new ReflectionMethod(ReportsAdmin::class, $method))->invokeArgs($this->reports, $args);
    };

    $this->positionView = function (int $id, string $title, array $members, int $termYears = 3, ?DateTime $rotation = null): PositionView {
        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn($id);
        $position->method('getLongName')->willReturn($title . ' Long');
        $position->method('getTermYears')->willReturn($termYears);

        $view = $this->createMock(PositionView::class);
        $view->method('getPosition')->willReturn($position);
        $view->method('getTitle')->willReturn($title);
        $view->method('getPositionEmail')->willReturn(strtolower($title) . '@example.test');
        $view->method('getRotationDate')->willReturn($rotation ?? new DateTime('2027-01-01'));
        $view->method('getMembers')->willReturn($members);

        return $view;
    };

    $this->member = function (string $name): Member {
        $member = $this->createMock(Member::class);
        $member->method('getAnonymousName')->willReturn($name);
        $member->method('getPersonalEmail')->willReturn(strtolower($name) . '@example.test');
        $member->method('getMobileNumber')->willReturn('0700 000 000');
        $member->method('isGSR')->willReturn(true);

        return $member;
    };
});

// ── page render ──────────────────────────────────────────────────
it('offers both downloads for the selected meeting', function () {
    $this->wpdb->col = ['March IG'];

    $html = $this->capture(fn () => $this->reports->renderPage());

    expect($html)->toContain('Positions Report')
        ->toContain('Groups Report')
        // Filenames sanitised from the label.
        ->toContain('position_March_IG.csv')
        ->toContain('group_March_IG.csv')
        // Download links are nonce-protected.
        ->toContain('amber_action=download_positions_csv');
});

it('reports when there are no meetings', function () {
    $this->wpdb->col = [];

    expect($this->capture(fn () => $this->reports->renderPage()))->toContain('No attendance records found');
});

// ── download guards ──────────────────────────────────────────────
it('ignores other admin pages in the download handler', function () {
    $_GET = [];

    // No exception, no output — it simply returns.
    expect($this->reports->maybeHandleDownload())->toBeNull();
});

it('ignores an unknown action in the download handler', function () {
    $_GET = ['page' => 'intergroup-reports', 'amber_action' => 'nonsense'];

    expect($this->reports->maybeHandleDownload())->toBeNull();
});

it('refuses a download without permission', function () {
    $_GET = ['page' => 'intergroup-reports', 'amber_action' => 'download_positions_csv'];
    $this->denyCapability();

    $this->reports->maybeHandleDownload();
})->throws(WpDieException::class);

it('refuses a download with no meeting selected', function () {
    $_GET = ['page' => 'intergroup-reports', 'amber_action' => 'download_positions_csv'];

    // Permission ok, nonce ok (stubbed), but no meeting_label → wp_die.
    $this->reports->maybeHandleDownload();
})->throws(WpDieException::class);

// ── position rows ────────────────────────────────────────────────
it('includes a position row per holder and a vacant row', function () {
    // Two positions in the repo, one filled and one vacant. Only the filled
    // one is marked attending.
    $filled = ($this->positionView)(1, 'Treasurer', [($this->member)('Anonymous Alex')]);
    $vacant = ($this->positionView)(2, 'Secretary', []);

    $p1 = $this->createMock(Position::class);
    $p1->method('getId')->willReturn(1);
    $p2 = $this->createMock(Position::class);
    $p2->method('getId')->willReturn(2);
    $this->positionRepository->method('findAll')->willReturn([$p1, $p2]);
    $this->positionViewFactory->method('createFrom')->willReturnMap([[1, $filled], [2, $vacant]]);

    $attRecord = $this->createMock(IntergroupMeetingOfficerAttendance::class);
    $attRecord->method('getOfficerId')->willReturn(1);
    $this->officerAttendance->method('findAll')->willReturn([$attRecord]);

    /** @var array<array<string>> $rows */
    $rows = ($this->callPrivate)('buildPositionRows', ['March IG']);

    expect($rows)->toHaveCount(2)
        // Alphabetical: Secretary before Treasurer.
        ->and($rows[0][0])->toBe('Secretary')
        ->and($rows[0][8])->toBe('No')        // vacant, not attended
        ->and($rows[0][3])->toBe('')          // blank member name
        ->and($rows[1][0])->toBe('Treasurer')
        ->and($rows[1][3])->toBe('Anonymous Alex')
        ->and($rows[1][8])->toBe('Yes')       // attended
        ->and($rows[1][6])->toBe('3 years')   // duration
        ->and($rows[1][7])->toBe('2024-01-01'); // rotation 2027 − 3y term
});

// ── group rows ───────────────────────────────────────────────────
it('includes a group row per gsr and a gsr less row', function () {
    $groupA = $this->createMock(Group::class);
    $groupA->method('getId')->willReturn(10);
    $groupA->method('getTitle')->willReturn('Alpha Group');
    $groupB = $this->createMock(Group::class);
    $groupB->method('getId')->willReturn(20);
    $groupB->method('getTitle')->willReturn('Beta Group');
    $this->groupRepository->method('findAll')->willReturn([$groupB, $groupA]);

    // Alpha has a GSR and an attendance record with a proxy; Beta has neither.
    $viewA = $this->createMock(GroupView::class);
    $viewA->method('getMembers')->willReturn([($this->member)('Anonymous Bob')]);
    $viewB = $this->createMock(GroupView::class);
    $viewB->method('getMembers')->willReturn([]);
    $this->groupViewFactory->method('createFrom')->willReturnMap([[10, $viewA], [20, $viewB]]);

    $record = $this->createMock(IntergroupMeetingGroupAttendance::class);
    $record->method('getGroupId')->willReturn(10);
    $record->method('isGsrProxy')->willReturn(true);
    $record->method('getGsrProxyName')->willReturn('Anonymous Proxy');
    $this->groupAttendance->method('findAll')->willReturn([$record]);

    /** @var array<array<string>> $rows */
    $rows = ($this->callPrivate)('buildGroupRows', ['March IG']);

    expect($rows)->toHaveCount(2)
        // Alphabetical: Alpha first.
        ->and($rows[0][0])->toBe('Alpha Group')
        ->and($rows[0][1])->toBe('Anonymous Bob')
        ->and($rows[0][4])->toBe('Yes')          // attended
        ->and($rows[0][5])->toBe('Yes')          // proxy attended
        ->and($rows[0][6])->toBe('Anonymous Proxy')
        // Beta: no GSR, not attended.
        ->and($rows[1][0])->toBe('Beta Group')
        ->and($rows[1][1])->toBe('')
        ->and($rows[1][4])->toBe('No');
});

// ── derived-field helpers ────────────────────────────────────────
it('pluralises the duration and leaves it empty for a zero term', function () {
    expect(($this->callPrivate)('formatDuration', [1]))->toBe('1 year')
        ->and(($this->callPrivate)('formatDuration', [4]))->toBe('4 years')
        ->and(($this->callPrivate)('formatDuration', [0]))->toBe('')
        ->and(($this->callPrivate)('formatDuration', [null]))->toBe('');
});

it('dates the start of service as the rotation less the term', function () {
    expect(($this->callPrivate)('formatStartedService', [new DateTime('2026-06-01'), 3]))->toBe('2023-06-01')
        // No rotation date or no term → blank.
        ->and(($this->callPrivate)('formatStartedService', [null, 3]))->toBe('')
        ->and(($this->callPrivate)('formatStartedService', [new DateTime('2026-06-01'), 0]))->toBe('');
});

// ── CSV writer ───────────────────────────────────────────────────
it('writes a csv row rfc 4180 style with doubled quotes', function () {
    $stream = fopen('php://temp', 'r+');
    (new ReflectionMethod(ReportsAdmin::class, 'writeCsvRow'))->invoke(null, $stream, ['plain', 'says "hi"']);
    rewind($stream);
    $line = stream_get_contents($stream);
    fclose($stream);

    expect($line)->toBe("plain,\"says \"\"hi\"\"\"\n");
});

// ── registration and styles ──────────────────────────────────────
it('registers the submenu page', function () {
    $this->reports->registerSubmenuPage();

    expect($this->registeredMenuSlugs())->toContain('intergroup-reports');
});

it('loads styles only on the reports page', function () {
    $this->setScreen('intergroup_page_intergroup-reports');
    expect($this->capture(fn () => $this->reports->addPageStyles()))->toContain('<style>');

    $this->setScreen('dashboard', 'dashboard');
    expect($this->capture(fn () => $this->reports->addPageStyles()))->toBe('');
});
