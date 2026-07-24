<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\ReportsAdmin;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpDieException;
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

/**
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
 *
 * @covers \Amber\Admin\IntergroupMeetings\ReportsAdmin
 */
class ReportsAdminTest extends AmberTestCase
{
    /** @var IntergroupMeetingGroupAttendanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $groupAttendance;

    /** @var IntergroupMeetingOfficerAttendanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $officerAttendance;

    /** @var PositionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $positionRepository;

    /** @var PositionViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $positionViewFactory;

    /** @var GroupRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $groupRepository;

    /** @var GroupViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $groupViewFactory;

    private ReportsAdmin $reports;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /** @return mixed */
    private function callPrivate(string $method, array $args = [])
    {
        return (new ReflectionMethod(ReportsAdmin::class, $method))->invokeArgs($this->reports, $args);
    }

    // ── page render ──────────────────────────────────────────────────

    /** @test */
    public function the_page_offers_both_downloads_for_the_selected_meeting(): void
    {
        $this->wpdb->col = ['March IG'];

        $html = $this->capture(fn () => $this->reports->renderPage());

        $this->assertStringContainsString('Positions Report', $html);
        $this->assertStringContainsString('Groups Report', $html);
        // Filenames sanitised from the label.
        $this->assertStringContainsString('position_March_IG.csv', $html);
        $this->assertStringContainsString('group_March_IG.csv', $html);
        // Download links are nonce-protected.
        $this->assertStringContainsString('amber_action=download_positions_csv', $html);
    }

    /** @test */
    public function the_page_reports_when_there_are_no_meetings(): void
    {
        $this->wpdb->col = [];

        $this->assertStringContainsString('No attendance records found', $this->capture(fn () => $this->reports->renderPage()));
    }

    // ── download guards ──────────────────────────────────────────────

    /** @test */
    public function the_download_handler_ignores_other_admin_pages(): void
    {
        $_GET = [];

        // No exception, no output — it simply returns.
        $this->reports->maybeHandleDownload();
        $this->assertTrue(true);
    }

    /** @test */
    public function the_download_handler_ignores_an_unknown_action(): void
    {
        $_GET = ['page' => 'intergroup-reports', 'amber_action' => 'nonsense'];

        $this->reports->maybeHandleDownload();
        $this->assertTrue(true);
    }

    /** @test */
    public function a_download_without_permission_is_refused(): void
    {
        $_GET = ['page' => 'intergroup-reports', 'amber_action' => 'download_positions_csv'];
        $this->denyCapability();

        $this->expectException(WpDieException::class);
        $this->reports->maybeHandleDownload();
    }

    /** @test */
    public function a_download_with_no_meeting_selected_is_refused(): void
    {
        $_GET = ['page' => 'intergroup-reports', 'amber_action' => 'download_positions_csv'];

        // Permission ok, nonce ok (stubbed), but no meeting_label → wp_die.
        $this->expectException(WpDieException::class);
        $this->reports->maybeHandleDownload();
    }

    // ── position rows ────────────────────────────────────────────────

    private function positionView(int $id, string $title, array $members, int $termYears = 3, ?DateTime $rotation = null): PositionView
    {
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
    }

    private function member(string $name): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getAnonymousName')->willReturn($name);
        $member->method('getPersonalEmail')->willReturn(strtolower($name) . '@example.test');
        $member->method('getMobileNumber')->willReturn('0700 000 000');
        $member->method('isGSR')->willReturn(true);

