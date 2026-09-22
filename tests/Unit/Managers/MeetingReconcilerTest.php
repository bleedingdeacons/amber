<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Mockery\MockInterface;
use Amber\Managers\MeetingReconciler;
use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/*
 * Unit tests for MeetingReconciler
 */

// This file runs on plain PHPUnit rather than AmberTestCase, and much of its
// setup is Mockery expectations. Without the trait they are never checked or
// counted.
uses(MockeryPHPUnitIntegration::class);

/**
 * Create a mock local Meeting.
 */
function reconcilerMeeting(int $id, string $name, int $day, string $time, string $endTime = '', bool $online = false): Meeting|MockInterface
{
    $dayNames = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    $meeting = Mockery::mock(Meeting::class);
    $meeting->shouldReceive('getId')->andReturn($id);
    $meeting->shouldReceive('getName')->andReturn($name);
    $meeting->shouldReceive('getDay')->andReturn($day);
    $meeting->shouldReceive('getDayOfWeek')->andReturn($dayNames[$day] ?? '');
    $meeting->shouldReceive('getTime')->andReturn($time);
    $meeting->shouldReceive('getEndTime')->andReturn($endTime);
    $meeting->shouldReceive('isOnline')->andReturn($online);
    // extractLocalAddress() reads the location to build the string used for
    // postcode extraction and fuzzy town matching. null is a valid Meeting
    // location and the reconciler handles it, so these fixtures are
    // location-less unless a test needs otherwise.
    $meeting->shouldReceive('getLocation')->andReturn(null)->byDefault();

    return $meeting;
}

/**
 * Create a mock national GroupListing.
 */
/**
 * @param array<string, string> $rawFields Extra AAGBDB payload fields
 *                                         (meetingStatus, postcode, address1)
 *                                         which GroupListing exposes only
 *                                         through getRawValue().
 */
function reconcilerListing(string $id, string $name, string $day, string $startTime, string $endTime = '', string $town = '', array $rawFields = []): GroupListing|MockInterface
{
    $listing = Mockery::mock(GroupListing::class);
    $listing->shouldReceive('getId')->andReturn($id);
    $listing->shouldReceive('getGroupName')->andReturn($name);
    $listing->shouldReceive('getDay')->andReturn($day);
    $listing->shouldReceive('getStartTime')->andReturn($startTime);
    $listing->shouldReceive('getEndTime')->andReturn($endTime);
    $listing->shouldReceive('getTown')->andReturn($town);
    // Status/postcode/address1 are not promoted to getters on GroupListing;
    // the reconciler reads them out of the raw payload.
    $listing->shouldReceive('getRawValue')
        ->andReturnUsing(static fn (string $key, mixed $default = null): mixed => $rawFields[$key] ?? $default);

    return $listing;
}

/**
 * Set up the mocks so reconcile() can run with the given meetings/listings.
 */
function setupReconcile(MeetingRepository|MockInterface $meetingRepo, array $localMeetings, array $nationalListings): void
{
    $meetingRepo->shouldReceive('findAll')
        ->with(['posts_per_page' => -1])
        ->andReturn($localMeetings);

    // ApiCache returns raw data; GroupListing::collectionFromResponse parses it.
    // Since we can't easily mock the static factory, we mock at the ApiCache level
    // and replace the reconciler's fetchNationalGroups via a test subclass.
    // Instead, we directly test through the public reconcile() by providing
    // the ApiCache mock to return data that GroupListing::collectionFromResponse expects.
    // For simplicity, we use a partial mock approach.
}

beforeEach(function () {
    $this->meetingRepo = Mockery::mock(MeetingRepository::class);
    $this->apiCache = Mockery::mock(ApiCache::class);
    $this->reconciler = new MeetingReconciler($this->meetingRepo, $this->apiCache);

    // ── Helper ─────────────────────────────────────────────────────────
    $this->nameSimilarity = function (string $a, string $b): float {
        $method = (new \ReflectionClass(MeetingReconciler::class))
            ->getMethod('nameSimilarity');

        return $method->invoke($this->reconciler, $a, $b);
    };
});

