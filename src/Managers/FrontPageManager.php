<?php

declare(strict_types=1);

namespace Amber\Managers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Meetings\Interfaces\MeetingRepository;

/**
 * Class FrontPageManager
 *
 * Registers a shortcode to display list of today's meetings.
 */
class FrontPageManager
{
    private MeetingRepository $repository;

    /**
     * Constructor.
     * Registers the shortcode.
     *
     * @param MeetingRepository $repository Meeting repository
     */
    public function __construct(MeetingRepository $repository)
    {
        $this->repository = $repository;
        add_shortcode('todays_meetings', [$this, 'render']);
    }

    /**
     * Render today's meetings.
     *
     */
    public function render(): string
    {
        try {
            // Get current day (0 for Sunday, 1 for Monday, etc.)
            $current_day = intval(current_time('w'));
            $meetings = $this->repository->findByDay($current_day);

            // Normalise to an array so we can sort (repository may return a Traversable).
            if ($meetings instanceof \Traversable) {
                $meetings = iterator_to_array($meetings, false);
            } else {
                $meetings = is_array($meetings) ? array_values($meetings) : [];
            }

            // Sort by start time ascending. Times are zero-padded "HH:MM"
            // strings (see MeetingReconciler::normaliseTime), so a string
            // comparison gives correct chronological order.
            usort($meetings, static function (Meeting $a, Meeting $b): int {
                return strcmp((string) $a->getTime(), (string) $b->getTime());
            });

            $list = '';

            foreach ($meetings as $meeting) {
                $list .= '<li class="meeting">';
                $list .= '<div class="time">' . esc_html($meeting->getTime()) . ' - ';
                $list .= '<a href="' . esc_url($meeting->getUrl()) . '">' . esc_html($meeting->getName()) . '</a>';
                $list .= '</div>';
                $list .= '<div class="attendance-option">' . $this->renderAttendanceOption($meeting) . '</div>';
                $list .= '</li>';
            }

            if (empty($list)) {
                $list = '<li>No meetings scheduled for today.</li>';
            }

            return '<h1>Today\'s Meetings</h1><ul>' . $list . '</ul>';
        } catch (Exception $e) {
            \Trumpet\Plugin::logError('Error rendering todays_meetings shortcode: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return '<p>Sorry, an error occurred while retrieving today\'s meetings.</p>';
        }
    }

    /**
     * Render the attendance option cell for a meeting.
     *
     * Online meetings display the word "Online". In-person meetings display the
     * location name linked to its permalink when available; meetings with no
     * resolvable location render an empty string.
     *
     * @param Meeting $meeting Meeting to render attendance for.
     * @return string HTML fragment (safe to embed; values are escaped).
     */
    private function renderAttendanceOption(Meeting $meeting): string
    {
        if ($meeting->isOnline()) {
            return esc_html__('Online', 'trumpet');
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
