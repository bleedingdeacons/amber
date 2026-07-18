<?php

declare(strict_types=1);

namespace Amber\Managers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Models\ReconciliationResult;
use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

use function is_wp_error;

/**
 * Class MeetingReconciler
 *
 * Reconciles local Unity meetings (from MeetingRepository) against the
 * national AAGBDB group listings fetched via the Concordance ApiCache.
 *
 * Matching strategy:
 *  1. Confident match  — same day + same start time + composite (name + address)
 *                        similarity >= NAME_THRESHOLD and the national listing
 *                        is in an "open" state.
 *  2. Closed national  — confident match, but the national listing's
 *                        meetingStatus is anything other than open. Surfaced
 *                        as a distinct status so the user can decide whether
 *                        to retire the local meeting.
 *  3. Possible match   — same day + same start time, but composite similarity
 *                        below threshold.
 *  4. Local only       — present in Unity but unmatched nationally.
 *  5. National only    — present in AAGBDB but unmatched locally (open
 *                        listings only — closed-only national entries are
 *                        excluded so reports stay actionable).
 *
 * Composite scoring:
 *   final = NAME_WEIGHT * name_similarity + ADDRESS_WEIGHT * address_similarity
 *
 * Address similarity is itself composed of postcode and town signals
 * (see addressSimilarity()).
 */
class MeetingReconciler
{
    /** Minimum composite-similarity score (0–1) for a confident match. */
    private const NAME_THRESHOLD = 0.3;

    /** Composite scores below this are flagged as "weak match". */
    private const WEAK_NAME_THRESHOLD = 0.7;

    /** Weighting of name vs. address inside the composite score. Must sum to 1. */
    private const NAME_WEIGHT    = 0.7;
    private const ADDRESS_WEIGHT = 0.3;

    /** End-time discrepancies smaller than this (in minutes) are ignored. */
    private const END_TIME_TOLERANCE_MINUTES = 15;

    /**
     * Whitelist of national meetingStatus values that count as "currently
     * meeting". Anything else is treated as closed/suspended.
     *
     * Compared case-insensitively after trimming.
     *
     * @var string[]
     */
    private const OPEN_STATUSES = ['open', 'open again', ''];

    /** Words stripped before comparing names. */
    private const STOP_WORDS = [
        'bristol', 'the', 'aa', 'meeting', 'group', 'of', 'and', 'a', 'in', 'on',
    ];

    /** TSML day integers mapped to the day strings used by AAGBDB. */
    private const DAY_MAP = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    private MeetingRepository $meetingRepository;
    private ApiCache $apiCache;

    public function __construct(MeetingRepository $meetingRepository, ApiCache $apiCache)
    {
        $this->meetingRepository = $meetingRepository;
        $this->apiCache          = $apiCache;
    }

