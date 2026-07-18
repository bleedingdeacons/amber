<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\MeetingReconciler;
use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
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
class MeetingReconcilerEnhancementsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private MeetingReconciler $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $meetingRepo = Mockery::mock(MeetingRepository::class);
        $apiCache    = Mockery::mock(ApiCache::class);
        $this->reconciler = new MeetingReconciler($meetingRepo, $apiCache);
    }

    // ── Address similarity ─────────────────────────────────────────────

    /** @test */
    public function address_similarity_full_postcode_match_scores_one(): void
    {
        $listing = $this->stubListing(['postcode' => 'SL2 4HL']);
        $score = $this->invoke('addressSimilarity', [
            'Wexham Park Hospital, Slough, SL2 4HL',
            $listing,
        ]);

        $this->assertSame(1.0, $score);
    }

    /** @test */
    public function address_similarity_outward_only_scores_partial(): void
    {
        $listing = $this->stubListing(['postcode' => 'SL2 4HL']);
        $score = $this->invoke('addressSimilarity', [
            'Some other site, SL2 9XX',
            $listing,
        ]);

        $this->assertSame(0.7, $score);
    }

    /** @test */
    public function address_similarity_falls_back_to_town_when_postcode_absent(): void
    {
        $listing = $this->stubListing(['postcode' => '', 'town' => 'Slough']);
        $score = $this->invoke('addressSimilarity', [
            'Town Hall, Slough',
            $listing,
        ]);

        $this->assertSame(0.6, $score);
    }

    /** @test */
    public function address_similarity_returns_zero_when_no_signal(): void
    {
        $listing = $this->stubListing(['postcode' => 'SL2 4HL', 'town' => 'Slough']);
        $score = $this->invoke('addressSimilarity', [
            'Different Place, Bristol, BS1 1AA',
            $listing,
        ]);

        $this->assertSame(0.0, $score);
    }

    /** @test */
    public function address_similarity_normalises_postcode_spacing(): void
    {
        // Local address has no space between out/in code; should still match.
        $listing = $this->stubListing(['postcode' => 'SL2 4HL']);
        $score = $this->invoke('addressSimilarity', [
            'Wexham Park Hospital, SL24HL',
            $listing,
        ]);

        $this->assertSame(1.0, $score);
    }

    // ── Postcode extraction ────────────────────────────────────────────

    /** @test */
    public function extract_postcodes_finds_uk_postcodes_in_freetext(): void
    {
        $found = $this->invoke('extractPostcodes', ['Some Place, Slough, SL2 4HL']);
        $this->assertSame(['SL2 4HL'], $found);
    }

    /** @test */
    public function extract_postcodes_handles_mixed_case_and_spacing(): void
    {
        $found = $this->invoke('extractPostcodes', ['near sl24hl right there']);
        $this->assertSame(['SL2 4HL'], $found);
    }

    /** @test */
    public function extract_postcodes_returns_empty_when_none_present(): void
    {
        $found = $this->invoke('extractPostcodes', ['No postcode in this string']);
        $this->assertSame([], $found);
    }

    // ── Open-status detection ──────────────────────────────────────────

    /** @test */
    public function open_status_recognises_open_and_open_again_case_insensitively(): void
    {
        $this->assertTrue($this->invoke('isOpenStatus', ['Open']));
        $this->assertTrue($this->invoke('isOpenStatus', ['open again']));
        $this->assertTrue($this->invoke('isOpenStatus', ['OPEN AGAIN']));
        $this->assertTrue($this->invoke('isOpenStatus', ['']));
    }

    /** @test */
    public function open_status_rejects_closed_and_suspended(): void
    {
        $this->assertFalse($this->invoke('isOpenStatus', ['Closed']));
        $this->assertFalse($this->invoke('isOpenStatus', ['Suspended']));
        $this->assertFalse($this->invoke('isOpenStatus', ['Temporarily Closed']));
    }

    // ── End-time discrepancy with tolerance ────────────────────────────

    /** @test */
    public function end_time_discrepancy_ignores_small_differences(): void
    {
        // 5 minutes apart — under the 15 minute tolerance.
        $this->assertFalse($this->invoke('endTimeDiscrepancy', ['20:30', '20:35']));
    }

    /** @test */
    public function end_time_discrepancy_flags_differences_over_tolerance(): void
    {
        // 30 minutes apart.
        $this->assertTrue($this->invoke('endTimeDiscrepancy', ['20:00', '20:30']));
    }

    /** @test */
    public function end_time_discrepancy_returns_false_when_one_side_empty(): void
    {
        $this->assertFalse($this->invoke('endTimeDiscrepancy', ['', '20:30']));
        $this->assertFalse($this->invoke('endTimeDiscrepancy', ['20:30', '']));
    }

    /** @test */
    public function end_time_discrepancy_returns_false_when_identical(): void
    {
        $this->assertFalse($this->invoke('endTimeDiscrepancy', ['20:30', '20:30']));
    }

    // ── Postcode normalisation ─────────────────────────────────────────

    /** @test */
    public function normalise_postcode_inserts_space_before_inward_code(): void
    {
        $this->assertSame('SL2 4HL', $this->invoke('normalisePostcode', ['SL24HL']));
        $this->assertSame('SL2 4HL', $this->invoke('normalisePostcode', ['  sl2 4hl  ']));
        $this->assertSame('BS1 5AA', $this->invoke('normalisePostcode', ['BS1  5AA']));
    }

    /** @test */
    public function postcode_outward_returns_first_half(): void
    {
        $this->assertSame('SL2', $this->invoke('postcodeOutward', ['SL2 4HL']));
        $this->assertSame('BS1', $this->invoke('postcodeOutward', ['BS1 5AA']));
    }

    // ── Helpers ────────────────────────────────────────────────────────

    /**
     * Invoke a private method on the reconciler under test.
     *
     * @param mixed[] $args
     * @return mixed
     */
    private function invoke(string $method, array $args)
    {
        $ref = (new \ReflectionClass(MeetingReconciler::class))->getMethod($method);
        return $ref->invoke($this->reconciler, ...$args);
    }

    /**
     * Build a Mockery GroupListing with the given fields.
     *
     * @param array<string, string> $fields
     */
    private function stubListing(array $fields): GroupListing
    {
        $listing = Mockery::mock(GroupListing::class);
        $listing->shouldReceive('getPostcode')->andReturn($fields['postcode'] ?? '');
        $listing->shouldReceive('getTown')->andReturn($fields['town'] ?? '');
        $listing->shouldReceive('getAddress1')->andReturn($fields['address1'] ?? '');
        $listing->shouldReceive('getMeetingStatus')->andReturn($fields['meetingStatus'] ?? '');
        return $listing;
    }
}
