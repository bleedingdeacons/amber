<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Positions;

use Amber\Admin\Positions\PositionDashboard;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/**
 * Tests for the Positions & Members dashboard widget.
 *
 * This is the at-a-glance screen an intergroup officer sees on login: one
 * card per position, showing who holds it and when they rotate. The cases
 * that matter are the ones that look like data problems — a vacant post, a
 * position with no title, an Archivist (permanent tenure, so no "current
 * member" row) — because a widget that renders a blank card or a PHP notice
 * on the dashboard is the most visible failure Amber can have.
 *
 * @covers \Amber\Admin\Positions\PositionDashboard
 */
class PositionDashboardTest extends AmberTestCase
{
    private PositionDashboard $dashboard;

    /** @var PositionViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $viewFactory;

    /** @var PositionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturn(['POST_TYPE' => 'intergroup-member']);

        $this->viewFactory = $this->createMock(PositionViewFactory::class);
        $this->repository = $this->createMock(PositionRepository::class);

        $this->dashboard = new PositionDashboard($config, $this->viewFactory, $this->repository);
    }

    private function position(int $id = 7): Position
    {
        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn($id);

        return $position;
    }

    private function member(int $id, string $name): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    }

    private function view(array $overrides = []): PositionView
    {
        $defaults = [
            'getTitle' => 'Treasurer',
            'isVacant' => false,
            'getMembers' => [$this->member(1, 'Anonymous Alex')],
            'getPositionEmail' => 'treasurer@example.test',
            'getDescription' => 'Treasurer',
            'getRotationDate' => new DateTime('2027-03-01'),
            'getMonthsUntilRotation' => 12,
        ];

        $view = $this->createMock(PositionView::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $view->method($method)->willReturn($value);
        }

        return $view;
    }

    /** Render the widget over the given views. */
    private function renderWith(PositionView ...$views): string
    {
        $this->repository->method('findAll')->willReturn(
            array_map(fn (int $i): Position => $this->position($i), range(1, max(count($views), 1)))
        );
        $this->viewFactory->method('createFrom')->willReturnOnConsecutiveCalls(...$views ?: [null]);

        return $this->capture(fn () => $this->dashboard->renderDashboardWidget());
    }

    // ── registration ─────────────────────────────────────────────────

    /** @test */
    public function it_registers_the_dashboard_widget_hooks(): void
    {
        $this->assertNotEmpty($this->hooksFor('wp_dashboard_setup'));
        $this->assertNotEmpty($this->hooksFor('admin_head'));
    }

    /** @test */
    public function the_widget_is_registered_on_the_dashboard(): void
    {
        $this->dashboard->registerDashboardWidget();

        $this->assertArrayHasKey('position_members_dashboard', WpState::$widgets);
        $this->assertSame('Positions & Members', WpState::$widgets['position_members_dashboard']['name']);
    }

    /** @test */
    public function the_widget_styles_are_emitted_on_the_dashboard(): void
    {
        $this->setScreen('dashboard', 'dashboard');

        $css = $this->capture(fn () => $this->dashboard->addDashboardStyles());

        $this->assertStringContainsString('<style>', $css);
    }

    /** @test */
    public function the_widget_styles_are_not_emitted_on_other_screens(): void
    {
        // The widget only appears on the dashboard, so its CSS has no
        // business loading on every admin page.
        $this->setScreen('edit-post', 'edit', 'post');

        $this->assertSame('', $this->capture(fn () => $this->dashboard->addDashboardStyles()));
    }

    // ── rendering ────────────────────────────────────────────────────

    /** @test */
    public function a_site_with_no_positions_says_so_rather_than_rendering_nothing(): void
    {
        $this->repository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('No positions found', $html);
    }

    /** @test */
    public function a_position_is_rendered_as_a_card_with_its_holder(): void
    {
        $html = $this->renderWith($this->view());

        $this->assertStringContainsString('position-card', $html);
        $this->assertStringContainsString('Treasurer', $html);
        $this->assertStringContainsString('Anonymous Alex', $html);
        $this->assertStringContainsString('treasurer@example.test', $html);
    }