    /**
     * Run the full reconciliation.
     *
     * @param array $apiQueryArgs Optional query-string params forwarded to getGroups().
     * @return ReconciliationResult
     *
     * @throws \RuntimeException If the AAGBDB API call fails.
     */
    public function reconcile(array $apiQueryArgs = []): ReconciliationResult
    {
        $localMeetings  = $this->fetchLocalMeetings();
        $nationalGroups = $this->fetchNationalGroups($apiQueryArgs);

        $matches         = [];
        $closedMatches   = [];
        $localMatched    = [];
        $nationalMatched = [];

        // ── Pass 1: best candidate per local meeting (composite scoring) ──
        foreach ($localMeetings as $li => $local) {
            $bestScore   = 0.0;
            $bestNameSim = 0.0;
            $bestAddrSim = 0.0;
            $bestNi      = null;

            $lDay     = $this->normaliseDayFromMeeting($local);
            $lTime    = $this->normaliseTime($local->getTime());
            $lAddress = $this->extractLocalAddress($local);

            foreach ($nationalGroups as $ni => $national) {
                if (isset($nationalMatched[$ni])) {
                    continue;
                }

                $nDay  = mb_strtolower(trim($national->getDay()));
                $nTime = $this->normaliseTime($national->getStartTime());

                if ($lDay !== $nDay || $lTime !== $nTime) {
                    continue;
                }

                $nameSim = $this->nameSimilarity($local->getName(), $national->getGroupName());
                $addrSim = $this->addressSimilarity($lAddress, $national);
                $score   = self::NAME_WEIGHT * $nameSim + self::ADDRESS_WEIGHT * $addrSim;

                if ($score > $bestScore) {
                    $bestScore   = $score;
                    $bestNameSim = $nameSim;
                    $bestAddrSim = $addrSim;
                    $bestNi      = $ni;
                }
            }

            if ($bestScore >= self::NAME_THRESHOLD && $bestNi !== null) {
                $national = $nationalGroups[$bestNi];

                $endDiff  = $this->endTimeDiscrepancy(
                    $local->getEndTime(),
                    $national->getEndTime()
                );

                $notes = [];
                if ($endDiff) {
                    $notes[] = 'End time mismatch';
                }
                if ($bestScore < self::WEAK_NAME_THRESHOLD) {
                    $notes[] = 'Weak match';
                }
                if ($bestAddrSim === 0.0 && $lAddress !== '') {
                    $notes[] = 'Address differs';
                }

                $row = [
                    'local_name'       => $local->getName(),
                    'local_id'         => $local->getId(),
                    'national_name'    => $national->getGroupName(),
                    'national_id'      => $national->getId(),
                    'national_status'  => $this->nationalStatus($national),
                    'national_address' => $this->formatNationalAddress($national),
                    'national_postcode'=> $this->nationalPostcode($national),
                    'day'              => $local->getDayOfWeek(),
                    'start_time'       => $local->getTime(),
                    'local_end'        => $local->getEndTime(),
                    'national_end'     => $national->getEndTime(),
                    'score'            => round($bestScore, 2),
                    'name_score'       => round($bestNameSim, 2),
                    'address_score'    => round($bestAddrSim, 2),
                    'end_time_diff'    => $endDiff,
                    'notes'            => $notes,
                ];

                if ($this->isOpenStatus($this->nationalStatus($national))) {
                    $matches[] = $row;
                } else {
                    $row['notes'] = array_merge(
                        ['Closed nationally: ' . ($this->nationalStatus($national) ?: 'unknown')],
                        $notes
                    );
                    $closedMatches[] = $row;
                }

                $localMatched[$li]        = true;
                $nationalMatched[$bestNi] = true;
            }
        }

        // ── Pass 2: possible matches (day + time only) ──────────────────
        $possibles        = [];
        $localPossible    = [];
        $nationalPossible = [];

        foreach ($localMeetings as $li => $local) {
            if (isset($localMatched[$li])) {
                continue;
            }

            $lDay  = $this->normaliseDayFromMeeting($local);
            $lTime = $this->normaliseTime($local->getTime());

            foreach ($nationalGroups as $ni => $national) {
                if (isset($nationalMatched[$ni])) {
                    continue;
                }

                $nDay  = mb_strtolower(trim($national->getDay()));
                $nTime = $this->normaliseTime($national->getStartTime());

                if ($lDay === $nDay && $lTime === $nTime) {
                    $possibles[] = [
                        'local_name'       => $local->getName(),
                        'local_id'         => $local->getId(),
                        'national_name'    => $national->getGroupName(),
                        'national_id'      => $national->getId(),
                        'national_status'  => $this->nationalStatus($national),
                        'national_address' => $this->formatNationalAddress($national),
                        'national_postcode'=> $this->nationalPostcode($national),
                        'day'              => $local->getDayOfWeek(),
                        'start_time'       => $local->getTime(),
                        'local_end'        => $local->getEndTime(),
                        'national_end'     => $national->getEndTime(),
                    ];
                    $localPossible[$li]    = true;
                    $nationalPossible[$ni] = true;
                }
            }
        }

        // ── Unmatched ───────────────────────────────────────────────────
        $localOnly = [];
        foreach ($localMeetings as $li => $local) {
            if (!isset($localMatched[$li]) && !isset($localPossible[$li])) {
                $localOnly[] = [
                    'id'       => $local->getId(),
                    'name'     => $local->getName(),
                    'day'      => $local->getDayOfWeek(),
                    'time'     => $local->getTime(),
                    'end_time' => $local->getEndTime(),
                    'online'   => $local->isOnline(),
                    'reason'   => $local->isOnline() ? 'Online-only meeting' : 'Missing from national list',
                ];
            }
        }

        $nationalOnly = [];
        foreach ($nationalGroups as $ni => $national) {
            if (isset($nationalMatched[$ni]) || isset($nationalPossible[$ni])) {
                continue;
            }

            // National-only listings that are themselves closed are noise —
            // they're neither local nor currently meeting, so excluding them
            // keeps the report focused on actionable discrepancies.
            if (!$this->isOpenStatus($this->nationalStatus($national))) {
                continue;
            }

            $nationalOnly[] = [
                'id'         => $national->getId(),
                'name'       => $national->getGroupName(),
                'day'        => $national->getDay(),
                'start_time' => $national->getStartTime(),
                'end_time'   => $national->getEndTime(),
                'town'       => $national->getTown(),
                'postcode'   => $this->nationalPostcode($national),
                'reason'     => 'Missing from local list',
            ];
        }

        // ── Summary ─────────────────────────────────────────────────────
        $localTotal      = count($localMeetings);
        $nationalTotal   = count($nationalGroups);
        $endTimeDiffs    = count(array_filter($matches, static fn(array $m): bool => $m['end_time_diff']));
        $weakNames       = count(array_filter($matches, static fn(array $m): bool => $m['score'] < self::WEAK_NAME_THRESHOLD));
        $confidentTotal  = count($matches);

        $summary = [
            'local_total'            => $localTotal,
            'national_total'         => $nationalTotal,
            'confident_matches'      => $confidentTotal,
            'closed_matches'         => count($closedMatches),
            'end_time_discrepancies' => $endTimeDiffs,
            'weak_name_matches'      => $weakNames,
            'possible_matches'       => count($possibles),
            'local_only'             => count($localOnly),
            'national_only'          => count($nationalOnly),
            'local_match_pct'        => $localTotal > 0
                ? round($confidentTotal / $localTotal * 100, 1)
                : 0.0,
            'national_match_pct'     => $nationalTotal > 0
                ? round($confidentTotal / $nationalTotal * 100, 1)
                : 0.0,
        ];

        return new ReconciliationResult(
            $matches,
            $possibles,
            $localOnly,
            $nationalOnly,
            $summary,
            $closedMatches,
        );
    }

