<?php

declare(strict_types=1);

namespace Amber\Tests\Unit;

use Amber\Common\Functions;
use Amber\Core\HelpPage;
use Amber\Managers\FrontPageManager;
use BleedingDeacons\WpMocks\WpState;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/*
 * Tests for the remaining front-end helpers.
 *
 * Functions is the tiny mailto/tel/anchor toolkit the shortcodes lean on.
 * FrontPageManager renders the "today's meetings" list — sorted by start time,
 * each row labelled Online or with its location, and a friendly line when the
 * day is empty. HelpPage renders the admin manual, falling back to an inline
 * notice if its template is missing. None of these are large, but together
 * they are a good chunk of otherwise-uncovered front-of-house code.
 */

covers(Functions::class, FrontPageManager::class, HelpPage::class);

// ── Functions ────────────────────────────────────────────────────
describe('Functions', function () {
    it('builds a plain mailto with emailTo', function () {
        expect(Functions::emailTo('sec@example.test'))->toBe('mailto:sec@example.test');
    });

    it('appends an encoded subject with emailTo', function () {
        expect(Functions::emailTo('sec@example.test', 'Hello There'))
            ->toBe('mailto:sec@example.test?subject=Hello+There');
    });

    it('builds a tel link with phoneTo', function () {
        expect(Functions::phoneTo('0117 000 0000'))->toBe('tel:0117 000 0000');
    });

    it('builds a safe new tab anchor with linkTo', function () {
        $html = Functions::linkTo('https://example.test', 'btn', 'Visit');

        expect($html)->toContain('rel="noreferrer noopener"')
            ->toContain('href="https://example.test"')
            ->toContain('>Visit<');
    });

    it('composes the mailto and the link in the email anchor', function () {
        $html = Functions::createEmailAnchor('sec@example.test', 'Hi', 'btn', 'Email');

        expect($html)->toContain('mailto:sec@example.test?subject=Hi')
            ->toContain('>Email<');
    });
});

// ── FrontPageManager ─────────────────────────────────────────────
describe('FrontPageManager', function () {
    beforeEach(function () {
        $this->meeting = function (string $time, string $name, bool $online = false, ?Location $location = null): Meeting {
            $meeting = $this->createMock(Meeting::class);
            $meeting->method('getTime')->willReturn($time);
            $meeting->method('getName')->willReturn($name);
            $meeting->method('getUrl')->willReturn('https://example.test/' . $name);
            $meeting->method('isOnline')->willReturn($online);
            $meeting->method('getLocation')->willReturn($location);

            return $meeting;
        };
    });

    it("lists today's meetings in start time order", function () {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([
            ($this->meeting)('19:30', 'Evening'),
            ($this->meeting)('08:00', 'Morning'),
        ]);

        $html = (new FrontPageManager($repo))->render();

        // Sorted lexically on zero-padded HH:MM, so Morning precedes Evening.
        expect(strpos($html, 'Morning'))->toBeLessThan(strpos($html, 'Evening'));
    });

    it('labels an online meeting online', function () {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([($this->meeting)('19:00', 'Zoom', true)]);

        expect((new FrontPageManager($repo))->render())->toContain('Online');
    });

    it('shows the location of an in person meeting', function () {
        $location = $this->createMock(Location::class);
        $location->method('getName')->willReturn('Church Hall');

        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([($this->meeting)('19:00', 'Hall', false, $location)]);

        expect((new FrontPageManager($repo))->render())->toContain('Church Hall');
    });

    it('renders an empty attendance cell for a meeting with no location', function () {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([($this->meeting)('19:00', 'Nowhere', false, null)]);

        $html = (new FrontPageManager($repo))->render();

        expect($html)->toContain('attendance-option')
            ->toContain('Nowhere');
    });

    it('says no meetings are scheduled on an empty day', function () {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([]);

        expect((new FrontPageManager($repo))->render())->toContain('No meetings scheduled for today');
    });

    it('registers the shortcode on construction', function () {
        $repo = $this->createMock(MeetingRepository::class);

        new FrontPageManager($repo);

        expect(WpState::$shortcodes)->toHaveKey('todays_meetings');
    });
});

// ── HelpPage ─────────────────────────────────────────────────────
describe('HelpPage', function () {
    it('renders its template', function () {
        // Point the plugin dir at the real Amber root so the bundled template
        // is found and included.
        if (!defined('AMBER_PLUGIN_DIR')) {
            define('AMBER_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        $html = $this->capture(static fn () => HelpPage::render());

        expect($html)->not->toBe('');
    });

    it('emits the help tab script', function () {
        $script = $this->capture(static fn () => HelpPage::enqueueHelpTabScript());

        expect($script)->toContain('<script>')
            ->toContain('page=amber-help');
    });

    // window.open() returns null when a popup blocker or an extension refuses
    // the window. preventDefault() has already run by then, so without an
    // explicit fallback the Help link would be inert — and the next line would
    // throw on the null handle rather than failing quietly.
    it('falls back to the current tab when the help window is blocked', function () {
        $script = $this->capture(static fn () => HelpPage::enqueueHelpTabScript());

        expect($script)->toContain('if (!existing) {')
            ->toContain('window.location.href = helpUrl;');
    });
});
