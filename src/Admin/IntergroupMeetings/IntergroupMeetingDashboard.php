<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Members\Interfaces\MemberRepository;

use function add_action;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function wp_add_dashboard_widget;

/**
 * Intergroup Meeting Dashboard Widget
 *
 * Adds a dashboard panel listing intergroup meetings with their attendees,
 * sorted by date (most recent first).
 */
class IntergroupMeetingDashboard
{
    private IntergroupMeetingRepository $intergroupMeetingRepository;
    private GroupRepository $groupRepository;
    private MemberRepository $memberRepository;

    /**
     * Constructor
     *
     * @param IntergroupMeetingRepository $intergroupMeetingRepository Intergroup meeting repository
     * @param GroupRepository $groupRepository Group repository
     * @param MemberRepository $memberRepository Member repository
     */
    public function __construct(
        IntergroupMeetingRepository $intergroupMeetingRepository,
        GroupRepository $groupRepository,
        MemberRepository $memberRepository
    ) {
        $this->intergroupMeetingRepository = $intergroupMeetingRepository;
        $this->groupRepository = $groupRepository;
        $this->memberRepository = $memberRepository;

        // Register hooks
        add_action('wp_dashboard_setup', [$this, 'registerDashboardWidget']);
        add_action('admin_head', [$this, 'addDashboardStyles']);
    }