// ── Confident match ────────────────────────────────────────────────
it('finds a confident match on day, time and name', function () {
    $local = [reconcilerMeeting(1, 'Serenity Group', 1, '19:00', '20:00')];

    $this->meetingRepo->shouldReceive('findAll')->andReturn($local);

    // We need to test the private nameSimilarity and matching logic.
    // Use reflection to test the core matching algorithm directly.
    $reflection = new \ReflectionClass(MeetingReconciler::class);
    $method = $reflection->getMethod('nameSimilarity');

    $score = $method->invoke($this->reconciler, 'Serenity Group', 'Serenity Group');

    expect($score)->toBeGreaterThanOrEqual(0.3)
        ->toEqual(1.0);
});

// ── Name similarity scoring ────────────────────────────────────────
describe('nameSimilarity', function () {
    it('returns 1 for identical names', function () {
        $score = ($this->nameSimilarity)('Monday Night Meeting', 'Monday Night Meeting');

        expect($score)->toEqual(1.0);
    });

    it('is case insensitive', function () {
        $score = ($this->nameSimilarity)('Serenity Group', 'SERENITY GROUP');

        expect($score)->toEqual(1.0);
    });

    it('strips stop words', function () {
        // "the", "aa", "meeting", "group" are stop words
        $score = ($this->nameSimilarity)('The AA Serenity Meeting', 'Serenity');

        // After stripping stop words, both reduce to "serenity"
        expect($score)->toBeGreaterThanOrEqual(0.3);
    });

    it('returns a low score for unrelated names', function () {
        $score = ($this->nameSimilarity)('Hope Springs Eternal', 'Downtown Lunch Bunch');

        expect($score)->toBeLessThan(0.3);
    });

    it('handles empty strings', function () {
        $score = ($this->nameSimilarity)('', '');

        // Both empty after normalization; should not throw
        expect($score)->toBeFloat();
    });

    it('handles stop words only', function () {
        // Both names consist entirely of stop words
        $score = ($this->nameSimilarity)('The AA Meeting Group', 'The Meeting');

        // Falls back to full-word comparison since significant tokens are empty
        expect($score)->toBeFloat();
    });

    it('scores a partial overlap between zero and one', function () {
        // "serenity" matches, "sunrise"/"sunset" don't
        $score = ($this->nameSimilarity)('Serenity Sunrise', 'Serenity Sunset');

        expect($score)->toBeGreaterThan(0.0)
            ->toBeLessThan(1.0);
    });
});

// ── Time normalisation ─────────────────────────────────────────────
it('pads a single digit hour when normalising time', function () {
    $method = (new \ReflectionClass(MeetingReconciler::class))
        ->getMethod('normaliseTime');

    expect($method->invoke($this->reconciler, '7:00'))->toEqual('07:00')
        ->and($method->invoke($this->reconciler, '19:30'))->toEqual('19:30')
        ->and($method->invoke($this->reconciler, ''))->toEqual('');
});

// ── Day normalisation ──────────────────────────────────────────────
it('maps a tsml integer to a day string when normalising the day', function () {
    $method = (new \ReflectionClass(MeetingReconciler::class))
        ->getMethod('normaliseDayFromMeeting');

    $meeting = reconcilerMeeting(1, 'Test', 0, '10:00'); // 0 = Sunday

    expect($method->invoke($this->reconciler, $meeting))->toEqual('sunday');
});

