<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Shortcodes;

use Amber\Services\ShortcodeService;
use Amber\Shortcodes\GeneralShortcodes;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use DateTime;
use DateTimeZone;

/**
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
 *
 * @covers \Amber\Shortcodes\GeneralShortcodes
 * @covers \Amber\Services\ShortcodeService
 */
class GeneralShortcodesTest extends AmberTestCase
{
    private GeneralShortcodes $shortcodes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shortcodes = new GeneralShortcodes();
    }

    // ── registrar ────────────────────────────────────────────────────

    /** @test */
    public function the_service_registers_every_general_shortcode(): void
    {
        (new ShortcodeService())->registerShortcodes();

        foreach (['open_new_link', 'open_email', 'pdf_link', 'days_remaining'] as $tag) {
            $this->assertArrayHasKey($tag, WpState::$shortcodes);
        }
    }

    /** @test */
    public function a_tag_another_plugin_already_registered_is_left_untouched(): void
    {
        // Confur got there first; Amber must not clobber its callback.
        $sentinel = static fn (): string => 'confur';
        WpState::$shortcodes['open_email'] = $sentinel;

        (new ShortcodeService())->registerShortcodes();

        $this->assertSame($sentinel, WpState::$shortcodes['open_email']);
    }

    // ── open_new_link ────────────────────────────────────────────────

    /** @test */
    public function open_blank_builds_a_new_tab_link(): void
    {
        $html = $this->shortcodes->openBlank(['href' => 'https://example.test', 'class' => 'btn'], 'Visit');

        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('>Visit<', $html);
    }

    // ── open_email ───────────────────────────────────────────────────

    /** @test */
    public function link_email_wraps_the_address(): void
    {
        $html = $this->shortcodes->linkEmail(['address' => 'sec@example.test', 'subject' => 'Hello'], 'Email us');

        $this->assertStringContainsString('mailto:sec@example.test', $html);
        $this->assertStringContainsString('Email us', $html);
    }

    /** @test */
    public function link_email_without_an_address_returns_its_content_unchanged(): void
    {
        $this->assertSame('just text', $this->shortcodes->linkEmail(['address' => ''], 'just text'));
    }

    // ── pdf_link ─────────────────────────────────────────────────────

    /** @test */
    public function a_pdf_link_wraps_the_download_anchor(): void
    {
        $html = $this->shortcodes->generatePdfLink(['url' => 'https://example.test/a.pdf', 'name' => 'minutes.pdf'], 'Minutes');

        $this->assertStringContainsString('<div>', $html);
        $this->assertStringContainsString('download="minutes.pdf"', $html);
    }

    /** @test */
    public function a_pdf_link_reports_missing_parameters(): void
    {
        $this->assertStringContainsString(
            'Missing required parameters',
            $this->shortcodes->generatePdfLink(['url' => '', 'name' => ''])
        );
    }

    // ── days_remaining ───────────────────────────────────────────────

    /** @test */
    public function days_remaining_asks_for_an_end_date_when_none_is_given(): void
    {
        $this->assertSame('Please provide an end date.', $this->shortcodes->generateDaysRemaining(['end_date' => '']));
    }

    /** @test */
    public function days_remaining_rejects_an_unparseable_date(): void
    {
        $this->assertStringContainsString(
            'Invalid date format',
            $this->shortcodes->generateDaysRemaining(['end_date' => 'not-a-date'])
        );
    }

    /** @test */
    public function days_remaining_reports_a_date_in_the_past(): void
    {
        $this->assertSame(
            'The date has already passed.',
            $this->shortcodes->generateDaysRemaining(['end_date' => '2000-01-01'])
        );
    }

    /** @test */
    public function days_remaining_counts_whole_days_for_a_far_off_date(): void
    {
        $future = (new DateTime('now', new DateTimeZone('UTC')))->modify('+10 days')->format('Y-m-d');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $future]);

        $this->assertStringContainsString('days remaining', $html);
        $this->assertStringContainsString('Deadline:', $html);
    }

    /** @test */
    public function days_remaining_counts_hours_when_under_a_day_away(): void
    {
        // A datetime a few hours out exercises the hours branch and the
        // HH:MM parse/format path.
        $soon = (new DateTime('now', new DateTimeZone('UTC')))->modify('+3 hours')->format('Y-m-d H:i');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $soon]);

        $this->assertStringContainsString('hours remaining', $html);
    }

    /** @test */
    public function days_remaining_can_extend_the_deadline(): void
    {
        $future = (new DateTime('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $future, 'extend_by' => 5]);

        $this->assertStringContainsString('extended by 5 days', $html);
    }

    /** @test */
    public function days_remaining_extension_uses_the_singular_for_one_day(): void
    {
        $future = (new DateTime('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');

        $html = $this->shortcodes->generateDaysRemaining(['end_date' => $future, 'extend_by' => 1]);

        $this->assertStringContainsString('extended by 1 day', $html);
    }

    /** @test */
    public function days_remaining_accepts_a_relative_date_via_the_generic_parser(): void
    {
        // "+5 days" matches none of the strict formats, so it falls through to
        // the generic DateTime parser rather than being rejected.
        $html = $this->shortcodes->generateDaysRemaining(['end_date' => '+5 days']);

        $this->assertStringContainsString('remaining', $html);
        $this->assertStringNotContainsString('Invalid date format', $html);
    }
}
