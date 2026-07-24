<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Amber\Admin\Members\DirectoryDashboard;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/**
 * Tests for the Intergroup Directory dashboard widget.
 *
 * The widget surfaces members' personal email addresses (into data-email, for
 * the Copy-All button), so the load-bearing rule is the Scrutiny gate: without
 * the "view personal data" capability the widget, its styles and its scripts
 * must all be withheld — not merely blanked. Beyond that it renders two
 * foldable lists: GSRs with a home group, and filled positions with their
 * holders, each sorted by name. The empty-state copy for each section matters
 * because an intergroup with no GSRs is a real, common state.
 *
 * @covers \Amber\Admin\Members\DirectoryDashboard
 */
class DirectoryDashboardTest extends AmberTestCase
{
    private DirectoryDashboard $dashboard;

    /** @var MemberRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $memberRepository;

    /** @var GroupFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $groupFactory;

    /** @var PositionViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $viewFactory;

    /** @var PositionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $positionRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturn(['POST_TYPE' => 'intergroup-member']);

        $this->memberRepository   = $this->createMock(MemberRepository::class);
        $this->groupFactory       = $this->createMock(GroupFactory::class);
        $this->viewFactory        = $this->createMock(PositionViewFactory::class);
        $this->positionRepository = $this->createMock(PositionRepository::class);

        $this->dashboard = new DirectoryDashboard(
            $config,
            $this->memberRepository,
            $this->groupFactory,
            $this->viewFactory,
            $this->positionRepository,
            new PersonalDataPolicy()
        );
    }

    private function gsr(int $id, string $name, string $email = 'm@example.test', ?string $group = 'Tuesday Group'): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);
        $member->method('getPersonalEmail')->willReturn($email);
        $member->method('isGsr')->willReturn(true);
        $member->method('getHomeGroup')->willReturn($group === null ? null : 100 + $id);

        if ($group !== null) {
            $groupObj = $this->createMock(Group::class);
            $groupObj->method('getTitle')->willReturn($group);
            $this->groupFactory->method('createFromSource')->willReturn($groupObj);
        }

        return $member;
    }

    private function filledPosition(int $id, string $title, array $holderNames): PositionView
    {
        $members = [];
        foreach ($holderNames as $name) {
            $member = $this->createMock(Member::class);
            $member->method('getAnonymousName')->willReturn($name);
            $members[] = $member;
        }

        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn($id);

        $view = $this->createMock(PositionView::class);
        $view->method('getTitle')->willReturn($title);
        $view->method('getMembers')->willReturn($members);
        $view->method('isVacant')->willReturn(false);
        $view->method('getPositionEmail')->willReturn('pos@example.test');
        $view->method('getPosition')->willReturn($position);

        $this->positionRepository->method('findAll')->willReturn([$position]);
        $this->viewFactory->method('createFrom')->willReturn($view);

        return $view;
    }

    // ── capability gate ──────────────────────────────────────────────

    /** @test */
    public function the_widget_is_registered_for_a_user_who_may_view_personal_data(): void
    {
        $this->dashboard->registerDashboardWidget();

        $this->assertArrayHasKey('directory_dashboard', WpState::$widgets);
    }

    /** @test */
    public function the_widget_is_withheld_from_a_user_who_may_not(): void
    {
        // The widget leaks personal email into the DOM, so a user without the
        // capability must not get it at all — not an empty shell.
        $this->denyCapability();

        $this->dashboard->registerDashboardWidget();

        $this->assertArrayNotHasKey('directory_dashboard', WpState::$widgets);
    }

    /** @test */
    public function neither_styles_nor_scripts_load_without_the_capability(): void
    {
        $this->setScreen('dashboard', 'dashboard');
        $this->denyCapability();

        $this->assertSame('', $this->capture(fn () => $this->dashboard->addDashboardStyles()));
        $this->assertSame('', $this->capture(fn () => $this->dashboard->addDashboardScripts()));
    }

    // ── groups section ───────────────────────────────────────────────

    /** @test */
    public function the_groups_section_lists_gsrs_with_a_home_group_sorted_by_name(): void
    {
        $this->memberRepository->method('findAll')->willReturn([
            $this->gsr(1, 'Zoe'),
            $this->gsr(2, 'Alex'),
        ]);
        $this->positionRepository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('Tuesday Group', $html);
        $this->assertLessThan(strpos($html, 'Zoe'), strpos($html, 'Alex'));
        // Personal email is carried in the data attribute for Copy-All.
        $this->assertStringContainsString('data-email="m@example.test"', $html);
    }

    /** @test */
    public function a_member_who_is_not_a_gsr_is_excluded(): void
    {
        $nonGsr = $this->createMock(Member::class);
        $nonGsr->method('isGsr')->willReturn(false);
        $nonGsr->method('getHomeGroup')->willReturn(5);

        $this->memberRepository->method('findAll')->willReturn([$nonGsr]);
        $this->positionRepository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('No members are currently marked as GSR', $html);
    }

    // ── positions section ────────────────────────────────────────────

    /** @test */
    public function the_positions_section_lists_filled_positions_with_holders(): void
    {
        $this->memberRepository->method('findAll')->willReturn([]);
        $this->filledPosition(7, 'Treasurer', ['Anonymous Alex', 'Anonymous Sam']);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('Treasurer', $html);
        $this->assertStringContainsString('Anonymous Alex, Anonymous Sam', $html);
    }

    /** @test */
    public function a_vacant_position_is_left_out_of_the_positions_section(): void
    {
        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn(7);

        $view = $this->createMock(PositionView::class);
        $view->method('isVacant')->willReturn(true);
        $view->method('getMembers')->willReturn([]);

        $this->memberRepository->method('findAll')->willReturn([]);
        $this->positionRepository->method('findAll')->willReturn([$position]);
        $this->viewFactory->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        $this->assertStringContainsString('No filled positions found', $html);
    }

    // ── styles and scripts ───────────────────────────────────────────

    /** @test */
    public function styles_and_scripts_are_emitted_on_the_dashboard(): void
    {
        $this->setScreen('dashboard', 'dashboard');

        $this->assertStringContainsString('<style>', $this->capture(fn () => $this->dashboard->addDashboardStyles()));
        $this->assertStringContainsString('<script>', $this->capture(fn () => $this->dashboard->addDashboardScripts()));
    }

    /** @test */
    public function styles_and_scripts_stay_off_other_admin_screens(): void
    {
        $this->setScreen('edit-post', 'edit', 'post');

        $this->assertSame('', $this->capture(fn () => $this->dashboard->addDashboardStyles()));
        $this->assertSame('', $this->capture(fn () => $this->dashboard->addDashboardScripts()));
    }
}
