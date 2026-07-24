<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin;

use Amber\Admin\DeveloperDashboard;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpDieException;
use Amber\Tests\WpState;
use ReflectionMethod;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;

/**
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
 *
 * @covers \Amber\Admin\DeveloperDashboard
 */
class DeveloperDashboardTest extends AmberTestCase
{
    /** @var MemberRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $memberRepository;

    /** @var MemberRevisor&\PHPUnit\Framework\MockObject\MockObject */
    private $memberRevisor;

    private DeveloperDashboard $dashboard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->memberRepository = $this->createMock(MemberRepository::class);
        $this->memberRevisor    = $this->createMock(MemberRevisor::class);

        $this->dashboard = new DeveloperDashboard($this->memberRepository, $this->memberRevisor);
    }

    /** @return mixed */
    private function callPrivate(string $method, array $args = [])
    {
        return (new ReflectionMethod(DeveloperDashboard::class, $method))->invokeArgs($this->dashboard, $args);
    }

    private function member(bool $accepted = false, string $version = ''): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('isGdprAccepted')->willReturn($accepted);
        $member->method('getGdprAcceptedAt')->willReturn('');
        $member->method('getGdprAcceptanceVersion')->willReturn($version);
        $member->method('getGdprAcceptanceMethod')->willReturn('');
        $member->method('getGdprAcceptanceStatement')->willReturn('');

        return $member;
    }

    // ── submenu visibility ───────────────────────────────────────────

    /** @test */
    public function the_submenu_appears_only_outside_production(): void
    {
        // PRODUCTION defaults to true when undefined, hiding the page; the test
        // environment declares it false so the menu is registered.
        if (!defined('PRODUCTION')) {
            define('PRODUCTION', false);
        }

        $this->dashboard->registerSubmenuPage();

        $this->assertContains('developer', $this->registeredMenuSlugs());
    }

    // ── page access ──────────────────────────────────────────────────

    /** @test */
    public function the_page_is_refused_to_a_non_administrator(): void
    {
        WpState::$currentUserRoles = ['editor'];

        $this->expectException(WpDieException::class);
        $this->dashboard->renderPage();
    }

    /** @test */
    public function the_page_shows_both_maintenance_sections_with_live_counts(): void
    {
        $this->wpdb->var = 4;                       // each attendance table reports 4
        $this->memberRepository->method('count')->willReturn(10);
        $this->memberRepository->method('findAll')->willReturn([
            $this->member(true),
            $this->member(false),
        ]);

        $html = $this->capture(fn () => $this->dashboard->renderPage());

        $this->assertStringContainsString('Attendance Records', $html);
        $this->assertStringContainsString('Member GDPR Values', $html);
        $this->assertStringContainsString('Total members', $html);
        // Counts flow through: 4 group + 4 officer records, 1 of 2 members with GDPR data.
        $this->assertStringContainsString('>4</strong>', $html);
        $this->assertStringContainsString('>10</strong>', $html);
    }

    /** @test */
    public function the_buttons_are_disabled_when_there_is_nothing_to_delete(): void
    {
        $this->wpdb->var = 0;
        $this->memberRepository->method('count')->willReturn(0);
        $this->memberRepository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderPage());

        $this->assertStringContainsString('disabled', $html);
    }

    // ── notices ──────────────────────────────────────────────────────

    /** @test */
    public function the_delete_success_notice_reports_the_counts(): void
    {
        $this->wpdb->var = 0;
        $this->memberRepository->method('count')->willReturn(0);
        $this->memberRepository->method('findAll')->willReturn([]);
        $_GET = ['amber_action_done' => 'delete_attendance', 'group_deleted' => '3', 'officer_deleted' => '1'];

        $html = $this->capture(fn () => $this->dashboard->renderPage());

        $this->assertStringContainsString('Attendance records deleted', $html);
        $this->assertStringContainsString('3 group records', $html);
        $this->assertStringContainsString('1 officer record', $html);   // singular
    }

    /** @test */
    public function the_gdpr_success_notice_reports_the_counts(): void
    {
        $this->wpdb->var = 0;
        $this->memberRepository->method('count')->willReturn(0);
        $this->memberRepository->method('findAll')->willReturn([]);
        $_GET = ['amber_action_done' => 'clear_gdpr', 'members_cleared' => '2', 'members_total' => '5'];

        $html = $this->capture(fn () => $this->dashboard->renderPage());

        $this->assertStringContainsString('GDPR values cleared', $html);
        $this->assertStringContainsString('2 of 5 members updated', $html);
    }

    // ── action guards ────────────────────────────────────────────────

    /** @test */
    public function actions_are_ignored_off_the_developer_page(): void
    {
        $_GET = [];

        $this->dashboard->handleActions();
        $this->assertTrue(true);
    }

    /** @test */
    public function a_page_load_without_a_posted_action_does_nothing(): void
    {
        $_GET = ['page' => 'developer'];
        $_POST = [];

        $this->dashboard->handleActions();
        $this->assertTrue(true);
    }

    /** @test */
    public function an_action_from_a_non_administrator_is_refused(): void
    {
        $_GET = ['page' => 'developer'];
        $_POST = ['amber_developer_action' => 'delete_attendance'];
        WpState::$currentUserRoles = ['editor'];

        $this->expectException(WpDieException::class);
        $this->dashboard->handleActions();
    }

    // ── destructive workers (via reflection; the live path exits) ─────

    /** @test */
    public function deleting_attendance_issues_a_delete_against_each_table(): void
    {
        $this->wpdb->queryResult = 5;

        /** @var array{group:int, officer:int} $result */
        $result = $this->callPrivate('deleteAllAttendanceRecords');

        $this->assertSame(5, $result['group']);
        $this->assertSame(5, $result['officer']);
        $this->assertCount(2, $this->wpdb->queries);
        $this->assertStringContainsString('DELETE FROM', $this->wpdb->queries[0]);
    }

    /** @test */
    public function clearing_gdpr_only_revises_members_that_have_a_value_set(): void
    {
        $withGdpr    = $this->member(true);
        $withoutGdpr = $this->member(false);
        $this->memberRepository->method('findAll')->willReturn([$withGdpr, $withoutGdpr]);

        // Only the member with data is revised; the other is skipped.
        $this->memberRevisor->expects($this->once())->method('revise')->willReturn($this->member(false));
        $this->memberRepository->method('save')->willReturn(true);

        /** @var array{cleared:int, total:int} $result */
        $result = $this->callPrivate('clearAllGdprValues');

        $this->assertSame(1, $result['cleared']);
        $this->assertSame(2, $result['total']);
    }

    /** @test */
    public function a_member_is_counted_as_having_gdpr_data_when_any_field_is_set(): void
    {
        $this->assertTrue($this->callPrivate('memberHasGdprValues', [$this->member(false, '2.0')]));
        $this->assertFalse($this->callPrivate('memberHasGdprValues', [$this->member(false, '')]));
    }

    // ── styles ───────────────────────────────────────────────────────

    /** @test */
    public function styles_load_only_on_the_developer_page(): void
    {
        $this->setScreen('intergroup_page_developer');
        $this->assertStringContainsString('<style>', $this->capture(fn () => $this->dashboard->addPageStyles()));

        $this->setScreen('dashboard', 'dashboard');
        $this->assertSame('', $this->capture(fn () => $this->dashboard->addPageStyles()));
    }
}
