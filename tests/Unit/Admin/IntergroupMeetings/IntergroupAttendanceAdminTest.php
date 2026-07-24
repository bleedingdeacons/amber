<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\IntergroupMeetings;

use Amber\Admin\IntergroupMeetings\IntergroupAttendanceAdmin;
use Amber\Tests\AmberTestCase;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendance;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;

/**
 * Tests for the (older) Meeting Attendance admin page.
 *
 * This is the sibling of {@see IntergroupMeetingAttendanceDashboard}: the same
 * two-table view of group and officer attendance for a chosen meeting, but it
 * sources its meeting list from a single attendance table rather than a UNION.
 * The behaviour worth pinning is the same — proxy Yes/No, em-dash fallbacks for
 * a missing proxy or blank position, the default-to-first-meeting selector, and
 * the singular/plural record counts.
 *
 * @covers \Amber\Admin\IntergroupMeetings\IntergroupAttendanceAdmin
 */
class IntergroupAttendanceAdminTest extends AmberTestCase
{
    private const PAGE_SCREEN = 'intergroup_page_intergroup-attendance';

    /** @var IntergroupMeetingGroupAttendanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $groupAttendance;

    /** @var IntergroupMeetingOfficerAttendanceRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $officerAttendance;

    private IntergroupAttendanceAdmin $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->groupAttendance   = $this->createMock(IntergroupMeetingGroupAttendanceRepository::class);
        $this->officerAttendance = $this->createMock(IntergroupMeetingOfficerAttendanceRepository::class);

        $this->page = new IntergroupAttendanceAdmin(
            $this->groupAttendance,
            $this->officerAttendance
        );
    }

    private function groupRecord(string $group, string $gsr, bool $proxy, string $proxyName): IntergroupMeetingGroupAttendance
    {
        $record = $this->createMock(IntergroupMeetingGroupAttendance::class);
        $record->method('getMeetingGroup')->willReturn($group);
        $record->method('getGsrName')->willReturn($gsr);
        $record->method('isGsrProxy')->willReturn($proxy);
        $record->method('getGsrProxyName')->willReturn($proxyName);

        return $record;
    }

    private function officerRecord(string $position, string $officer): IntergroupMeetingOfficerAttendance
    {
        $record = $this->createMock(IntergroupMeetingOfficerAttendance::class);
        $record->method('getPositionName')->willReturn($position);
        $record->method('getOfficerName')->willReturn($officer);

        return $record;
    }

    private function availableLabels(array $labels): void
    {
        $this->wpdb->col = $labels;
    }

    // ── selector ─────────────────────────────────────────────────────

    /** @test */
    public function with_no_records_the_page_says_so(): void
    {
        $this->availableLabels([]);

        $this->assertStringContainsString('No attendance records found', $this->capture(fn () => $this->page->renderPage()));
    }

    /** @test */
    public function the_first_meeting_is_selected_by_default(): void
    {
        $this->availableLabels(['January IG', 'March IG']);
        $this->groupAttendance->method('findAll')->willReturn([]);
        $this->officerAttendance->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->page->renderPage());

        $this->assertStringContainsString('Meeting Attendance', $html);
        $this->assertStringContainsString('<option value="January IG" selected', $html);
    }

    /** @test */
    public function a_chosen_meeting_from_the_query_string_is_marked_selected(): void
    {
        $this->availableLabels(['January IG', 'March IG']);
        $_GET['meeting_label'] = 'March IG';
        $this->groupAttendance->method('findAll')->willReturn([]);
        $this->officerAttendance->method('findAll')->willReturn([]);

        $this->assertStringContainsString('<option value="March IG" selected', $this->capture(fn () => $this->page->renderPage()));
    }

    // ── tables ───────────────────────────────────────────────────────

    /** @test */
    public function the_group_table_renders_rows_proxies_and_a_plural_summary(): void
    {
        $this->availableLabels(['March IG']);
        $this->groupAttendance->method('findAll')->willReturn([
            $this->groupRecord('Tuesday Group', 'Anonymous Alex', false, ''),
            $this->groupRecord('Friday Group', 'Anonymous Sam', true, 'Anonymous Jo'),
        ]);
        $this->officerAttendance->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->page->renderPage());

        $this->assertStringContainsString('Tuesday Group', $html);
        $this->assertStringContainsString('ig-proxy-yes', $html);
        $this->assertStringContainsString('ig-proxy-no', $html);
        $this->assertStringContainsString('Anonymous Jo', $html);
        $this->assertStringContainsString('2</strong> group records', $html);
        $this->assertStringContainsString('1</strong> proxy', $html);
    }

    /** @test */
    public function an_empty_group_table_is_reported(): void
    {
        $this->availableLabels(['March IG']);
        $this->groupAttendance->method('findAll')->willReturn([]);
        $this->officerAttendance->method('findAll')->willReturn([]);

        $this->assertStringContainsString('No group attendance records', $this->capture(fn () => $this->page->renderPage()));
    }

    /** @test */
    public function the_officer_table_collapses_names_by_position_and_dashes_a_blank_role(): void
    {
        $this->availableLabels(['March IG']);
        $this->groupAttendance->method('findAll')->willReturn([]);
        $this->officerAttendance->method('findAll')->willReturn([
            $this->officerRecord('Treasurer', 'Anonymous Alex'),
            $this->officerRecord('Treasurer', 'Anonymous Sam'),
            $this->officerRecord('', 'Anonymous Jo'),
        ]);

        $html = $this->capture(fn () => $this->page->renderPage());

        $this->assertStringContainsString('Anonymous Alex, Anonymous Sam', $html);
        $this->assertStringContainsString('ig-empty-cell', $html);
        $this->assertStringContainsString('3</strong> officer records', $html);
    }

    /** @test */
    public function an_empty_officer_table_is_reported(): void
    {
        $this->availableLabels(['March IG']);
        $this->groupAttendance->method('findAll')->willReturn([]);
        $this->officerAttendance->method('findAll')->willReturn([]);

        $this->assertStringContainsString('No officer attendance records', $this->capture(fn () => $this->page->renderPage()));
    }

    // ── registration and styles ──────────────────────────────────────

    /** @test */
    public function the_submenu_page_is_registered_under_intergroup(): void
    {
        $this->page->registerSubmenuPage();

        $this->assertContains('intergroup-attendance', $this->registeredMenuSlugs());
    }

    /** @test */
    public function styles_load_only_on_the_attendance_page(): void
    {
        $this->setScreen(self::PAGE_SCREEN);
        $this->assertStringContainsString('<style>', $this->capture(fn () => $this->page->addPageStyles()));

        $this->setScreen('dashboard', 'dashboard');
        $this->assertSame('', $this->capture(fn () => $this->page->addPageStyles()));
    }
}
