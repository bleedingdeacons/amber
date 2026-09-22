<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupAttendanceAdmin;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;

/*
 * Tests for the (older) Meeting Attendance admin page.
 *
 * This is the sibling of {@see IntergroupMeetingAttendanceDashboard}: the same
 * two-table view of group and officer attendance for a chosen meeting, but it
 * sources its meeting list from a single attendance table rather than a UNION.
 * The behaviour worth pinning is the same — proxy Yes/No, em-dash fallbacks for
 * a missing proxy or blank position, the default-to-first-meeting selector, and
 * the singular/plural record counts.
 */

covers(IntergroupAttendanceAdmin::class);

const ATTENDANCE_ADMIN_SCREEN = 'intergroup_page_intergroup-attendance';

beforeEach(function () {
    $this->groupAttendance   = $this->createMock(IntergroupMeetingGroupAttendanceRepository::class);
    $this->officerAttendance = $this->createMock(IntergroupMeetingOfficerAttendanceRepository::class);

    $this->page = new IntergroupAttendanceAdmin(
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

    $this->availableLabels = function (array $labels): void {
        $this->wpdb->col = $labels;
    };
});

// ── selector ─────────────────────────────────────────────────────
it('says so when there are no records', function () {
    ($this->availableLabels)([]);

    expect($this->capture(fn () => $this->page->renderPage()))->toContain('No attendance records found');
});

it('selects the first meeting by default', function () {
    ($this->availableLabels)(['January IG', 'March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('Meeting Attendance')
        ->toContain('<option value="January IG" selected');
});

it('marks a meeting chosen in the query string as selected', function () {
    ($this->availableLabels)(['January IG', 'March IG']);
    $_GET['meeting_label'] = 'March IG';
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    expect($this->capture(fn () => $this->page->renderPage()))->toContain('<option value="March IG" selected');
});

// ── tables ───────────────────────────────────────────────────────
it('renders group rows, proxies and a plural summary', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([
        ($this->groupRecord)('Tuesday Group', 'Anonymous Alex', false, ''),
        ($this->groupRecord)('Friday Group', 'Anonymous Sam', true, 'Anonymous Jo'),
    ]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('Tuesday Group')
        ->toContain('ig-proxy-yes')
        ->toContain('ig-proxy-no')
        ->toContain('Anonymous Jo')
        ->toContain('2</strong> group records')
        ->toContain('1</strong> proxy');
});

it('reports an empty group table', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([]);

    expect($this->capture(fn () => $this->page->renderPage()))->toContain('No group attendance records');
});

it('collapses officer names by position and dashes a blank role', function () {
    ($this->availableLabels)(['March IG']);
    $this->groupAttendance->method('findAll')->willReturn([]);
    $this->officerAttendance->method('findAll')->willReturn([
        ($this->officerRecord)('Treasurer', 'Anonymous Alex'),
        ($this->officerRecord)('Treasurer', 'Anonymous Sam'),
        ($this->officerRecord)('', 'Anonymous Jo'),
    ]);

    $html = $this->capture(fn () => $this->page->renderPage());

    expect($html)->toContain('Anonymous Alex, Anonymous Sam')
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
    $this->setScreen(ATTENDANCE_ADMIN_SCREEN);
    expect($this->capture(fn () => $this->page->addPageStyles()))->toContain('<style>');

    $this->setScreen('dashboard', 'dashboard');
    expect($this->capture(fn () => $this->page->addPageStyles()))->toBe('');
});