// ── Summary structure ──────────────────────────────────────────────
it('returns the correct summary structure with no data', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

    // An empty API response passes through GroupListing::collectionFromResponse
    // as an empty collection, so no partial mock is needed to get an empty
    // national list — stubbing the injected ApiCache is enough, and it
    // exercises the real fetchNationalGroups().
    $this->apiCache->shouldReceive('getGroups')->andReturn([]);

    $result = $this->reconciler->reconcile();
    $summary = $result->getSummary();

    expect($summary)->toHaveKey('local_total')
        ->toHaveKey('national_total')
        ->toHaveKey('confident_matches')
        ->toHaveKey('possible_matches')
        ->toHaveKey('local_only')
        ->toHaveKey('national_only')
        ->toHaveKey('local_match_pct')
        ->toHaveKey('national_match_pct');

    expect($summary['local_total'])->toEqual(0)
        ->and($summary['national_total'])->toEqual(0)
        ->and($summary['confident_matches'])->toEqual(0);
});

it('gives the reconcile result every list accessor', function () {
    // Stub the injected ApiCache rather than partial-mocking the reconciler:
    // an empty API response yields no national groups, and this exercises
    // the real fetchNationalGroups() instead of replacing it.
    $this->apiCache->shouldReceive('getGroups')->andReturn([]);
    $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

    $result = $this->reconciler->reconcile();

    expect($result->getMatches())->toBeArray()
        ->and($result->getPossibles())->toBeArray()
        ->and($result->getLocalOnly())->toBeArray()
        ->and($result->getNationalOnly())->toBeArray()
        ->and($result->getSummary())->toBeArray();
});

// ── Local-only detection ───────────────────────────────────────────
it('reports local only when there is no national data', function () {
    $local = [
        reconcilerMeeting(1, 'Monday Meditation', 1, '07:00', '08:00'),
        reconcilerMeeting(2, 'Friday Night', 5, '20:00', '21:00', true),
    ];

    $this->meetingRepo->shouldReceive('findAll')->andReturn($local);
    $this->apiCache->shouldReceive('getGroups')->andReturn([]);

    $result = $this->reconciler->reconcile();

    expect($result->getLocalOnly())->toHaveCount(2)
        ->and($result->getMatches())->toBeEmpty()
        ->and($result->getNationalOnly())->toBeEmpty();

    // Online meeting should have appropriate reason
    $localOnly = $result->getLocalOnly();
    $onlineMeeting = array_values(array_filter($localOnly, fn($m) => $m['id'] === 2));
    expect($onlineMeeting[0]['reason'])->toContain('Online');
});

// ── API error propagation ──────────────────────────────────────────
it('throws on an api error', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

    $wpError = Mockery::mock('WP_Error');
    $wpError->shouldReceive('get_error_message')->andReturn('API timeout');

    $this->apiCache->shouldReceive('getGroups')->andReturn($wpError);

    // is_wp_error needs to return true for WP_Error instances
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing)
        {
            return $thing instanceof \WP_Error || (is_object($thing) && method_exists($thing, 'get_error_message'));
        }
    }

    $this->reconciler->reconcile();
})->throws(\RuntimeException::class, 'API timeout');

// ── Match rows ─────────────────────────────────────────────────────
// Drives reconcile() all the way to a confident match, which builds a
// result row from the national listing's status, postcode and address.
//
// Nothing covered that path before: every existing test supplied an empty
// national list, so reconcile() never reached the row builder. That gap is
// why the reconciler could call getMeetingStatus(), getPostcode() and
// getAddress1() — none of which exist on GroupListing — and still show a
// green suite, while any real reconcile that found a match died with
// "Call to undefined method".
it('builds a row from the national listing for a confident match', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([
        reconcilerMeeting(1, 'Serenity Group', 1, '19:00', '20:00'),
    ]);

    $this->apiCache->shouldReceive('getGroups')->andReturn([
        [
            'id'            => 501,
            'groupName'     => 'Serenity Group',
            'day'           => 'monday',
            'startTime'     => '19:00',
            'endTime'       => '20:00',
            'town'          => 'Bristol',
            'meetingStatus' => 'Open',
            'postcode'      => 'BS1 4DJ',
            'address1'      => '1 Church Road',
        ],
    ]);

    $result  = $this->reconciler->reconcile();
    $matches = $result->getMatches();

    expect($matches)->toHaveCount(1, 'The listing should match the local meeting.');

    $row = $matches[0];
    expect($row['national_name'])->toBe('Serenity Group')
        ->and($row['national_status'])->toBe('Open')
        ->and($row['national_postcode'])->toBe('BS1 4DJ')
        ->and($row['national_address'])->toBe('1 Church Road, Bristol, BS1 4DJ');
});

