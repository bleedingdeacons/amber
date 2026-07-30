<?php

declare(strict_types=1);

namespace Amber\Models;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use JsonSerializable;

/**
 * Immutable value object representing the result of reconciling
 * local Unity meetings against national AAGBDB GroupListings.
 *
 * @phpstan-type ReconciliationArray array{
 *     matches: array<int, array<string, mixed>>,
 *     possibles: array<int, array<string, mixed>>,
 *     local_only: array<int, array<string, mixed>>,
 *     national_only: array<int, array<string, mixed>>,
 *     closed_matches: array<int, array<string, mixed>>,
 *     summary: array<string, mixed>
 * }
 */
class ReconciliationResult implements JsonSerializable
{
    /**
     * @param array<int, array<string, mixed>> $matches        Confident matches against open national listings.
     * @param array<int, array<string, mixed>> $possibles      Possible matches (day + start time only, names diverge).
     * @param array<int, array<string, mixed>> $localOnly      Meetings present only in the local Unity data.
     * @param array<int, array<string, mixed>> $nationalOnly   Open national groups unmatched locally.
     * @param array<string, mixed> $summary        Aggregate counts and match-rate percentages.
     * @param array<int, array<string, mixed>> $closedMatches  Confident matches whose national listing is
     *                              currently closed/suspended. Surfaced as a
     *                              distinct status so users can decide whether
     *                              to retire the local meeting.
     */
    public function __construct(
        private readonly array $matches,
        private readonly array $possibles,
        private readonly array $localOnly,
        private readonly array $nationalOnly,
        private readonly array $summary,
        private readonly array $closedMatches = [],
    ) {
    }

    /** @return array<int, array<string, mixed>> Confident matches against open national listings. */
    public function getMatches(): array
    {
        return $this->matches;
    }

    /** @return array<int, array<string, mixed>> Possible matches (day + start time only, names diverge). */
    public function getPossibles(): array
    {
        return $this->possibles;
    }

    /** @return array<int, array<string, mixed>> Meetings present only in the local Unity data. */
    public function getLocalOnly(): array
    {
        return $this->localOnly;
    }

    /** @return array<int, array<string, mixed>> Open national groups unmatched locally. */
    public function getNationalOnly(): array
    {
        return $this->nationalOnly;
    }

    /** @return array<string, mixed> Aggregate counts and match-rate percentages. */
    public function getSummary(): array
    {
        return $this->summary;
    }

    /** @return array<int, array<string, mixed>> Confident matches against closed/suspended national listings. */
    public function getClosedMatches(): array
    {
        return $this->closedMatches;
    }

    /**
     * @return ReconciliationArray
     */
    public function toArray(): array
    {
        return [
            'matches'        => $this->matches,
            'possibles'      => $this->possibles,
            'local_only'     => $this->localOnly,
            'national_only'  => $this->nationalOnly,
            'closed_matches' => $this->closedMatches,
            'summary'        => $this->summary,
        ];
    }

    /**
     * @return ReconciliationArray
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
