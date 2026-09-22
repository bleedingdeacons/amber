<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\PositionShortcodeRenderer;
use BleedingDeacons\WpMocks\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/*
 * Tests for the public position shortcodes.
 *
 * These are the tags an intergroup drops into a page to show who holds which
 * service position and when they rotate. Each callback wraps its work in a
 * try/catch that swallows the error into a bland "Error building…" string, so
 * the two things worth pinning are: the visible copy each rotation state
 * produces (Vacant, Overdue, Rotates in N Months, tenure), and that a missing
 * post id lands in the guarded fallback rather than a white screen.
 */

covers(PositionShortcodeRenderer::class);

function atCurrentPost(int $id = 7): void
{
    WpState::$options['__current_post_id'] = $id;
}

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturn(['POST_TYPE' => 'intergroup-position', 'SUMMARY' => 'summary']);

    $this->viewFactory = $this->createMock(PositionViewFactory::class);

    $this->renderer = new PositionShortcodeRenderer($config, $this->viewFactory);

    $this->position = function (array $overrides = []): Position {
        $defaults = [
            'getMinimumSobriety' => 24,
            'getTermYears'       => 3,
            'getLink'            => 'https://example.test/positions/treasurer',
            'getLongName'        => 'Treasurer',
        ];

        $position = $this->createMock(Position::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $position->method($method)->willReturn($value);
        }

        return $position;
    };

    $this->view = function (array $overrides = [], ?Position $position = null): PositionView {
        $defaults = [
            'isArchivist'            => false,
            'isVacant'               => false,
            'getRotationDate'        => new DateTime('2030-01-01'),
            'getMonthsUntilRotation' => 3,
            'getTitle'               => 'Treasurer',
            'getPositionEmail'       => 'treasurer@example.test',
            'getDescription'         => 'Treasurer',
            'getPublicDisplayName'   => 'Anonymous Alex',
        ];

        $view = $this->createMock(PositionView::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $view->method($method)->willReturn($value);
        }
        $view->method('getPosition')->willReturn($position ?? ($this->position)());

        return $view;
    };
});

// ── position_state ───────────────────────────────────────────────
describe('position_state', function () {
    it('falls back gracefully without a current post', function () {
        WpState::$options['__current_post_id'] = 0;

        expect($this->renderer->renderPositionState())->toContain('Error building position state');
    });

    it('says vacant for a vacant post', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['isVacant' => true]));

        $html = $this->renderer->renderPositionState();

        expect($html)->toContain('Vacant!')
            ->toContain('Email Service Officer');
    });

    it('shows no rotation heading for an archivist', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['isArchivist' => true]));

        $html = $this->renderer->renderPositionState();

        // Archivist tenure is permanent, so the heading is intentionally blank.
        expect($html)->toContain('<h1></h1>');
    });

    it('flags a missing rotation date', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['getRotationDate' => null]));

        expect($this->renderer->renderPositionState())->toContain('No Rotation Date!');
    });

    it('describes the rotation status', function (?int $months, string $expected) {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(
            ($this->view)(['getMonthsUntilRotation' => $months])
        );

        expect($this->renderer->renderPositionState())->toContain($expected);
    })->with([
        'overdue'      => [-2, 'Rotation Overdue!'],
        'due now'      => [0, 'Rotation Due Now'],
        'next month'   => [1, 'Rotation Next Month'],
        'within window' => [3, 'Rotates in 3 Months'],
        'unknown'      => [null, 'Status Unknown'],
    ]);

    it('shows an empty status far from rotation', function () {
        // Beyond the warning window there is nothing to flag, so the heading
        // collapses to empty.
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['getMonthsUntilRotation' => 24]));

        expect($this->renderer->renderPositionState())->toContain('<h1></h1>');
    });
});