        return $member;
    }

    /** @test */
    public function the_position_rows_include_a_row_per_holder_and_a_vacant_row(): void
    {
        // Two positions in the repo, one filled and one vacant. Only the filled
        // one is marked attending.
        $filled = $this->positionView(1, 'Treasurer', [$this->member('Anonymous Alex')]);
        $vacant = $this->positionView(2, 'Secretary', []);

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
        $rows = $this->callPrivate('buildPositionRows', ['March IG']);

        $this->assertCount(2, $rows);
        // Alphabetical: Secretary before Treasurer.
        $this->assertSame('Secretary', $rows[0][0]);
        $this->assertSame('No', $rows[0][8]);        // vacant, not attended
        $this->assertSame('', $rows[0][3]);          // blank member name
        $this->assertSame('Treasurer', $rows[1][0]);
        $this->assertSame('Anonymous Alex', $rows[1][3]);
        $this->assertSame('Yes', $rows[1][8]);       // attended
        $this->assertSame('3 years', $rows[1][6]);   // duration
        $this->assertSame('2024-01-01', $rows[1][7]); // rotation 2027 − 3y term
    }

    // ── group rows ───────────────────────────────────────────────────

    /** @test */
    public function the_group_rows_include_a_row_per_gsr_and_a_gsr_less_row(): void
    {
        $groupA = $this->createMock(Group::class);
        $groupA->method('getId')->willReturn(10);
        $groupA->method('getTitle')->willReturn('Alpha Group');
        $groupB = $this->createMock(Group::class);
        $groupB->method('getId')->willReturn(20);
        $groupB->method('getTitle')->willReturn('Beta Group');
        $this->groupRepository->method('findAll')->willReturn([$groupB, $groupA]);

        // Alpha has a GSR and an attendance record with a proxy; Beta has neither.
        $viewA = $this->createMock(GroupView::class);
        $viewA->method('getMembers')->willReturn([$this->member('Anonymous Bob')]);
        $viewB = $this->createMock(GroupView::class);
        $viewB->method('getMembers')->willReturn([]);
        $this->groupViewFactory->method('createFrom')->willReturnMap([[10, $viewA], [20, $viewB]]);

        $record = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $record->method('getGroupId')->willReturn(10);
        $record->method('isGsrProxy')->willReturn(true);
        $record->method('getGsrProxyName')->willReturn('Anonymous Proxy');
        $this->groupAttendance->method('findAll')->willReturn([$record]);

        /** @var array<array<string>> $rows */
        $rows = $this->callPrivate('buildGroupRows', ['March IG']);

        $this->assertCount(2, $rows);
        // Alphabetical: Alpha first.
        $this->assertSame('Alpha Group', $rows[0][0]);
        $this->assertSame('Anonymous Bob', $rows[0][1]);
        $this->assertSame('Yes', $rows[0][4]);          // attended
        $this->assertSame('Yes', $rows[0][5]);          // proxy attended
        $this->assertSame('Anonymous Proxy', $rows[0][6]);
        // Beta: no GSR, not attended.
        $this->assertSame('Beta Group', $rows[1][0]);
        $this->assertSame('', $rows[1][1]);
        $this->assertSame('No', $rows[1][4]);
    }

    // ── derived-field helpers ────────────────────────────────────────

    /** @test */
    public function the_duration_is_pluralised_and_empty_for_a_zero_term(): void
    {
        $this->assertSame('1 year', $this->callPrivate('formatDuration', [1]));
        $this->assertSame('4 years', $this->callPrivate('formatDuration', [4]));
        $this->assertSame('', $this->callPrivate('formatDuration', [0]));
        $this->assertSame('', $this->callPrivate('formatDuration', [null]));
    }

    /** @test */
    public function the_started_service_date_is_the_rotation_less_the_term(): void
    {
        $this->assertSame(
            '2023-06-01',
            $this->callPrivate('formatStartedService', [new DateTime('2026-06-01'), 3])
        );
        // No rotation date or no term → blank.
        $this->assertSame('', $this->callPrivate('formatStartedService', [null, 3]));
        $this->assertSame('', $this->callPrivate('formatStartedService', [new DateTime('2026-06-01'), 0]));
    }

    // ── CSV writer ───────────────────────────────────────────────────

    /** @test */
    public function a_csv_row_is_written_rfc_4180_with_doubled_quotes(): void
    {
        $stream = fopen('php://temp', 'r+');
        (new ReflectionMethod(ReportsAdmin::class, 'writeCsvRow'))->invoke(null, $stream, ['plain', 'says "hi"']);
        rewind($stream);
        $line = stream_get_contents($stream);
        fclose($stream);

        $this->assertSame("plain,\"says \"\"hi\"\"\"\n", $line);
    }

    // ── registration and styles ──────────────────────────────────────

    /** @test */
    public function the_submenu_page_is_registered(): void
    {
        $this->reports->registerSubmenuPage();

        $this->assertContains('intergroup-reports', $this->registeredMenuSlugs());
    }

    /** @test */
    public function styles_load_only_on_the_reports_page(): void
    {
        $this->setScreen('intergroup_page_intergroup-reports');
        $this->assertStringContainsString('<style>', $this->capture(fn () => $this->reports->addPageStyles()));

        $this->setScreen('dashboard', 'dashboard');
        $this->assertSame('', $this->capture(fn () => $this->reports->addPageStyles()));
    }
}
