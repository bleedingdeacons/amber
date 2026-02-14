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
        echo '<table class="widefat striped intergroup-meeting-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Date</th>';
        echo '<th>Groups Attending</th>';
        echo '<th>Officers</th>';
        echo '<th>Total</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($meetings as $meeting) {
            $this->renderMeetingRow($meeting);
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Render a single intergroup meeting row
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function renderMeetingRow(IntergroupMeeting $meeting): void
    {
        $meetingId = $meeting->getId();
        $editLink = get_edit_post_link($meetingId);

        echo '<tr>';

        // Date column
        echo '<td class="ig-meeting-date">';
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
        echo '</td>';

        // Groups attending column
        echo '<td class="ig-meeting-groups">';
        $this->renderGroupAttendees($meeting);
        echo '</td>';

        // Officers column
        echo '<td class="ig-meeting-officers">';
        $this->renderOfficers($meeting);
        echo '</td>';

        // Total attendees column
        echo '<td class="ig-meeting-total">';
        $groupCount = count($meeting->getGroupAttendees());
        $officerCount = count($meeting->getOfficersAttending());
        $total = $groupCount + $officerCount;
        echo '<span class="attendee-count" title="' . esc_html($groupCount . ' groups, ' . $officerCount . ' officers') . '">';
        echo esc_html((string)$total);
        echo '</span>';
        echo '</td>';

        echo '</tr>';
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
            echo '<span class="no-attendees">—</span>';
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
            echo '<span class="no-attendees">—</span>';
            return;
        }

        // Show first 5, indicate if more
        $displayNames = array_slice($names, 0, 5);
        echo implode(', ', $displayNames);

        $remaining = count($names) - 5;
        if ($remaining > 0) {
            echo '<br><small class="more-attendees">+' . $remaining . ' more</small>';
        }
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
            echo '<span class="no-attendees">—</span>';
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
            echo '<span class="no-attendees">—</span>';
            return;
        }

        echo implode(', ', $names);
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
            
            .intergroup-meeting-table {
                margin: 0;
                border: none;
            }
            
            .intergroup-meeting-table th {
                background: #f9f9f9;
                font-weight: 600;
                padding: 8px 10px;
            }
            
            .intergroup-meeting-table td {
                padding: 8px 10px;
                vertical-align: top;
            }
            
            .intergroup-meeting-table .ig-meeting-date {
                width: 15%;
                white-space: nowrap;
            }
            
            .intergroup-meeting-table .ig-meeting-groups {
                width: 40%;
            }
            
            .intergroup-meeting-table .ig-meeting-officers {
                width: 30%;
            }
            
            .intergroup-meeting-table .ig-meeting-total {
                width: 15%;
                text-align: center;
            }
            
            .intergroup-meeting-table .no-date {
                color: #999;
                font-style: italic;
            }
            
            .intergroup-meeting-table .no-attendees {
                color: #ccc;
            }
            
            .intergroup-meeting-table .more-attendees {
                color: #666;
            }
            
            .intergroup-meeting-table .attendee-count {
                display: inline-block;
                padding: 2px 8px;
                background: #f0f0f1;
                border-radius: 3px;
                font-weight: 600;
            }
            
            .intergroup-meeting-table tr:hover {
                background: #f9f9f9;
            }
        </style>';
    }
}