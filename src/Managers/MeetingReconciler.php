<?php

declare(strict_types=1);

namespace Amber\Managers;

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
 *  1. Confident match  — same day + same start time + name similarity >= threshold.
 *  2. Possible match   — same day + same start time, but names are too dissimilar.
 *  3. Local only       — present in Unity but unmatched nationally.
 *  4. National only    — present in AAGBDB but unmatched locally.
 */
class MeetingReconciler
{
    /** Minimum name-similarity score (0–1) for a confident match. */
    private const NAME_THRESHOLD = 0.3;

    /** Scores below this are flagged as "weak name match". */
    private const WEAK_NAME_THRESHOLD = 0.7;

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
        $localMatched    = [];
        $nationalMatched = [];

        // ── Pass 1: confident matches ───────────────────────────────────
        foreach ($localMeetings as $li => $local) {
            $bestScore = 0.0;
            $bestNi    = null;

            $lDay  = $this->normaliseDayFromMeeting($local);
            $lTime = $this->normaliseTime($local->getTime());

            foreach ($nationalGroups as $ni => $national) {
                if (isset($nationalMatched[$ni])) {
                    continue;
                }

                $nDay  = mb_strtolower(trim($national->getDay()));
                $nTime = $this->normaliseTime($national->getStartTime());

                if ($lDay !== $nDay || $lTime !== $nTime) {
                    continue;
                }

                $score = $this->nameSimilarity($local->getName(), $national->getGroupName());

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestNi    = $ni;
                }
            }

            if ($bestScore >= self::NAME_THRESHOLD && $bestNi !== null) {
                $national = $nationalGroups[$bestNi];
                $lEnd     = $this->normaliseTime($local->getEndTime());
                $nEnd     = $this->normaliseTime($national->getEndTime());
                $timeDiff = ($lEnd !== $nEnd);

                $notes = [];
                if ($timeDiff) {
                    $notes[] = 'End time mismatch';
                }
                if ($bestScore < self::WEAK_NAME_THRESHOLD) {
                    $notes[] = 'Weak name match';
                }

                $matches[] = [
                    'local_name'    => $local->getName(),
                    'local_id'      => $local->getId(),
                    'national_name' => $national->getGroupName(),
                    'national_id'   => $national->getId(),
                    'day'           => $local->getDayOfWeek(),
                    'start_time'    => $local->getTime(),
                    'local_end'     => $local->getEndTime(),
                    'national_end'  => $national->getEndTime(),
                    'score'         => round($bestScore, 2),
                    'end_time_diff' => $timeDiff,
                    'notes'         => $notes,
                ];

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
                        'local_name'    => $local->getName(),
                        'local_id'      => $local->getId(),
                        'national_name' => $national->getGroupName(),
                        'national_id'   => $national->getId(),
                        'day'           => $local->getDayOfWeek(),
                        'start_time'    => $local->getTime(),
                        'local_end'     => $local->getEndTime(),
                        'national_end'  => $national->getEndTime(),
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
            if (!isset($nationalMatched[$ni]) && !isset($nationalPossible[$ni])) {
                $nationalOnly[] = [
                    'id'         => $national->getId(),
                    'name'       => $national->getGroupName(),
                    'day'        => $national->getDay(),
                    'start_time' => $national->getStartTime(),
                    'end_time'   => $national->getEndTime(),
                    'town'       => $national->getTown(),
                    'reason'     => 'Missing from local list',
                ];
            }
        }

        // ── Summary ─────────────────────────────────────────────────────
        $localTotal    = count($localMeetings);
        $nationalTotal = count($nationalGroups);
        $endTimeDiffs  = count(array_filter($matches, static fn(array $m): bool => $m['end_time_diff']));
        $weakNames     = count(array_filter($matches, static fn(array $m): bool => $m['score'] < self::WEAK_NAME_THRESHOLD));

        $summary = [
            'local_total'            => $localTotal,
            'national_total'         => $nationalTotal,
            'confident_matches'      => count($matches),
            'end_time_discrepancies' => $endTimeDiffs,
            'weak_name_matches'      => $weakNames,
            'possible_matches'       => count($possibles),
            'local_only'             => count($localOnly),
            'national_only'          => count($nationalOnly),
            'local_match_pct'        => $localTotal > 0
                ? round(count($matches) / $localTotal * 100, 1)
                : 0.0,
            'national_match_pct'     => $nationalTotal > 0
                ? round(count($matches) / $nationalTotal * 100, 1)
                : 0.0,
        ];

        return new ReconciliationResult($matches, $possibles, $localOnly, $nationalOnly, $summary);
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
}