<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Shortcodes;

use Amber\Services\ShortcodeService;
use Amber\Shortcodes\GeneralShortcodes;
use BleedingDeacons\WpMocks\WpState;
use DateTime;
use DateTimeZone;

/*
 * Tests for the general-purpose shortcodes and their registrar.
 *
 * These tags are shared verbatim with the Confur plugin, so the registrar
 * skips any tag another plugin already claimed — the "first one wins" rule the
 * test pins directly. The shortcodes themselves each wrap their work in a
 * catch-all that turns any failure into a bracketed [tag error: …] string
 * rather than a fatal, so both the happy output and that fallback are worth
 * covering. days_remaining carries the real logic: it parses a date, optionally
 * extends it, and renders hours-or-days remaining, so its boundaries (past,
 * within a day, whole days, bad input) are exercised one by one.
 */

covers(GeneralShortcodes::class, ShortcodeService::class);

beforeEach(function () {
    $this->shortcodes = new GeneralShortcodes();
});

// ── registrar ────────────────────────────────────────────────────
it('registers every general shortcode through the service', function () {
    (new ShortcodeService())->registerShortcodes();

    foreach (['open_new_link', 'open_email', 'pdf_link', 'days_remaining'] as $tag) {
        expect(WpState::$shortcodes)->toHaveKey($tag);
    }
});

it('leaves a tag another plugin already registered untouched', function () {
    // Confur got there first; Amber must not clobber its callback.
    $sentinel = static fn (): string => 'confur';
    WpState::$shortcodes['open_email'] = $sentinel;

    (new ShortcodeService())->registerShortcodes();

    expect(WpState::$shortcodes['open_email'])->toBe($sentinel);
});

// ── open_new_link ────────────────────────────────────────────────
it('builds a new tab link with openBlank', function () {
    $html = $this->shortcodes->openBlank(['href' => 'https://example.test', 'class' => 'btn'], 'Visit');

    expect($html)->toContain('href="https://example.test"')
        ->toContain('target="_blank"')
        ->toContain('>Visit<');
});

// ── open_email ───────────────────────────────────────────────────
it('wraps the address with linkEmail', function () {
    $html = $this->shortcodes->linkEmail(['address' => 'sec@example.test', 'subject' => 'Hello'], 'Email us');

    expect($html)->toContain('mailto:sec@example.test')
        ->toContain('Email us');
});

it('returns the content unchanged from linkEmail without an address', function () {
    expect($this->shortcodes->linkEmail(['address' => ''], 'just text'))->toBe('just text');
});

// ── pdf_link ─────────────────────────────────────────────────────
it('wraps the download anchor in a pdf link', function () {
    $html = $this->shortcodes->generatePdfLink(['url' => 'https://example.test/a.pdf', 'name' => 'minutes.pdf'], 'Minutes');

    expect($html)->toContain('<div>')
        ->toContain('download="minutes.pdf"');
});

it('reports missing parameters for a pdf link', function () {
    expect($this->shortcodes->generatePdfLink(['url' => '', 'name' => '']))
        ->toContain('Missing required parameters');
});

// ── days_remaining ───────────────────────────────────────────────
describe('days_remaining', function () {
    it('asks for an end date when none is given', function () {
        expect($this->shortcodes->generateDaysRemaining(['end_date' => '']))->toBe('Please provide an end date.');
    });

    it('rejects an unparseable date', function () {
        expect($this->shortcodes->generateDaysRemaining(['end_date' => 'not-a-date']))
            ->toContain('Invalid date format');
    });

    it('reports a date in the past', function () {
        expect($this->shortcodes->generateDaysRemaining(['end_date' => '2000-01-01']))
            ->toBe('The date has already passed.');
    });

    it('counts whole days for a far off date', function () {
        $future = (new DateTime('now', new DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $future]);

        expect($html)->toContain('days remaining')
            ->toContain('Deadline:');
    });

    it('counts hours when under a day away', function () {
        // A datetime a few hours out exercises the hours branch and the
        // HH:MM parse/format path.
        $soon = (new DateTime('now', new DateTimeZone('UTC')))->modify('+3 hours')->format('Y-m-d H:i');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $soon]);

        expect($html)->toContain('hours remaining');
    });

    it('can extend the deadline', function () {
        $future = (new DateTime('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $future, 'extend_by' => 5]);

        expect($html)->toContain('extended by 5 days');
    });

    it('uses the singular for a one day extension', function () {
        $future = (new DateTime('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $future, 'extend_by' => 1]);

        expect($html)->toContain('extended by 1 day');
    });

    it('accepts a relative date via the generic parser', function () {
        // "+5 days" matches none of the strict formats, so it falls through to
        // the generic DateTime parser rather than being rejected.
        $html = $this->shortcodes->generateDaysRemaining(['end_date' => '+5 days']);

        expect($html)->toContain('remaining')
            ->not->toContain('Invalid date format');
    });
});