    // ── Private helpers ─────────────────────────────────────────────────

    /**
     * Fetch all local meetings from the Unity MeetingRepository.
     *
     * @return Meeting[]
     */
    private function fetchLocalMeetings(): array
    {
        return $this->meetingRepository->findAll([
            'posts_per_page' => -1,
        ]);
    }

    /**
     * Fetch national group listings from the AAGBDB API via the Concordance cache.
     *
     * @param array $queryArgs
     * @return GroupListing[]
     *
     * @throws \RuntimeException
     */
    private function fetchNationalGroups(array $queryArgs = []): array
    {
        $response = $this->apiCache->getGroups($queryArgs);

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Failed to fetch national groups: ' . $response->get_error_message()
            );
        }

        return GroupListing::collectionFromResponse($response);
    }

    /**
     * Extract and normalise the day string from a Unity Meeting.
     *
     * Meeting::getDay() returns a TSML integer (0 = Sunday … 6 = Saturday).
     * We map it to a lowercase day name matching the AAGBDB format.
     */
    private function normaliseDayFromMeeting(Meeting $meeting): string
    {
        return self::DAY_MAP[$meeting->getDay()] ?? mb_strtolower(trim($meeting->getDayOfWeek()));
    }

    /**
     * Normalise a time string to HH:MM for consistent comparison.
     */
    private function normaliseTime(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '';
        }

        if (str_contains($time, ':')) {
            $parts = explode(':', $time);
            return sprintf('%02d:%s', (int) $parts[0], $parts[1]);
        }

        return $time;
    }

    /**
     * Compute a 0–1 similarity score between two meeting names.
     *
     * After lowercasing, stripping non-alphanumeric characters, and removing
     * common stop-words, we measure the Jaccard overlap of the remaining
     * significant tokens.
     */
    private function nameSimilarity(string $a, string $b): float
    {
        $normalise = static function (string $s): string {
            $s = mb_strtolower(trim($s));
            $s = preg_replace('/[^a-z0-9 ]/', ' ', $s);
            return trim(preg_replace('/\s+/', ' ', $s));
        };

        $stopLookup = array_flip(self::STOP_WORDS);

        $aWords = array_flip(explode(' ', $normalise($a)));
        $bWords = array_flip(explode(' ', $normalise($b)));

        $aSig = array_diff_key($aWords, $stopLookup);
        $bSig = array_diff_key($bWords, $stopLookup);

        if (empty($aSig) || empty($bSig)) {
            $overlap = count(array_intersect_key($aWords, $bWords));
            $total   = max(count($aWords), count($bWords), 1);
        } else {
            $overlap = count(array_intersect_key($aSig, $bSig));
            $total   = max(count($aSig), count($bSig), 1);
        }

        return $overlap / $total;
    }

    /**
     * Pull together a single normalised address string from a Unity Meeting.
     *
     * Combines the location's name and formatted address (when present).
     * Used as the source for postcode extraction and fuzzy town comparison.
     */
    private function extractLocalAddress(Meeting $meeting): string
    {
        $location = $meeting->getLocation();

        if ($location === null) {
            return '';
        }

        $parts = [];
        $name  = trim((string) $location->getName());
        $addr  = trim((string) $location->getFormattedAddress());

        if ($name !== '') {
            $parts[] = $name;
        }
        if ($addr !== '') {
            $parts[] = $addr;
        }

        return implode(', ', $parts);
    }

    /**
     * Read a field the AAGBDB API returns but GroupListing has no accessor for.
     *
     * GroupListing promotes only a subset of the response to named getters
     * (id, groupName, town, day, times, intergroup, lastUpdate) and keeps the
     * rest in its raw payload, reachable via getRawValue(). Meeting status,
     * postcode and the address lines are in that remainder — they are declared
     * GroupListing fields in Concordance's own whitelist
     * (ConcordanceConfiguration::DASHBOARD_FIELDS), just not promoted.
     *
     * This previously called getMeetingStatus() / getPostcode() /
     * getAddress1(), which have never existed on GroupListing and have no
     * __call to catch them, so every reconcile that found a national match
     * died with "Call to undefined method". The reconciler's own tests missed
     * it because they only ever supplied an empty national list, which never
     * reaches the match-row builder.
     */
    private function nationalField(GroupListing $national, string $key): string
    {
        return (string) $national->getRawValue($key, '');
    }

    private function nationalStatus(GroupListing $national): string
    {
        return $this->nationalField($national, 'meetingStatus');
    }

    private function nationalPostcode(GroupListing $national): string
    {
        return $this->nationalField($national, 'postcode');
    }

    /**
     * Format the national listing's address into a single display string.
     */
    private function formatNationalAddress(GroupListing $national): string
    {
        $parts = array_filter([
            $this->nationalField($national, 'address1'),
            $national->getTown(),
            $this->nationalPostcode($national),
        ], static fn(string $p): bool => $p !== '');

        return implode(', ', $parts);
    }

    /**
     * Compute a 0–1 address similarity score between a local meeting's
     * address blob and a national GroupListing.
     *
     * Postcode is the dominant signal:
     *   - exact full match            -> 1.0
     *   - same outward code (SL2 ...) -> 0.7
     * Town is a secondary signal:
     *   - exact (case-insensitive)    -> 0.6
     *   - substring containment       -> 0.4
     * If we can't extract any postcode AND no town match, the result is 0.0.
     */
    private function addressSimilarity(string $localAddress, GroupListing $national): float
    {
        $localAddr = mb_strtolower($localAddress);
        $nPostcode = mb_strtolower(trim($this->nationalPostcode($national)));
        $nTown     = mb_strtolower(trim($national->getTown()));

        // ── Postcode signal ─────────────────────────────────────────
        $postcodeScore = 0.0;
        if ($nPostcode !== '') {
            $localPostcodes = $this->extractPostcodes($localAddr);
            $nNorm          = $this->normalisePostcode($nPostcode);
            $nOutward       = $this->postcodeOutward($nNorm);

            foreach ($localPostcodes as $lpc) {
                if ($lpc === $nNorm) {
                    $postcodeScore = 1.0;
                    break;
                }
                if ($nOutward !== '' && $this->postcodeOutward($lpc) === $nOutward) {
                    $postcodeScore = max($postcodeScore, 0.7);
                }
            }
        }

        // ── Town signal ─────────────────────────────────────────────
        $townScore = 0.0;
        if ($nTown !== '' && $localAddr !== '') {
            // Token boundary check so "Slough" doesn't match inside "Sloughborough"
            if (preg_match('/\b' . preg_quote($nTown, '/') . '\b/u', $localAddr)) {
                $townScore = 0.6;
            } elseif (str_contains($localAddr, $nTown)) {
                $townScore = 0.4;
            }
        }

        return max($postcodeScore, $townScore);
    }

    /**
     * Extract zero or more UK-format postcodes from a free-text address.
     *
     * Returns each as a normalised "AB1 2CD" string (uppercase, single space).
     * Source: GOV.UK BS 7666 simplified pattern.
     *
     * @return string[]
     */
    private function extractPostcodes(string $haystack): array
    {
        $pattern = '/\b([A-Z]{1,2}\d[A-Z\d]?)\s*(\d[A-Z]{2})\b/i';
        if (!preg_match_all($pattern, $haystack, $m, PREG_SET_ORDER)) {
            return [];
        }

        return array_map(
            fn(array $hit): string => mb_strtoupper($hit[1] . ' ' . $hit[2]),
            $m
        );
    }

    /**
     * Normalise a known-good postcode string to "OUT IN" uppercase form.
     */
    private function normalisePostcode(string $postcode): string
    {
        $postcode = mb_strtoupper(trim($postcode));
        $postcode = preg_replace('/\s+/', ' ', $postcode);

        // If the value lacks a space and is long enough, insert one before the
        // last 3 characters (the inward code is always 3 chars).
        if (!str_contains($postcode, ' ') && strlen($postcode) >= 5) {
            $postcode = substr($postcode, 0, -3) . ' ' . substr($postcode, -3);
        }

        return $postcode;
    }

    /**
     * Return the outward (first-half) code from a normalised postcode,
     * e.g. "SL2 4HL" -> "SL2".
     */
    private function postcodeOutward(string $postcode): string
    {
        $parts = explode(' ', $postcode, 2);
        return $parts[0] ?? '';
    }

    /**
     * Whether the gap between two end-time strings exceeds the tolerance.
     *
     * Returns false when both are empty or when one side is empty (no signal).
     */
    private function endTimeDiscrepancy(string $localEnd, string $nationalEnd): bool
    {
        $local    = $this->normaliseTime($localEnd);
        $national = $this->normaliseTime($nationalEnd);

        if ($local === '' || $national === '') {
            return false;
        }
        if ($local === $national) {
            return false;
        }

        $lMinutes = $this->timeToMinutes($local);
        $nMinutes = $this->timeToMinutes($national);

        if ($lMinutes === null || $nMinutes === null) {
            return $local !== $national;
        }

        return abs($lMinutes - $nMinutes) >= self::END_TIME_TOLERANCE_MINUTES;
    }

    /**
     * Convert a normalised "HH:MM" time string to total minutes.
     * Returns null on malformed input.
     */
    private function timeToMinutes(string $time): ?int
    {
        if (!preg_match('/^(\d{1,2}):(\d{2})/', $time, $m)) {
            return null;
        }
        return ((int) $m[1]) * 60 + (int) $m[2];
    }

    /**
     * Whether the given national meetingStatus represents a currently-meeting group.
     */
    private function isOpenStatus(string $status): bool
    {
        return in_array(mb_strtolower(trim($status)), self::OPEN_STATUSES, true);
    }
}
