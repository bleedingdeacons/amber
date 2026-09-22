<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Positions;

use Amber\Admin\Positions\PositionDashboard;
use BleedingDeacons\WpMocks\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/*
 * Tests for the Positions & Members dashboard widget.
 *
 * This is the at-a-glance screen an intergroup officer sees on login: one
 * card per position, showing who holds it and when they rotate. The cases
 * that matter are the ones that look like data problems — a vacant post, a
 * position with no title, an Archivist (permanent tenure, so no "current
 * member" row) — because a widget that renders a blank card or a PHP notice
 * on the dashboard is the most visible failure Amber can have.
 */

covers(PositionDashboard::class);

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturn(['POST_TYPE' => 'intergroup-member']);

    $this->viewFactory = $this->createMock(PositionViewFactory::class);
    $this->repository = $this->createMock(PositionRepository::class);

    $this->dashboard = new PositionDashboard($config, $this->viewFactory, $this->repository);

    $this->position = function (int $id = 7): Position {
        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn($id);

        return $position;
    };

    $this->member = function (int $id, string $name): Member {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    };

    $this->view = function (array $overrides = []): PositionView {
        $defaults = [
            'getTitle' => 'Treasurer',
            'isVacant' => false,
            'getMembers' => [($this->member)(1, 'Anonymous Alex')],
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
    };

    /** Render the widget over the given views. */
    $this->renderWith = function (PositionView ...$views): string {
        $this->repository->method('findAll')->willReturn(
            array_map(fn (int $i): Position => ($this->position)($i), range(1, max(count($views), 1)))
        );
        $this->viewFactory->method('createFrom')->willReturnOnConsecutiveCalls(...$views ?: [null]);

        return $this->capture(fn () => $this->dashboard->renderDashboardWidget());
    };
});

// ── registration ─────────────────────────────────────────────────
describe('registration', function () {
    it('registers the dashboard widget hooks', function () {
        $this->assertHookAdded('wp_dashboard_setup');
        $this->assertHookAdded('admin_head');
    });

    it('registers the widget on the dashboard', function () {
        $this->dashboard->registerDashboardWidget();

        expect(WpState::$widgets)->toHaveKey('position_members_dashboard')
            ->and(WpState::$widgets['position_members_dashboard']['name'])->toBe('Positions & Members');
    });

    it('emits the widget styles on the dashboard', function () {
        $this->setScreen('dashboard', 'dashboard');

        $css = $this->capture(fn () => $this->dashboard->addDashboardStyles());

        expect($css)->toContain('<style>');
    });

    it('does not emit the widget styles on other screens', function () {
        // The widget only appears on the dashboard, so its CSS has no
        // business loading on every admin page.
        $this->setScreen('edit-post', 'edit', 'post');

        expect($this->capture(fn () => $this->dashboard->addDashboardStyles()))->toBe('');
    });
});

// ── rendering ────────────────────────────────────────────────────
describe('rendering', function () {
    it('says so rather than rendering nothing for a site with no positions', function () {
        $this->repository->method('findAll')->willReturn([]);

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        expect($html)->toContain('No positions found');
    });

    it('renders a position as a card with its holder', function () {
        $html = ($this->renderWith)(($this->view)());

        expect($html)->toContain('position-card')
            ->toContain('Treasurer')
            ->toContain('Anonymous Alex')
            ->toContain('treasurer@example.test');
    });

    it('marks a vacant position vacant', function () {
        $html = ($this->renderWith)(($this->view)(['isVacant' => true, 'getMembers' => []]));

        expect($html)->toContain('Vacant');
    });

    it('falls back to a placeholder for a position with no title', function () {
        // Better a labelled card than an anonymous empty one.
        $html = ($this->renderWith)(($this->view)(['getTitle' => '']));

        expect($html)->toContain('Untitled Position');
    });

    it('omits the current member row from the archivist card', function () {
        // Archivist is a permanent tenure, so "current member" and rotation
        // are not meaningful for it.
        $html = ($this->renderWith)(($this->view)(['getDescription' => 'Archivist']));

        expect($html)->not->toContain('Current Member');
    });

    it('shows the current member row on a non archivist card', function () {
        $html = ($this->renderWith)(($this->view)());

        expect($html)->toContain('Current Member');
    });

    it('lists every holder of a job share', function () {
        $html = ($this->renderWith)(($this->view)([
            'getMembers' => [($this->member)(1, 'Anonymous Alex'), ($this->member)(2, 'Anonymous Sam')],
        ]));

        expect($html)->toContain('Anonymous Alex')
            ->toContain('Anonymous Sam');
    });

    it('orders positions by title', function () {
        $html = ($this->renderWith)(
            ($this->view)(['getTitle' => 'Treasurer']),
            ($this->view)(['getTitle' => 'Chair'])
        );

        // Sorted case-insensitively, so Chair precedes Treasurer regardless
        // of the order the repository returned them in.
        expect(strpos($html, 'Chair'))->toBeLessThan(strpos($html, 'Treasurer'));
    });

    it('skips positions without a view', function () {
        $this->repository->method('findAll')->willReturn([($this->position)(1), ($this->position)(2)]);
        $this->viewFactory->method('createFrom')->willReturnOnConsecutiveCalls(null, ($this->view)());

        $html = $this->capture(fn () => $this->dashboard->renderDashboardWidget());

        expect(substr_count($html, 'position-card-header'))->toBe(1);
    });

    it('still renders a position with no email', function () {
        $html = ($this->renderWith)(($this->view)(['getPositionEmail' => '']));

        expect($html)->toContain('Position Email');
    });

    it('still renders a position with no rotation date', function () {
        $html = ($this->renderWith)(($this->view)(['getRotationDate' => null]));

        expect($html)->toContain('position-card');
    });
});

// ── status badge ─────────────────────────────────────────────────
it('reflects the rotation state in the status badge', function (?int $months, string $expected) {
    $html = ($this->renderWith)(($this->view)([
        'getMonthsUntilRotation' => $months,
        'getDaysUntilRotation'   => $months === null ? null : $months * 30,
    ]));

    expect($html)->toContain($expected);
})->with([
    'unknown when months null' => [null, 'status-unknown'],
    'overdue when negative'    => [-2, 'status-overdue'],
    'due at zero'              => [0, 'status-due'],
    'soon within three'       => [2, 'status-soon'],
    'filled beyond three'     => [12, 'status-normal'],
]);

it('shows how many months overdue in an overdue member cell', function () {
    $html = ($this->renderWith)(($this->view)([
        'getMonthsUntilRotation' => -3,
        'getDaysUntilRotation'   => -90,
    ]));

    expect($html)->toContain('Overdue 3 months');
});
