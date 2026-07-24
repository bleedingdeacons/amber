<?php

declare(strict_types=1);

namespace Amber\Tests\Unit;

use Amber\Common\Functions;
use Amber\Core\HelpPage;
use Amber\Managers\FrontPageManager;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Unity\Locations\Interfaces\Location;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Tests for the remaining front-end helpers.
 *
 * Functions is the tiny mailto/tel/anchor toolkit the shortcodes lean on.
 * FrontPageManager renders the "today's meetings" list — sorted by start time,
 * each row labelled Online or with its location, and a friendly line when the
 * day is empty. HelpPage renders the admin manual, falling back to an inline
 * notice if its template is missing. None of these are large, but together
 * they are a good chunk of otherwise-uncovered front-of-house code.
 *
 * @covers \Amber\Common\Functions
 * @covers \Amber\Managers\FrontPageManager
 * @covers \Amber\Core\HelpPage
 */
class SupportClassesExtraTest extends AmberTestCase
{
    // ── Functions ────────────────────────────────────────────────────

    /** @test */
    public function email_to_builds_a_plain_mailto(): void
    {
        $this->assertSame('mailto:sec@example.test', Functions::emailTo('sec@example.test'));
    }

    /** @test */
    public function email_to_appends_an_encoded_subject(): void
    {
        $this->assertSame(
            'mailto:sec@example.test?subject=Hello+There',
            Functions::emailTo('sec@example.test', 'Hello There')
        );
    }

    /** @test */
    public function phone_to_builds_a_tel_link(): void
    {
        $this->assertSame('tel:0117 000 0000', Functions::phoneTo('0117 000 0000'));
    }

    /** @test */
    public function link_to_builds_a_safe_new_tab_anchor(): void
    {
        $html = Functions::linkTo('https://example.test', 'btn', 'Visit');

        $this->assertStringContainsString('rel="noreferrer noopener"', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
        $this->assertStringContainsString('>Visit<', $html);
    }

    /** @test */
    public function the_email_anchor_composes_the_mailto_and_the_link(): void
    {
        $html = Functions::createEmailAnchor('sec@example.test', 'Hi', 'btn', 'Email');

        $this->assertStringContainsString('mailto:sec@example.test?subject=Hi', $html);
        $this->assertStringContainsString('>Email<', $html);
    }

    // ── FrontPageManager ─────────────────────────────────────────────

    private function meeting(string $time, string $name, bool $online = false, ?Location $location = null): Meeting
    {
        $meeting = $this->createMock(Meeting::class);
        $meeting->method('getTime')->willReturn($time);
        $meeting->method('getName')->willReturn($name);
        $meeting->method('getUrl')->willReturn('https://example.test/' . $name);
        $meeting->method('isOnline')->willReturn($online);
        $meeting->method('getLocation')->willReturn($location);

        return $meeting;
    }

    /** @test */
    public function todays_meetings_are_listed_in_start_time_order(): void
    {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([
            $this->meeting('19:30', 'Evening'),
            $this->meeting('08:00', 'Morning'),
        ]);

        $html = (new FrontPageManager($repo))->render();

        // Sorted lexically on zero-padded HH:MM, so Morning precedes Evening.
        $this->assertLessThan(strpos($html, 'Evening'), strpos($html, 'Morning'));
    }

    /** @test */
    public function an_online_meeting_is_labelled_online(): void
    {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([$this->meeting('19:00', 'Zoom', true)]);

        $this->assertStringContainsString('Online', (new FrontPageManager($repo))->render());
    }

    /** @test */
    public function an_in_person_meeting_shows_its_location(): void
    {
        $location = $this->createMock(Location::class);
        $location->method('getName')->willReturn('Church Hall');

        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([$this->meeting('19:00', 'Hall', false, $location)]);

        $this->assertStringContainsString('Church Hall', (new FrontPageManager($repo))->render());
    }

    /** @test */
    public function a_meeting_with_no_location_renders_an_empty_attendance_cell(): void
    {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([$this->meeting('19:00', 'Nowhere', false, null)]);

        $html = (new FrontPageManager($repo))->render();

        $this->assertStringContainsString('attendance-option', $html);
        $this->assertStringContainsString('Nowhere', $html);
    }

    /** @test */
    public function an_empty_day_says_no_meetings_are_scheduled(): void
    {
        $repo = $this->createMock(MeetingRepository::class);
        $repo->method('findByDay')->willReturn([]);

        $this->assertStringContainsString('No meetings scheduled for today', (new FrontPageManager($repo))->render());
    }

    /** @test */
    public function the_shortcode_is_registered_on_construction(): void
    {
        $repo = $this->createMock(MeetingRepository::class);

        new FrontPageManager($repo);

        $this->assertArrayHasKey('todays_meetings', WpState::$shortcodes);
    }

    // ── HelpPage ─────────────────────────────────────────────────────

    /** @test */
    public function the_help_page_renders_its_template(): void
    {
        // Point the plugin dir at the real Amber root so the bundled template
        // is found and included.
        if (!defined('AMBER_PLUGIN_DIR')) {
            define('AMBER_PLUGIN_DIR', dirname(__DIR__, 2) . '/');
        }

        $html = $this->capture(static fn () => HelpPage::render());

        $this->assertNotSame('', $html);
    }

    /** @test */
    public function the_help_tab_script_is_emitted(): void
    {
        $script = $this->capture(static fn () => HelpPage::enqueueHelpTabScript());

        $this->assertStringContainsString('<script>', $script);
        $this->assertStringContainsString('page=amber-help', $script);
    }
}
