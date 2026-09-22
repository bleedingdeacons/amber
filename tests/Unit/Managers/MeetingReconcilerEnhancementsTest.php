<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\MeetingReconciler;
use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Unity\Meetings\Interfaces\MeetingRepository;

/*
 * Unit tests for the new MeetingReconciler features:
 *  - composite (name + address) scoring
 *  - postcode and town address similarity
 *  - closed-national branching
 *  - end-time discrepancy with tolerance
 *
 * These tests use reflection to exercise the private helpers directly.
 * The matching pipeline as a whole is harder to test in isolation
 * (fetchNationalGroups is private), so we cover the building blocks here
 * and let the existing tests cover the orchestration shape.
 */

// This file runs on plain PHPUnit rather than AmberTestCase, and builds its
// fixtures with Mockery. Keeping the integration trait means Mockery is closed
// and verified after every test, as it was before the conversion.
uses(MockeryPHPUnitIntegration::class);

// ── Helpers ────────────────────────────────────────────────────────

/**
 * Build a Mockery GroupListing with the given fields.
 *
 * postcode, address1 and meetingStatus are not promoted to getters on
 * GroupListing — they live in the raw API payload behind getRawValue().
 * This helper used to stub getPostcode()/getAddress1()/getMeetingStatus()
 * instead, which Mockery is happy to invent even though the real class has
 * no such methods. That is exactly what hid the fatal: the tests passed
 * against methods that do not exist, while production died with "Call to
 * undefined method" on any national match. Stub the real accessor so these
 * fixtures cannot diverge from the class again.
 *
 * @param array<string, string> $fields
 */
function stubListing(array $fields): GroupListing
{
    $listing = Mockery::mock(GroupListing::class);
    $listing->shouldReceive('getTown')->andReturn($fields['town'] ?? '');
    $listing->shouldReceive('getRawValue')
        ->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $fields[$key] ?? $default);

    return $listing;
}

beforeEach(function () {
    $meetingRepo = Mockery::mock(MeetingRepository::class);
    $apiCache    = Mockery::mock(ApiCache::class);
    $this->reconciler = new MeetingReconciler($meetingRepo, $apiCache);

    /**
     * Invoke a private method on the reconciler under test.
     *
     * @param mixed[] $args
     * @return mixed
     */
    $this->invoke = function (string $method, array $args) {
        $ref = (new \ReflectionClass(MeetingReconciler::class))->getMethod($method);
        return $ref->invoke($this->reconciler, ...$args);
    };
});

// ── Address similarity ─────────────────────────────────────────────
describe('addressSimilarity', function () {
    it('scores one for a full postcode match', function () {
        $listing = stubListing(['postcode' => 'SL2 4HL']);
        $score = ($this->invoke)('addressSimilarity', [
            'Wexham Park Hospital, Slough, SL2 4HL',
            $listing,
        ]);

        expect($score)->toBe(1.0);
    });

    it('scores partial for an outward only match', function () {
        $listing = stubListing(['postcode' => 'SL2 4HL']);
        $score = ($this->invoke)('addressSimilarity', [
            'Some other site, SL2 9XX',
            $listing,
        ]);

        expect($score)->toBe(0.7);
    });

    it('falls back to the town when the postcode is absent', function () {
        $listing = stubListing(['postcode' => '', 'town' => 'Slough']);
        $score = ($this->invoke)('addressSimilarity', [
            'Town Hall, Slough',
            $listing,
        ]);

        expect($score)->toBe(0.6);
    });

    it('returns zero when there is no signal', function () {
        $listing = stubListing(['postcode' => 'SL2 4HL', 'town' => 'Slough']);
        $score = ($this->invoke)('addressSimilarity', [
            'Different Place, Bristol, BS1 1AA',
            $listing,
        ]);

        expect($score)->toBe(0.0);
    });

    it('normalises postcode spacing', function () {
        // Local address has no space between out/in code; should still match.
        $listing = stubListing(['postcode' => 'SL2 4HL']);
        $score = ($this->invoke)('addressSimilarity', [
            'Wexham Park Hospital, SL24HL',
            $listing,
        ]);

        expect($score)->toBe(1.0);
    });
});

// ── Postcode extraction ────────────────────────────────────────────
describe('extractPostcodes', function () {
    it('finds uk postcodes in freetext', function () {
        $found = ($this->invoke)('extractPostcodes', ['Some Place, Slough, SL2 4HL']);
        expect($found)->toBe(['SL2 4HL']);
    });

    it('handles mixed case and spacing', function () {
        $found = ($this->invoke)('extractPostcodes', ['near sl24hl right there']);
        expect($found)->toBe(['SL2 4HL']);
    });

    it('returns empty when none are present', function () {
        $found = ($this->invoke)('extractPostcodes', ['No postcode in this string']);
        expect($found)->toBe([]);
    });
});

// ── Open-status detection ──────────────────────────────────────────
describe('isOpenStatus', function () {
    it('recognises open and open again case insensitively', function () {
        expect(($this->invoke)('isOpenStatus', ['Open']))->toBeTrue()
            ->and(($this->invoke)('isOpenStatus', ['open again']))->toBeTrue()
            ->and(($this->invoke)('isOpenStatus', ['OPEN AGAIN']))->toBeTrue()
            ->and(($this->invoke)('isOpenStatus', ['']))->toBeTrue();
    });

    it('rejects closed and suspended', function () {
        expect(($this->invoke)('isOpenStatus', ['Closed']))->toBeFalse()
            ->and(($this->invoke)('isOpenStatus', ['Suspended']))->toBeFalse()
            ->and(($this->invoke)('isOpenStatus', ['Temporarily Closed']))->toBeFalse();
    });
});

// ── End-time discrepancy with tolerance ────────────────────────────
describe('endTimeDiscrepancy', function () {
    it('ignores small differences', function () {
        // 5 minutes apart — under the 15 minute tolerance.
        expect(($this->invoke)('endTimeDiscrepancy', ['20:30', '20:35']))->toBeFalse();
    });

    it('flags differences over the tolerance', function () {
        // 30 minutes apart.
        expect(($this->invoke)('endTimeDiscrepancy', ['20:00', '20:30']))->toBeTrue();
    });

    it('returns false when one side is empty', function () {
        expect(($this->invoke)('endTimeDiscrepancy', ['', '20:30']))->toBeFalse()
            ->and(($this->invoke)('endTimeDiscrepancy', ['20:30', '']))->toBeFalse();
    });

    it('returns false when identical', function () {
        expect(($this->invoke)('endTimeDiscrepancy', ['20:30', '20:30']))->toBeFalse();
    });
});

// ── Postcode normalisation ─────────────────────────────────────────
it('inserts a space before the inward code when normalising a postcode', function () {
    expect(($this->invoke)('normalisePostcode', ['SL24HL']))->toBe('SL2 4HL')
        ->and(($this->invoke)('normalisePostcode', ['  sl2 4hl  ']))->toBe('SL2 4HL')
        ->and(($this->invoke)('normalisePostcode', ['BS1  5AA']))->toBe('BS1 5AA');
});

it('returns the first half as the postcode outward', function () {
    expect(($this->invoke)('postcodeOutward', ['SL2 4HL']))->toBe('SL2')
        ->and(($this->invoke)('postcodeOutward', ['BS1 5AA']))->toBe('BS1');
});