    /**
     * Register the dashboard widget
     */
    public function registerDashboardWidget(): void
    {
        wp_add_dashboard_widget(
            'intergroup_meetings_dashboard',
            'Intergroup Meetings',
            [$this, 'renderDashboardWidget'],
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render the dashboard widget content
     */
    public function renderDashboardWidget(): void
    {
        $meetings = $this->intergroupMeetingRepository->findAll();

        if (empty($meetings)) {
            echo '<p>No intergroup meetings found.</p>';
            return;
        }

        // Sort by date descending (most recent first)
        usort($meetings, function (IntergroupMeeting $a, IntergroupMeeting $b) {
            $dateA = $a->getDate();
            $dateB = $b->getDate();

            if (empty($dateA) && empty($dateB)) return 0;
            if (empty($dateA)) return 1;
            if (empty($dateB)) return -1;

            return strcmp($dateB, $dateA);
        });

        echo '<div class="intergroup-meeting-dashboard-widget">';

        foreach ($meetings as $meeting) {
            $this->renderMeetingCard($meeting);
        }

        echo '</div>';
    }

    /**
     * Render a single intergroup meeting card
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function renderMeetingCard(IntergroupMeeting $meeting): void
    {
        $meetingId = $meeting->getId();
        $editLink = get_edit_post_link($meetingId);

        echo '<div class="ig-meeting-card">';

        // Header with date and total count
        echo '<div class="ig-meeting-header">';

        // Date
        echo '<div class="ig-meeting-date">';
        $date = $meeting->getDate();
        if (!empty($date)) {
            $timestamp = strtotime($date);
            $formattedDate = $timestamp !== false ? date('M j, Y', $timestamp) : $date;

            if ($editLink) {
                echo '<a href="' . esc_url($editLink) . '"><strong>' . esc_html($formattedDate) . '</strong></a>';
            } else {
                echo '<strong>' . esc_html($formattedDate) . '</strong>';
            }
        } else {
            echo '<span class="no-date">No Date</span>';
        }
        echo '</div>';

        // Total count badge
        $groupCount = count($meeting->getGroupAttendees());
        $officerCount = count($meeting->getOfficersAttending());
        $total = $groupCount + $officerCount;

        echo '<div class="ig-meeting-total">';
        echo '<span class="attendee-badge" title="' . esc_html($groupCount . ' groups, ' . $officerCount . ' officers') . '">';
        echo '<span class="badge-number">' . esc_html((string)$total) . '</span>';
        echo '<span class="badge-label">attendees</span>';
        echo '</span>';
        echo '</div>';

        echo '</div>'; // .ig-meeting-header

        // Content with groups and officers
        echo '<div class="ig-meeting-content">';

        // Groups attending
        echo '<div class="ig-meeting-section">';
        echo '<div class="ig-section-label">Groups</div>';
        echo '<div class="ig-section-content">';
        $this->renderGroupAttendees($meeting);
        echo '</div>';
        echo '</div>';

        // Officers attending
        echo '<div class="ig-meeting-section">';
        echo '<div class="ig-section-label">Officers</div>';
        echo '<div class="ig-section-content">';
        $this->renderOfficers($meeting);
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .ig-meeting-content

        echo '</div>'; // .ig-meeting-card
    }

    /**
     * Render the group attendees cell content
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function renderGroupAttendees(IntergroupMeeting $meeting): void
    {
        $attendeeIds = $meeting->getGroupAttendees();

        if (empty($attendeeIds)) {
            echo '<span class="no-attendees">None</span>';
            return;
        }

        $names = [];
        foreach ($attendeeIds as $id) {
            $group = $this->groupRepository->findById($id);
            if ($group) {
                $editLink = get_edit_post_link($id);
                if ($editLink) {
                    $names[] = '<a href="' . esc_url($editLink) . '">' . esc_html($group->getTitle()) . '</a>';
                } else {
                    $names[] = esc_html($group->getTitle());
                }
            }
        }

        if (empty($names)) {
            echo '<span class="no-attendees">None</span>';
            return;
        }

        echo '<div class="attendee-list">' . implode(', ', $names) . '</div>';
    }

    /**
     * Render the officers cell content
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function renderOfficers(IntergroupMeeting $meeting): void
    {
        $officerIds = $meeting->getOfficersAttending();

        if (empty($officerIds)) {
            echo '<span class="no-attendees">None</span>';
            return;
        }

        $names = [];
        foreach ($officerIds as $id) {
            $member = $this->memberRepository->find($id);
            if ($member) {
                $displayName = $member->getAnonymousName();
                $editLink = get_edit_post_link($id);
                if ($editLink) {
                    $names[] = '<a href="' . esc_url($editLink) . '">' . esc_html($displayName) . '</a>';
                } else {
                    $names[] = esc_html($displayName);
                }
            }
        }

        if (empty($names)) {
            echo '<span class="no-attendees">None</span>';
            return;
        }

        echo '<div class="attendee-list">' . implode(', ', $names) . '</div>';
    }

    /**
     * Add custom styles for the dashboard widget
     */
    public function addDashboardStyles(): void
    {
        $screen = get_current_screen();

        // Only add styles on the dashboard page
        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        echo '<style>
            .intergroup-meeting-dashboard-widget {
                margin: -12px -12px 0 -12px;
            }
            
            .ig-meeting-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                margin: 12px;
                padding: 0;
                transition: box-shadow 0.2s;
            }
            
            .ig-meeting-card:hover {
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .ig-meeting-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: #f9f9f9;
                border-bottom: 1px solid #e0e0e0;
                border-radius: 4px 4px 0 0;
            }
            
            .ig-meeting-date {
                font-size: 14px;
            }
            
            .ig-meeting-date a {
                text-decoration: none;
                color: #2271b1;
            }
            
            .ig-meeting-date a:hover {
                color: #135e96;
            }
            
            .ig-meeting-date .no-date {
                color: #999;
                font-style: italic;
            }
            
            .ig-meeting-total {
                flex-shrink: 0;
            }
            
            .attendee-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #2271b1;
                color: #fff;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                cursor: help;
            }
            
            .badge-number {
                font-size: 16px;
            }
            
            .badge-label {
                font-weight: 400;
                opacity: 0.9;
            }
            
            .ig-meeting-content {
                padding: 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
            }
            
            @media (max-width: 600px) {
                .ig-meeting-content {
                    grid-template-columns: 1fr;
                }
            }
            
            .ig-meeting-section {
                min-width: 0;
            }
            
            .ig-section-label {
                font-size: 11px;
                text-transform: uppercase;
                color: #666;
                font-weight: 600;
                letter-spacing: 0.5px;
                margin-bottom: 8px;
            }
            
            .ig-section-content {
                font-size: 13px;
                line-height: 1.6;
                word-wrap: break-word;
            }
            
            .attendee-list {
                color: #333;
            }
            
            .attendee-list a {
                color: #2271b1;
                text-decoration: none;
            }
            
            .attendee-list a:hover {
                color: #135e96;
                text-decoration: underline;
            }
            
            .no-attendees {
                color: #999;
                font-style: italic;
            }
            
            /* First card special styling */
            .ig-meeting-card:first-child {
                margin-top: 0;
            }
        </style>';
    }
}