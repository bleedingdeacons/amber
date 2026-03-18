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
 */
class ReconciliationResult implements JsonSerializable
{
    /**
     * @param array $matches      Confident matches (day + start time + name similarity).
     * @param array $possibles    Possible matches (day + start time only, names diverge).
     * @param array $localOnly    Meetings present only in the local Unity data.
     * @param array $nationalOnly Groups present only in the national AAGBDB data.
     * @param array $summary      Aggregate counts and match-rate percentages.
     */
    public function __construct(
        private readonly array $matches,
        private readonly array $possibles,
        private readonly array $localOnly,
        private readonly array $nationalOnly,
        private readonly array $summary,
    ) {
    }

    /** @return array Confident matches (day + start time + name similarity). */
    public function getMatches(): array
    {
        return $this->matches;
    }

    /** @return array Possible matches (day + start time only, names diverge). */
    public function getPossibles(): array
    {
        return $this->possibles;
    }

    /** @return array Meetings present only in the local Unity data. */
    public function getLocalOnly(): array
    {
        return $this->localOnly;
    }

    /** @return array Groups present only in the national AAGBDB data. */
    public function getNationalOnly(): array
    {
        return $this->nationalOnly;
    }

    /** @return array Aggregate counts and match-rate percentages. */
    public function getSummary(): array
    {
        return $this->summary;
    }

    public function toArray(): array
    {
        return [
            'matches'        => $this->matches,
            'possibles'      => $this->possibles,
            'local_only'     => $this->localOnly,
            'national_only'  => $this->nationalOnly,
            'summary'        => $this->summary,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}