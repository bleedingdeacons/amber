<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupMeetingAttendanceDashboard;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;

/*
 * Tests for the Intergroup Meeting Attendance admin page.
 *
 * This page lets an officer pick a past meeting from a dropdown and see two
 * tables: group attendance (group, GSR, whether a proxy stood in, proxy name)
 * and officer attendance (position, officer names collapsed per position). The
 * list of selectable meetings comes from a UNION across the two attendance
 * tables, so the test drives that through the fake $wpdb. The details worth
 * pinning are the proxy Yes/No rendering, the em-dash fallbacks for a missing
 * proxy or position, and the singular/plural record counts — the summary line
 * an officer reads to sanity-check a register.
 */

covers(IntergroupMeetingAttendanceDashboard::class);

const ATTENDANCE_DASHBOARD_SCREEN = 'intergroup_page_intergroup-attendance';

beforeEach(function () {
    $this->groupAttendance   = $this->createMock(IntergroupMeetingGroupAttendanceRepository::class);
    $this->officerAttendance = $this->createMock(IntergroupMeetingOfficerAttendanceRepository::class);

    $this->page = new IntergroupMeetingAttendanceDashboard(
        $this->groupAttendance,
        $this->officerAttendance
    );

    $this->groupRecord = function (string $group, string $gsr, bool $proxy, string $proxyName): IntergroupMeetingGroupAttendance {
        $record = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $record->method('getMeetingGroup')->willReturn($group);
        $record->method('getGsrName')->willReturn($gsr);
        $record->method('isGsrProxy')->willReturn($proxy);
        $record->method('getGsrProxyName')->willReturn($proxyName);

        return $record;
    };

    $this->officerRecord = function (string $position, string $officer): IntergroupMeetingOfficerAttendance {
        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getPositionName')->willReturn($position);
        $record->method('getOfficerName')->willReturn($officer);

        return $record;
    };

    /** Make the meeting-label UNION query return the given labels. */
    $this->availableLabels = function (array $labels): void {
        $this->wpdb->col = $labels;
    };
});

// ── selector ─────────────────────────────────────────────────────
it('says so when there are no records', function () {
    ($this->availableLabels)([]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('No attendance records found');
});

it('lists meetings in the selector and marks the chosen one', function () {
    ($this->availableLabels)(['January IG', 'March IG']);
    $_GET['meeting_label'] = 'March IG';
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('<option value="January IG"')
        ->toContain('<option value="March IG" selected');
});

it('selects the first meeting by default', function () {
    ($this->availableLabels)(['January IG', 'March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    $html = $this->capture(fn () => $this->page->renderPage());

    // No ?meeting_label, so the newest (first in the ordered list) wins.
    expect($html)->toContain('<option value="January IG" selected');
});

// ── group attendance table ───────────────────────────────────────
it('renders group rows, proxies and a plural summary', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([
        ($this->groupRecord)('Tuesday Group', 'Anonymous Alex', false, ''),
        ($this->groupRecord)('Friday Group', 'Anonymous Sam', true, 'Anonymous Jo'),
    ]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('Group Attendance')
        ->toContain('Tuesday Group')
        ->toContain('ig-proxy-yes')
        ->toContain('ig-proxy-no')
        ->toContain('Anonymous Jo')        // proxy name
        ->toContain('2</strong> group records') // plural
        ->toContain('1</strong> proxy');
});

it('reports an empty group table', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    expect($this->capture(fn () => $this->page->renderPage()))->toContain('No group attendance records');
});

// ── officer attendance table ─────────────────────────────────────
it('collapses officer names by position and dashes a blank role', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([
        ($this->officerRecord)('Treasurer', 'Anonymous Alex'),
        ($this->officerRecord)('Treasurer', 'Anonymous Sam'),
        ($this->officerRecord)('', 'Anonymous Jo'),
    ]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('Officer Attendance')
        // Two Treasurer rows collapse into one comma-joined cell.
        ->toContain('Anonymous Alex, Anonymous Sam')
        // Blank position renders an em dash.
        ->toContain('ig-empty-cell')
        ->toContain('3</strong> officer records');
});

it('reports an empty officer table', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    expect($this->capture(fn () => $this->page->renderPage()))->toContain('No officer attendance records');
});

// ── registration and styles ──────────────────────────────────────
it('registers the submenu page under intergroup', function () {
    $this->page->registerSubmenuPage();

    expect($this->registeredMenuSlugs())->toContain('intergroup-attendance');
});

it('loads styles only on the attendance page', function () {
    $this->setScreen(ATTENDANCE_DASHBOARD_SCREEN);
    expect($this->capture(fn () => $this->page->addPageStyles()))->toContain('<style>');

    $this->setScreen('dashboard', 'dashboard');
    expect($this->capture(fn () => $this->page->addPageStyles()))->toBe('');
});
