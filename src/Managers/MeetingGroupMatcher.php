<?php

declare(strict_types=1);

namespace Amber\Managers;

use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

use function is_wp_error;

/**
 * Class MeetingGroupMatcher
 *
 * Matches local Unity meetings against national AAGBDB group listings
 * using day and name as the primary matching criteria.
 *
 * Unlike MeetingReconciler (which also factors in start time), this
 * matcher focuses purely on day-of-week and group name similarity,
 * making it useful for cases where meeting times may differ between
 * the local schedule and the national register but the group identity
 * is the same.
 *
 * Each local meeting is paired with at most one national listing (the
 * best name match on the same day). Results are returned as a
 * MeetingGroupMatchResult value object.
 */
class MeetingGroupMatcher
{
    /** Minimum name-similarity score (0–1) for a confident match. */
    private const NAME