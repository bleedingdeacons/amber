<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Amber\Admin\Members\DirectoryDashboard;
use BleedingDeacons\WpMocks\WpState;
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

/*
 * Tests for the Intergroup Directory dashboard widget.
 *
 * The widget surfaces members' personal email addresses (into data-email, for
 * the Copy-All button), so the load-bearing rule is the Scrutiny gate: without
 * the "view personal data" capability the widget, its styles and its scripts
 * must all be withheld — not merely blanked. Beyond that it renders two
 * foldable lists: GSRs with a home group, and filled positions with their
 * holders, each sorted by name. The empty-state copy for each section matters
 * because an intergroup with no GSRs is a real, common state.
 */

covers(DirectoryDashboard::class);

beforeEach(function () {
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

    $this->gsr = function (int $id, string $name, string $email = 'm@example.test', ?string $group = 'Tuesday Group'): Member {
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
    };

    $this->filledPosition = function (int $id, string $title, array $holderNames): PositionView {
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
    };
});

// ── capability gate ──────────────────────────────────────────────
describe('capability gate', function () {
    it('registers the widget for a user who may view personal data', function () {
        $this->dashboard->registerDashboardWidget();

        expect(WpState::$widgets)->toHaveKey('directory_dashboard');
    });

    it('withholds the widget from a user who may not', function () {
        // The widget leaks personal email into the DOM, so a user without the
        // capability must not get it at all — not an empty shell.
        $this->denyCapability();

        $this->dashboard->registerDashboardWidget();

        expect(WpState::$widgets)->not->toHaveKey('directory_dashboard');
    });

    it('loads neither styles nor scripts without the capability', function () {
        $this->setScreen('dashboard', 'dashboard');
        $this->denyCapability();

        expect($this->capture(fn () => $this->dashboard->addDashboardStyles()))->toBe('')
            ->and($this->capture(fn () => $this->dashboard->addDashboardScripts()))->toBe('');
    });
});

// ── groups section ───────────────────────────────────────────────
describe('groups section', function () {
    it('lists gsrs with a home group sorted by name', function () {
        $this->memberRepository->method('findAll')->willReturn([
            ($this->gsr)(1, 'Zoe'),
            ($this->gsr)(2, 'Alex'),
        ]);
        $this->positionRepository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        expect($html)->toContain('Tuesday Group')
            ->and(strpos($html, 'Alex'))->toBeLessThan(strpos($html, 'Zoe'))
            // Personal email is carried in the data attribute for Copy-All.
            ->and($html)->toContain('data-email="m@example.test"');
    });

    it('excludes a member who is not a gsr', function () {
        $nonGsr = $this->createMock(Member::class);
        $nonGsr->method('isGsr')->willReturn(false);
        $nonGsr->method('getHomeGroup')->willReturn(5);

        $this->memberRepository->method('findAll')->willReturn([$nonGsr]);
        $this->positionRepository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        expect($html)->toContain('No members are currently marked as GSR');
    });
});

// ── positions section ────────────────────────────────────────────
describe('positions section', function () {
    it('lists filled positions with holders', function () {
        $this->memberRepository->method('findAll')->willReturn([]);
        ($this->filledPosition)(7, 'Treasurer', ['Anonymous Alex', 'Anonymous Sam']);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        expect($html)->toContain('Treasurer')
            ->toContain('Anonymous Alex, Anonymous Sam');
    });

    it('leaves a vacant position out', function () {
        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn(7);

        $view = $this->createMock(PositionView::class);
        $view->method('isVacant')->willReturn(true);
        $view->method('getMembers')->willReturn([]);

        $this->memberRepository->method('findAll')->willReturn([]);
        $this->positionRepository->method('findAll')->willReturn([$position]);
        $this->viewFactory->method('createFrom')->willReturn($view);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        expect($html)->toContain('No filled positions found');
    });
});

// ── styles and scripts ───────────────────────────────────────────
it('emits styles and scripts on the dashboard', function () {
    $this->setScreen('dashboard', 'dashboard');

    expect($this->capture(fn () => $this->dashboard->addDashboardStyles()))->toContain('<style>')
        ->and($this->capture(fn () => $this->dashboard->addDashboardScripts()))->toContain('<script>');
});

it('keeps styles and scripts off other admin screens', function () {
    $this->setScreen('edit-post', 'edit', 'post');

    expect($this->capture(fn () => $this->dashboard->addDashboardStyles()))->toBe('')
        ->and($this->capture(fn () => $this->dashboard->addDashboardScripts()))->toBe('');
});