    /** @test */
    public function a_vacant_position_is_marked_vacant(): void
    {
        $html = $this->renderWith($this->view(['isVacant' => true, 'getMembers' => []]));

        $this->assertStringContainsString('Vacant', $html);
    }

    /** @test */
    public function a_position_with_no_title_falls_back_to_a_placeholder(): void
    {
        // Better a labelled card than an anonymous empty one.
        $html = $this->renderWith($this->view(['getTitle' => '']));

        $this->assertStringContainsString('Untitled Position', $html);
    }

    /** @test */
    public function the_archivist_card_omits_the_current_member_row(): void
    {
        // Archivist is a permanent tenure, so "current member" and rotation
        // are not meaningful for it.
        $html = $this->renderWith($this->view(['getDescription' => 'Archivist']));

        $this->assertStringNotContainsString('Current Member', $html);
    }

    /** @test */
    public function a_non_archivist_card_shows_the_current_member_row(): void
    {
        $html = $this->renderWith($this->view());

        $this->assertStringContainsString('Current Member', $html);
    }

    /** @test */
    public function a_job_share_lists_every_holder(): void
    {
        $html = $this->renderWith($this->view([
            'getMembers' => [$this->member(1, 'Anonymous Alex'), $this->member(2, 'Anonymous Sam')],
        ]));

        $this->assertStringContainsString('Anonymous Alex', $html);
        $this->assertStringContainsString('Anonymous Sam', $html);
    }

    /** @test */
    public function positions_are_ordered_by_title(): void
    {
        $html = $this->renderWith(
            $this->view(['getTitle' => 'Treasurer']),
            $this->view(['getTitle' => 'Chair'])
        );

        // Sorted case-insensitively, so Chair precedes Treasurer regardless
        // of the order the repository returned them in.
        $this->assertLessThan(strpos($html, 'Treasurer'), strpos($html, 'Chair'));
    }

    /** @test */
    public function positions_without_a_view_are_skipped(): void
    {
        $this->repository->method('findAll')->willReturn([$this->position(1), $this->position(2)]);
        $this->viewFactory->method('createFrom')->willReturnOnConsecutiveCalls(null, $this->view());

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertSame(1, substr_count($html, 'position-card-header'));
    }

    /** @test */
    public function a_position_with_no_email_still_renders(): void
    {
        $html = $this->renderWith($this->view(['getPositionEmail' => '']));

        $this->assertStringContainsString('Position Email', $html);
    }

    /** @test */
    public function a_position_with_no_rotation_date_still_renders(): void
    {
        $html = $this->renderWith($this->view(['getRotationDate' => null]));

        $this->assertStringContainsString('position-card', $html);
    }

    // ── status badge ─────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider statusBadgeProvider
     */
    public function the_status_badge_reflects_the_rotation_state(?int $months, string $expected): void
    {
        $html = $this->renderWith($this->view([
            'getMonthsUntilRotation' => $months,
            'getDaysUntilRotation'   => $months === null ? null : $months * 30,
        ]));

        $this->assertStringContainsString($expected, $html);
    }

    /** @return array<string, array{0: int|null, 1: string}> */
    public static function statusBadgeProvider(): array
    {
        return [
            'unknown when months null' => [null, 'status-unknown'],
            'overdue when negative'    => [-2, 'status-overdue'],
            'due at zero'              => [0, 'status-due'],
            'soon within three'       => [2, 'status-soon'],
            'filled beyond three'     => [12, 'status-normal'],
        ];
    }

    /** @test */
    public function an_overdue_member_cell_shows_how_many_months_overdue(): void
    {
        $html = $this->renderWith($this->view([
            'getMonthsUntilRotation' => -3,
            'getDaysUntilRotation'   => -90,
        ]));

        $this->assertStringContainsString('Overdue 3 months', $html);
    }
}