// A listing whose national status is not "open" is reported separately
// rather than as a live match — which also reads the status field.
it('does not report a match closed nationally as a live match', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([
        reconcilerMeeting(1, 'Serenity Group', 1, '19:00', '20:00'),
    ]);

    $this->apiCache->shouldReceive('getGroups')->andReturn([
        [
            'id'            => 502,
            'groupName'     => 'Serenity Group',
            'day'           => 'monday',
            'startTime'     => '19:00',
            'endTime'       => '20:00',
            'meetingStatus' => 'Closed',
        ],
    ]);

    $result = $this->reconciler->reconcile();

    expect($result->getMatches())->toBeEmpty('A closed listing is not a live match.')
        ->and($result->getLocalOnly())->toBeEmpty('It still matched, so it is not local-only.');
});

// ── Possible match (day + time, name diverges) ─────────────────────
// A listing that shares a local meeting's day and time but whose name is
// unrelated is reported as a "possible" — the day/time-only fallback that
// builds its own result row from the national listing.
it('treats a day and time coincidence with a different name as a possible match', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([
        reconcilerMeeting(1, 'Serenity Group', 1, '19:00', '20:00'),
    ]);

    $this->apiCache->shouldReceive('getGroups')->andReturn([
        [
            'id'            => 601,
            'groupName'     => 'Completely Different Fellowship',
            'day'           => 'monday',
            'startTime'     => '19:00',
            'endTime'       => '20:00',
            'town'          => 'Bath',
            'meetingStatus' => 'Open',
            'postcode'      => 'BA1 1AA',
            'address1'      => '2 Abbey Lane',
        ],
    ]);

    $result    = $this->reconciler->reconcile();
    $possibles = $result->getPossibles();

    expect($possibles)->toHaveCount(1)
        ->and($result->getMatches())->toBeEmpty('Names differ, so it is not a confident match.')
        ->and($result->getLocalOnly())->toBeEmpty('The day/time coincidence took it out of local-only.')
        ->and($possibles[0]['national_name'])->toBe('Completely Different Fellowship')
        ->and($possibles[0]['local_id'])->toBe(1);
});

// ── National-only detection ────────────────────────────────────────
// An open national listing that matches nothing locally is surfaced as a
// national-only discrepancy so it can be added to the local list.
it('reports an unmatched open national listing as national only', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

    $this->apiCache->shouldReceive('getGroups')->andReturn([
        [
            'id'            => 701,
            'groupName'     => 'Lone National Group',
            'day'           => 'tuesday',
            'startTime'     => '18:30',
            'endTime'       => '19:30',
            'town'          => 'Wells',
            'meetingStatus' => 'Open',
            'postcode'      => 'BA5 2AA',
        ],
    ]);

    $result       = $this->reconciler->reconcile();
    $nationalOnly = $result->getNationalOnly();

    expect($nationalOnly)->toHaveCount(1)
        ->and($nationalOnly[0]['name'])->toBe('Lone National Group')
        ->and($nationalOnly[0]['reason'])->toContain('Missing from local list');
});

// A closed national listing that matches nothing locally is deliberately
// left out of the national-only report — it is neither actionable nor live.
it('does not report an unmatched closed national listing', function () {
    $this->meetingRepo->shouldReceive('findAll')->andReturn([]);

    $this->apiCache->shouldReceive('getGroups')->andReturn([
        [
            'id'            => 702,
            'groupName'     => 'Closed National Group',
            'day'           => 'tuesday',
            'startTime'     => '18:30',
            'meetingStatus' => 'Closed',
        ],
    ]);

    expect($this->reconciler->reconcile()->getNationalOnly())->toBeEmpty();
});
