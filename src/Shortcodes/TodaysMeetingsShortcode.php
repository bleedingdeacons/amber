<?php

declare(strict_types=1);

namespace Amber\Shortcodes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Logger\HasLogger;
use Throwable;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

use function add_shortcode;
use function current_time;
use function esc_html;
use function esc_html__;
use function esc_url;
use function shortcode_exists;
use function sprintf;

/**
 * Registers the [todays_meetings] shortcode.
 *
 * Lists meetings for the current weekday, sorted by start time ascending.
 *
 * Migrated from the Trumpet plugin (Trumpet\FrontPage\FrontPageManager).
 * Two functional changes versus the Trumpet implementation:
 *   1. Meetings are explicitly sorted by start time ascending in the
 *      shortcode itself, so the order is correct regardless of how the
 *      underlying MeetingRepository::findByDay() returns its results.
 *   2. Registration is guarded by shortcode_exists() so that if Trumpet
 *      (or any other plugin) has already claimed the tag, this no-ops —
 *      matching the convention used by ShortcodeService for the other
 *      shortcodes Amber shares with Confur.
 */
class TodaysMeetingsShortcode
{
    use HasLogger;

    public const TAG = 'todays_meetings';

    private MeetingRepository $repository;

    public function __construct(MeetingRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Register the shortcode on the WordPress init hook. Safe to call
     * more than once: a second registration is a no-op because
     * shortcode_exists() will already report true.
     */
    public function register(): void
    {
        if (!shortcode_exists(self::TAG)) {
            add_shortcode(self::TAG, [$this, 'render']);
        }
    }

    /**
     * Render today's meetings, sorted by start time ascending.
     */
    public function render(): string
    {
        try {
            // 0 = Sunday, 1 = Monday, ..., 6 = Saturday (WordPress site time).
            $currentDay = (int) current_time('w');

            $meetings = $this->repository->findByDay($currentDay);
            $meetings = $this->sortByTimeAscending($meetings);

            $list = '';
            foreach ($meetings as $meeting) {
                $list .= '<li class="meeting">';
                $list .= '<div class="time">' . esc_html($meeting->getTime()) . ' - ';
                $list .= '<a href="' . esc_url($meeting->getUrl()) . '">'
                       . esc_html($meeting->getName())
                       . '</a>';
                $list .= '</div>';
                $list .= '<div class="attendance-option">'
                       . $this->renderAttendanceOption($meeting)
                       . '</div>';
                $list .= '</li>';
            }

            if ($list === '') {
                $list = '<li>' . esc_html__('No meetings scheduled for today.', 'amber') . '</li>';
            }

            return '<h1>' . esc_html__("Today's Meetings", 'amber') . '</h1><ul>' . $list . '</ul>';
        } catch (Throwable $e) {
            self::logError('Error rendering todays_meetings shortcode: ' . $e->getMessage(), [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString(),
            ]);
            return '<p>' . esc_html__("Sorry, an error occurred while retrieving today's meetings.", 'amber') . '</p>';
        }
    }

    /**
     * Sort meetings by start time ascending.
     *
     * TSML stores meeting times as zero-padded 24-hour HH:MM strings
     * (e.g. "07:30", "19:00"), so a lexicographic comparison is also a
     * correct chronological one — "07:30" < "10:00" < "19:00". strcmp()
     * is used explicitly so the intent is unambiguous and the result
     * does not depend on locale-aware comparison.
     *
     * Meetings with an empty time sort to the end of the list rather
     * than the top, which would otherwise happen because '' is less
     * than any non-empty string.
     *
     * @param array<int, Meeting> $meetings
     * @return array<int, Meeting>
     */
    private function sortByTimeAscending(array $meetings): array
    {
        usort($meetings, static function (Meeting $a, Meeting $b): int {
            $timeA = $a->getTime();
            $timeB = $b->getTime();

            if ($timeA === '' && $timeB === '') {
                return 0;
            }
            if ($timeA === '') {
                return 1;
            }
            if ($timeB === '') {
                return -1;
            }

            return strcmp($timeA, $timeB);
        });

        return $meetings;
    }

    /**
     * Render the attendance-option cell for a meeting.
     *
     * Online meetings display the word "Online". In-person meetings display
     * the location name linked to its permalink when available; meetings
     * with no resolvable location render an empty string.
     */
    private function renderAttendanceOption(Meeting $meeting): string
    {
        if ($meeting->isOnline()) {
            return esc_html__('Online', 'amber');
        }

        $location = $meeting->getLocation();
        if ($location === null) {
            return '';
        }

        $name = $location->getName();
        $link = $location->getLink();

        if ($link !== '') {
            return sprintf(
                '<a href="%s">%s</a>',
                esc_url($link),
                esc_html($name)
            );
        }

        return esc_html($name);
    }
}
