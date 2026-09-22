<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin;

use Amber\Admin\DeveloperDashboard;
use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use ReflectionMethod;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;

/*
 * Tests for the Developer maintenance page.
 *
 * This page carries two destructive, admin-only utilities: wipe every
 * attendance record, and clear the GDPR block on every member. The guarding is
 * the point — the submenu is hidden unless PRODUCTION is explicitly false, the
 * page itself and every action re-check the administrator role, and the forms
 * post through a nonce. The success handlers redirect-and-exit, which can't run
 * in-process, so the destructive workers are driven through reflection: the
 * attendance wipe issues two DELETEs, and the GDPR clear only revises members
 * that actually have a value set, routing through the repository so the audit
 * trail fires.
 */

covers(DeveloperDashboard::class);

beforeEach(function () {
    $this->memberRepository = $this->createMock(MemberRepository::class);
    $this->memberRevisor    = $this->createMock(MemberRevisor::class);

    $this->dashboard = new DeveloperDashboard($this->memberRepository, $this->memberRevisor);

    /** @return mixed */
    $this->callPrivate = function (string $method, array $args = []) {
        return (new ReflectionMethod(DeveloperDashboard::class, $method))->invokeArgs($this->dashboard, $args);
    };

    $this->member = function (bool $accepted = false, string $version = ''): Member {
        $member = $this->createMock(Member::class);
        $member->method('isGdprAccepted')->willReturn($accepted);
        $member->method('getGdprAcceptedAt')->willReturn('');
        $member->method('getGdprAcceptanceVersion')->willReturn($version);
        $member->method('getGdprAcceptanceMethod')->willReturn('');
        $member->method('getGdprAcceptanceStatement')->willReturn('');

        return $member;
    };
});

// ── submenu visibility ───────────────────────────────────────────
it('shows the submenu only outside production', function () {
    // PRODUCTION defaults to true when undefined, hiding the page; the test
    // environment declares it false so the menu is registered.
    if (!defined('PRODUCTION')) {
        define('PRODUCTION', false);
    }

    $this->dashboard->registerSubmenuPage();

    expect($this->registeredMenuSlugs())->toContain('developer');
});

// ── page access ──────────────────────────────────────────────────
it('refuses the page to a non administrator', function () {
    WpState::$currentUserRoles = ['editor'];

    $this->dashboard->renderPage();
})->throws(WpDieException::class);

it('shows both maintenance sections with live counts', function () {
    $this->wpdb->var = 4;                       // each attendance table reports 4
    $this->memberRepository->method('count')->willReturn(10);
    $this->memberRepository->method('findAll')->willReturn([
        ($this->member)(true),
        ($this->member)(false),
    ]);

    $html = $this->capture(fn () => $this->dashboard->renderPage());

    expect($html)->toContain('Attendance Records')
        ->toContain('Member GDPR Values')
        ->toContain('Total members')
        // Counts flow through: 4 group + 4 officer records, 1 of 2 members with GDPR data.
        ->toContain('>4</strong>')
        ->toContain('>10</strong>');
});

it('disables the buttons when there is nothing to delete', function () {
    $this->wpdb->var = 0;
    $this->memberRepository->method('count')->willReturn(0);
    $this->memberRepository->method('findAll')->willReturn([]);

    $html = $this->capture(fn () => $this->dashboard->renderPage());

    expect($html)->toContain('disabled');
});

// ── notices ──────────────────────────────────────────────────────
it('reports the counts in the delete success notice', function () {
    $this->wpdb->var = 0;
    $this->memberRepository->method('count')->willReturn(0);
    $this->memberRepository->method('findAll')->willReturn([]);
    $_GET = ['amber_action_done' => 'delete_attendance', 'group_deleted' => '3', 'officer_deleted' => '1'];

    $html = $this->capture(fn () => $this->dashboard->renderPage());

    expect($html)->toContain('Attendance records deleted')
        ->toContain('3 group records')
        ->toContain('1 officer record');   // singular
});

it('reports the counts in the gdpr success notice', function () {
    $this->wpdb->var = 0;
    $this->memberRepository->method('count')->willReturn(0);
    $this->memberRepository->method('findAll')->willReturn([]);
    $_GET = ['amber_action_done' => 'clear_gdpr', 'members_cleared' => '2', 'members_total' => '5'];

    $html = $this->capture(fn () => $this->dashboard->renderPage());

    expect($html)->toContain('GDPR values cleared')
        ->toContain('2 of 5 members updated');
});

// ── action guards ────────────────────────────────────────────────
it('ignores actions off the developer page', function () {
    $_GET = [];

    expect($this->dashboard->handleActions())->toBeNull();
});

it('does nothing on a page load without a posted action', function () {
    $_GET = ['page' => 'developer'];
    $_POST = [];

    expect($this->dashboard->handleActions())->toBeNull();
});

it('refuses an action from a non administrator', function () {
    $_GET = ['page' => 'developer'];
    $_POST = ['amber_developer_action' => 'delete_attendance'];
    WpState::$currentUserRoles = ['editor'];

    $this->dashboard->handleActions();
})->throws(WpDieException::class);

// ── destructive workers (via reflection; the live path exits) ─────
it('issues a delete against each table when deleting attendance', function () {
    $this->wpdb->queryResult = 5;

    /** @var array{group:int, officer:int} $result */
    $result = ($this->callPrivate)('deleteAllAttendanceRecords');

    expect($result['group'])->toBe(5)
        ->and($result['officer'])->toBe(5)
        ->and($this->wpdb->queries)->toHaveCount(2)
        ->and($this->wpdb->queries[0])->toContain('DELETE FROM');
});

it('only revises members that have a gdpr value set when clearing', function () {
    $withGdpr    = ($this->member)(true);
    $withoutGdpr = ($this->member)(false);
    $this->memberRepository->method('findAll')->willReturn([$withGdpr, $withoutGdpr]);

    // Only the member with data is revised; the other is skipped.
    $this->memberRevisor->expects($this->once())->method('revise')->willReturn(($this->member)(false));
    $this->memberRepository->method('save')->willReturn(true);

    /** @var array{cleared:int, total:int} $result */
    $result = ($this->callPrivate)('clearAllGdprValues');

    expect($result['cleared'])->toBe(1)
        ->and($result['total'])->toBe(2);
});

it('counts a member as having gdpr data when any field is set', function () {
    expect(($this->callPrivate)('memberHasGdprValues', [($this->member)(false, '2.0')]))->toBeTrue()
        ->and(($this->callPrivate)('memberHasGdprValues', [($this->member)(false, '')]))->toBeFalse();
});

// ── styles ───────────────────────────────────────────────────────
it('loads styles only on the developer page', function () {
    $this->setScreen('intergroup_page_developer');
    expect($this->capture(fn () => $this->dashboard->addPageStyles()))->toContain('<style>');

    $this->setScreen('dashboard', 'dashboard');
    expect($this->capture(fn () => $this->dashboard->addPageStyles()))->toBe('');
});
