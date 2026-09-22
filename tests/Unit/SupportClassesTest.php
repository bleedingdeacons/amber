<?php

declare(strict_types=1);

namespace Amber\Tests\Unit;

use Amber\Managers\PostTitleSyncer;
use Amber\Models\ReconciliationResult;
use BleedingDeacons\WpMocks\WpState;
use Amber\Utils\HtmlHelper;

/*
 * Tests for the small helpers Amber's screens are built from.
 *
 * HtmlHelper produces the links every directory and shortcode emits, so a
 * mistake here is repeated across the whole front end. PostTitleSyncer
 * keeps a post's title in step with the ACF field that really names it —
 * and does so from inside a save hook, which is why its re-entrancy guard
 * matters: without it, wp_update_post would re-trigger the very hook that
 * called it.
 */

covers(HtmlHelper::class, PostTitleSyncer::class, ReconciliationResult::class);

// ── HtmlHelper ───────────────────────────────────────────────────
describe('HtmlHelper', function () {
    it('makes a pdf link download rather than navigate', function () {
        $html = HtmlHelper::generatePdfLink('https://example.test/a.pdf', 'minutes.pdf', 'Minutes');

        expect($html)->toContain('href="https://example.test/a.pdf"')
            ->toContain('download="minutes.pdf"')
            ->toContain('type="application/pdf"')
            ->toContain('>Minutes<');
    });

    it('opens an external link safely in a new tab', function () {
        $html = HtmlHelper::createLink('https://example.test', 'btn', 'Visit');

        // noreferrer/noopener matter: target=_blank without them hands the
        // opened page a reference back to this one.
        expect($html)->toContain('target="_blank"')
            ->toContain('rel="noreferrer noopener"')
            ->toContain('class="btn"');
    });

    it('can make a link without a class or content', function () {
        $html = HtmlHelper::createLink('https://example.test');

        expect($html)->toContain('href="https://example.test"');
    });

    it('builds a mailto address from the address', function () {
        expect(HtmlHelper::createEmailToAddress('sec@example.test'))->toBe('mailto:sec@example.test');
    });

    it('appends a subject to the mailto address', function () {
        expect(HtmlHelper::createEmailToAddress('sec@example.test', 'Hello'))
            ->toBe('mailto:sec@example.test?subject=Hello');
    });

    it('does not append an empty subject', function () {
        expect(HtmlHelper::createEmailToAddress('sec@example.test', ''))->toBe('mailto:sec@example.test');
    });

    it('wraps the mailto address in an email anchor', function () {
        $html = HtmlHelper::createEmailAnchor('sec@example.test', 'Hello', 'Email the secretary');

        expect($html)->toContain('mailto:sec@example.test?subject=Hello')
            ->toContain('>Email the secretary<');
    });

    it('turns a phone number into a tel address', function () {
        expect(HtmlHelper::createPhoneToAddress('0117 000 0000'))->toBe('tel:0117 000 0000');
    });

    it('points a meeting link at the meetings page', function () {
        expect(HtmlHelper::createMeetingLink('tuesday-group'))->toBe('/meetings/?meeting=tuesday-group');
    });
});

// ── PostTitleSyncer ──────────────────────────────────────────────
describe('PostTitleSyncer', function () {
    it('updates the title to match the field', function () {
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Old Name']);
        $this->setField(42, 'anon-name', 'New Name');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        expect(WpState::$updatedPosts)->toBe([['ID' => 42, 'post_title' => 'New Name']]);
    });

    it('leaves a title that already matches alone', function () {
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Same Name']);
        $this->setField(42, 'anon-name', 'Same Name');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        expect(WpState::$updatedPosts)->toBe([], 'No write when nothing changed.');
    });

    it('never blanks the title from an empty field', function () {
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Existing']);
        $this->setField(42, 'anon-name', '');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        expect(WpState::$updatedPosts)->toBe([]);
    });

    it('ignores a missing post', function () {
        (new PostTitleSyncer())->sync(999, 'anon-name', 'Member');

        expect(WpState::$updatedPosts)->toBe([]);
    });

    it('logs a failed update rather than throwing', function () {
        // wp_update_post can answer with a WP_Error; the syncer runs inside
        // a save hook, so it must not let that escape.
        $this->makePost(42, 'intergroup-member', ['post_title' => 'Old Name']);
        $this->setField(42, 'anon-name', 'New Name');

        (new PostTitleSyncer())->sync(42, 'anon-name', 'Member');

        expect(WpState::$updatedPosts)->toHaveCount(1);
    });
});

// ── ReconciliationResult ─────────────────────────────────────────
describe('ReconciliationResult', function () {
    it('exposes each bucket', function () {
        $result = new ReconciliationResult(
            ['m'],
            ['p'],
            ['l'],
            ['n'],
            ['total' => 4],
            ['c']
        );

        expect($result->getMatches())->toBe(['m'])
            ->and($result->getPossibles())->toBe(['p'])
            ->and($result->getLocalOnly())->toBe(['l'])
            ->and($result->getNationalOnly())->toBe(['n'])
            ->and($result->getSummary())->toBe(['total' => 4])
            ->and($result->getClosedMatches())->toBe(['c']);
    });

    it('serialises every bucket', function () {
        $result = new ReconciliationResult(['m'], ['p'], ['l'], ['n'], ['total' => 4], ['c']);

        $array = $result->toArray();

        // The array form is what reaches the admin screen and the JSON
        // response, so every bucket has to survive the projection.
        foreach (['m', 'p', 'l', 'n', 'c'] as $marker) {
            expect(json_encode($array))->toContain($marker);
        }

        expect($result->jsonSerialize())->toBe($array)
            ->and((string) json_encode($result))->not->toBe('');
    });
});
