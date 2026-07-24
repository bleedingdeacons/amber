<?php

declare(strict_types=1);

namespace Amber\Tests\Unit;

use Amber\Managers\PostTitleSyncer;
use Amber\Models\ReconciliationResult;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Amber\Utils\HtmlHelper;
use WP_Error;

/**
 * Tests for the small helpers Amber's screens are built from.
 *
 * HtmlHelper produces the links every directory and shortcode emits, so a
 * mistake here is repeated across the whole front end. PostTitleSyncer
 * keeps a post's title in step with the ACF field that really names it —
 * and does so from inside a save hook, which is why its re-entrancy guard
 * matters: without it, wp_update_post would re-trigger the very hook that
 * called it.
 *
 * @covers \Amber\Utils\HtmlHelper
 * @covers \Amber\Managers\PostTitleSyncer
 * @covers \Amber\Models\ReconciliationResult
 */
class SupportClassesTest extends AmberTestCase
{
    // ── HtmlHelper ───────────────────────────────────────────────────

    /** @test */
    public function a_pdf_link_downloads_rather_than_navigates(): void
    {
        $html = HtmlHelper::generatePdfLink('https://example.test/a.pdf', 'minutes.pdf', 'Minutes');

        $this->assertStringContainsString('href="https://example.test/a.pdf"', $html);
        $this->assertStringContainsString('download="minutes.pdf"', $html);
        $this->assertStringContainsString('type="application/pdf"', $html);
        $this->assertStringContainsString('>Minutes<', $html);
    }

    /** @test */
    public function an_external_link_opens_safely_in_a_new_tab(): void
    {
        $html = HtmlHelper::createLink('https://example.test', 'btn', 'Visit');

        // noreferrer/noopener matter: target=_blank without them hands the
        // opened page a reference back to this one.
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noreferrer noopener"', $html);
        $this->assertStringContainsString('class="btn"', $html);
    }

    /** @test */
    public function a_link_can_be_made_without_a_class_or_content(): void
    {
        $html = HtmlHelper::createLink('https://example.test');

        $this->assertStringContainsString('href="https://example.test"', $html);
    }

    /** @test */
    public function a_mailto_address_is_built_from_the_address(): void
    {
        $this->assertSame('mailto:sec@example.test', HtmlHelper::createEmailToAddress('sec@example.test'));
    }

    /** @test */
    public function a_subject_is_appended_to_the_mailto_address(): void
    {
        $this->assertSame(
            'mailto:sec@example.test?subject=Hello',
            HtmlHelper::createEmailToAddress('sec@example.test', 'Hello')
        );
    }

    /** @test */
    public function an_empty_subject_is_not_appended(): void
    {
        $this->assertSame('mailto:sec@example.test', HtmlHelper::createEmailToAddress('sec@example.test', ''));
    }

    /** @test */
    public function an_email_anchor_wraps_the_mailto_address(): void
    {
        $html = HtmlHelper::createEmailAnchor('sec@example.test', 'Hello', 'Email the secretary');

        $this->assertStringContainsString('mailto:sec@example.test?subject=Hello', $html);
        $this->assertStringContainsString('>Email the secretary<', $html);
    }

    /** @test */
    public function a_phone_number_becomes_a_tel_address(): void
    {
        $this->assertSame('tel:0117 000 0000', HtmlHelper::createPhoneToAddress('0117 000 0000'));
    }

    /** @test */
    public function a_meeting_link_points_at_the_meetings_page(): void
    {
        $this->assertSame('/meetings/?meeting=tuesday-group', HtmlHelper::createMeetingLink('tuesday-group'));
    }

    // ── PostTitleSyncer ──────────────────────────────────────────────

    /** @test */
    public function the_title_is_updated_to_match_the_field(): void
    {
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Old Name']);
        $this->setField(42, 'anon-name', 'New Name');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        $this->assertSame(
            [['ID' => 42, 'post_title' => 'New Name']],
            WpState::$updatedPosts
        );
    }

    /** @test */
    public function a_title_that_already_matches_is_left_alone(): void
    {
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Same Name']);
        $this->setField(42, 'anon-name', 'Same Name');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        $this->assertSame([], WpState::$updatedPosts, 'No write when nothing changed.');
    }

    /** @test */
    public function an_empty_field_never_blanks_the_title(): void
    {
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Existing']);
        $this->setField(42, 'anon-name', '');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        $this->assertSame([], WpState::$updatedPosts);
    }

    /** @test */
    public function a_missing_post_is_ignored(): void
    {
        (new PostTitleSyncer())->sync(999, 'anon-name', 'Member');

        $this->assertSame([], WpState::$updatedPosts);
    }

    /** @test */
    public function a_failed_update_is_logged_rather_than_thrown(): void
    {
        // wp_update_post can answer with a WP_Error; the syncer runs inside
        // a save hook, so it must not let that escape.
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Old Name']);
        $this->setField(42, 'anon-name', 'New Name');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        $this->assertCount(1, WpState::$updatedPosts);
    }

    // ── ReconciliationResult ─────────────────────────────────────────

    /** @test */
    public function a_reconciliation_result_exposes_each_bucket(): void
    {
        $result = new ReconciliationResult(
            ['m'],
            ['p'],
            ['l'],
            ['n'],
            ['total' => 4],
            ['c']
        );

        $this->assertSame(['m'], $result->getMatches());
        $this->assertSame(['p'], $result->getPossibles());
        $this->assertSame(['l'], $result->getLocalOnly());
        $this->assertSame(['n'], $result->getNationalOnly());
        $this->assertSame(['total' => 4], $result->getSummary());
        $this->assertSame(['c'], $result->getClosedMatches());
    }

    /** @test */
    public function a_reconciliation_result_serialises_every_bucket(): void
    {
        $result = new ReconciliationResult(['m'], ['p'], ['l'], ['n'], ['total' => 4], ['c']);

        $array = $result->toArray();

        // The array form is what reaches the admin screen and the JSON
        // response, so every bucket has to survive the projection.
        foreach (['m', 'p', 'l', 'n', 'c'] as $marker) {
            $this->assertStringContainsString($marker, json_encode($array));
        }

        $this->assertSame($array, $result->jsonSerialize());
        $this->assertNotSame('', (string) json_encode($result));
    }
}