// ── position_header ──────────────────────────────────────────────
describe('position_header', function () {
    it('renders title, sobriety and term', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)());

        $html = $this->renderer->renderPositionHeader();

        expect($html)->toContain('Treasurer')
            ->toContain('Sobriety 2 Years')
            ->toContain('Term 3 Years');
    });

    it('renders sobriety in months when not a whole year', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(
            ($this->view)([], ($this->position)(['getMinimumSobriety' => 18]))
        );

        expect($this->renderer->renderPositionHeader())->toContain('Sobriety 18 Months');
    });

    it('uses the singular year for a single year of sobriety', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(
            ($this->view)([], ($this->position)(['getMinimumSobriety' => 12, 'getTermYears' => 1]))
        );

        $html = $this->renderer->renderPositionHeader();

        expect($html)->toContain('Sobriety 1 Year')
            ->toContain('Term 1 Year');
    });

    it('shows tenure for an archivist', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['isArchivist' => true]));

        expect($this->renderer->renderPositionHeader())->toContain('Term Tenure');
    });

    it('labels the email officer when the title says officer', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['getTitle' => 'Public Information Officer']));

        expect($this->renderer->renderPositionHeader())->toContain('Email Officer');
    });

    it('hides the email link for a vacant post', function () {
        atCurrentPost();
        $this->viewFactory->method('createFrom')->willReturn(($this->view)(['isVacant' => true]));

        expect($this->renderer->renderPositionHeader())->not->toContain('pseudo_link');
    });
});

// ── directory_list ───────────────────────────────────────────────
it('renders a directory table row per position', function () {
    $this->viewFactory->method('createAll')->willReturn([
        ($this->view)(['getDescription' => 'Treasurer']),
        ($this->view)(['isVacant' => true, 'getDescription' => 'Secretary']),
        ($this->view)(['isArchivist' => true, 'getDescription' => 'Archivist']),
        ($this->view)(['getRotationDate' => null, 'getDescription' => 'Chair']),
    ]);

    $html = $this->renderer->renderDirectoryTable();

    expect($html)->toContain('id="service_positions"')
        ->toContain('Treasurer')
        ->toContain('Position Vacant')
        ->toContain('No Rotation Date!')
        ->and(substr_count($html, '<tr>'))->toBe(4);
});

// ── position_summary ─────────────────────────────────────────────
describe('position_summary', function () {
    it('wraps the summary field', function () {
        atCurrentPost();
        $this->setField(7, 'summary', '<p>Keeps the books.</p>');

        $html = $this->renderer->renderPositionSummary();

        expect($html)->toContain('Keeps the books.');
    });

    it('falls back without a current post', function () {
        WpState::$options['__current_post_id'] = 0;

        expect($this->renderer->renderPositionSummary())->toContain('Error loading position summary');
    });
});

// ── vacant_positions ─────────────────────────────────────────────
describe('vacant_positions', function () {
    it('lists only the vacant ones', function () {
        $this->viewFactory->method('createAll')->willReturn([
            ($this->view)(['isVacant' => false, 'getDescription' => 'Treasurer']),
            ($this->view)(['isVacant' => true, 'getDescription' => 'Secretary']),
        ]);

        $html = $this->renderer->renderVacantPositions();

        expect($html)->toContain('Secretary')
            ->not->toContain('Treasurer');
    });

    it('falls back to the long name for a vacant position without a description', function () {
        $this->viewFactory->method('createAll')->willReturn([
            ($this->view)(['isVacant' => true, 'getDescription' => ''], ($this->position)(['getLongName' => 'General Service Rep'])),
        ]);

        expect($this->renderer->renderVacantPositions())->toContain('General Service Rep');
    });

    it('says so when there are none', function () {
        $this->viewFactory->method('createAll')->willReturn([
            ($this->view)(['isVacant' => false]),
        ]);

        expect($this->renderer->renderVacantPositions())->toContain('no vacant positions');
    });
});

// ── guarded failure paths ────────────────────────────────────────
it('falls back in the header when the view cannot be built', function () {
    atCurrentPost();
    $this->viewFactory->method('createFrom')->willThrowException(new \RuntimeException('boom'));

    expect($this->renderer->renderPositionHeader())->toContain('Error building position header');
});

it('falls back in the directory table on error', function () {
    $this->viewFactory->method('createAll')->willThrowException(new \RuntimeException('boom'));

    expect($this->renderer->renderDirectoryTable())->toContain('Error generating directory list');
});

it('falls back in the vacant list on error', function () {
    $this->viewFactory->method('createAll')->willThrowException(new \RuntimeException('boom'));

    expect($this->renderer->renderVacantPositions())->toContain('Error building vacant positions list');
});
