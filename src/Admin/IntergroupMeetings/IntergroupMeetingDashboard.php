<?php

declare(strict_types=1);

namespace Amber\Admin\IntergroupMeetings;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
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
 * sorted by date (most recent first). Reads from the attendance tables
 * so the widget reflects the archived registration data.
 */
class IntergroupMeetingDashboard
{
    private IntergroupMeetingRepository $intergroupMeetingRepository;
    private IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository;
    private IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository;
    private MemberRepository $memberRepository;

    /**
     * Constructor
     *
     * @param IntergroupMeetingRepository $intergroupMeetingRepository Intergroup meeting repository
     * @param IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository Group attendance repository
     * @param IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository Officer attendance repository
     * @param MemberRepository $memberRepository Member repository
     */
    public function __construct(
        IntergroupMeetingRepository $intergroupMeetingRepository,
        IntergroupMeetingGroupAttendanceRepository $groupAttendanceRepository,
        IntergroupMeetingOfficerAttendanceRepository $officerAttendanceRepository,
        MemberRepository $memberRepository
    ) {
        $this->intergroupMeetingRepository = $intergroupMeetingRepository;
        $this->groupAttendanceRepository = $groupAttendanceRepository;
        $this->officerAttendanceRepository = $officerAttendanceRepository;
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

        // Header with title, date and total count
        echo '<div class="ig-meeting-header">';

        // Title and date
        echo '<div class="ig-meeting-date">';
        $title = $meeting->getTitle();
        $date = $meeting->getDate();

        $formattedDate = '';
        if (!empty($date)) {
            $timestamp = strtotime($date);
            $formattedDate = $timestamp !== false ? wp_date('F j, Y', $timestamp) : $date;
        }

        if (!empty($title) && !empty($formattedDate)) {
            $label = $title . ' — ' . $formattedDate;
        } elseif (!empty($title)) {
            $label = $title;
        } elseif (!empty($formattedDate)) {
            $label = $formattedDate;
        } else {
            $label = '';
        }

        if (!empty($label)) {
            if ($editLink) {
                echo '<a href="' . esc_url($editLink) . '"><strong>' . esc_html($label) . '</strong></a>';
            } else {
                echo '<strong>' . esc_html($label) . '</strong>';
            }
        } else {
            echo '<span class="no-date">No Title or Date</span>';
        }
        echo '</div>';

        // Total count badge
        $groupCount = count($meeting->getGroupAttendees());
        $officerCount = count($meeting->getOfficersAttending());
        $total = $groupCount + $officerCount;

        echo '<div class="ig-meeting-total">';
        echo '<span class="attendee-badge" title="' . esc_html($groupCount . ' groups, ' . $officerCount . ' officers') . '">';
        echo '<span class="badge-number">' . esc_html((string)$total) . '</span>';
        echo '<span class="badge-label">eligible</span>';
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
     * Render the group attendees cell content from the attendance table
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function renderGroupAttendees(IntergroupMeeting $meeting): void
    {
        $records = $this->groupAttendanceRepository->findByIntergroupMeeting($meeting->getId());

        if (empty($records)) {
            echo '<span style="color: gray;">—</span>';
            return;
        }

        $allMembers = $this->memberRepository->findAll();
        $membersByName = [];
        foreach ($allMembers as $member) {
            $name = $member->getAnonymousName();
            if (!empty($name)) {
                $membersByName[$name] = $member;
            }
        }

        $entries = [];
        foreach ($records as $record) {
            $groupName = esc_html($record->getMeetingGroup());
            $gsrNameStr = $record->getGsrName();

            if (!empty($gsrNameStr)) {
                $names = array_map('trim', explode(',', $gsrNameStr));
                $gsrLinks = [];
                foreach ($names as $name) {
                    if (isset($membersByName[$name])) {
                        $editLink = get_edit_post_link($membersByName[$name]->getId());
                        $gsrLinks[] = $editLink
                            ? '<a href="' . esc_url($editLink) . '">' . esc_html($name) . '</a>'
                            : esc_html($name);
                    } else {
                        $gsrLinks[] = esc_html($name);
                    }
                }
                $groupName .= ' (' . implode(', ', $gsrLinks) . ')';
            }

            $entries[] = $groupName;
        }

        echo implode(', ', $entries);
    }

    /**
     * Render the officers cell content from the attendance table
     *
     * @param IntergroupMeeting $meeting Intergroup meeting object
     */
    private function renderOfficers(IntergroupMeeting $meeting): void
    {
        $records = $this->officerAttendanceRepository->findByIntergroupMeeting($meeting->getId());

        if (empty($records)) {
            echo '<span class="no-attendees">None</span>';
            return;
        }

        $allMembers = $this->memberRepository->findAll();
        $membersByName = [];
        foreach ($allMembers as $member) {
            $name = $member->getAnonymousName();
            if (!empty($name)) {
                $membersByName[$name] = $member;
            }
        }

        $entries = [];
        foreach ($records as $record) {
            $positionLabel = esc_html($record->getPositionName());
            $officerNameStr = $record->getOfficerName();

            if (!empty($officerNameStr)) {
                $names = array_map('trim', explode(',', $officerNameStr));
                $memberLinks = [];
                foreach ($names as $name) {
                    if (isset($membersByName[$name])) {
                        $editLink = get_edit_post_link($membersByName[$name]->getId());
                        $memberLinks[] = $editLink
                            ? '<a href="' . esc_url($editLink) . '">' . esc_html($name) . '</a>'
                            : esc_html($name);
                    } else {
                        $memberLinks[] = esc_html($name);
                    }
                }
                $entries[] = $positionLabel . ' (' . implode(', ', $memberLinks) . ')';
            } else {
                $entries[] = $positionLabel;
            }
        }

        if (empty($entries)) {
            echo '<span class="no-attendees">None</span>';
            return;
        }

        echo '<div class="attendee-list">' . implode(', ', $entries) . '</div>';
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
                margin: -6px -12px -12px -12px;
            }
            
            .ig-meeting-card {
                border-bottom: 1px solid #e0e0e0;
                padding: 0;
            }
            
            .ig-meeting-card:last-child {
                border-bottom: none;
            }
            
            .ig-meeting-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 12px;
                background: #f9f9f9;
                border-bottom: 1px solid #e0e0e0;
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
                padding: 12px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
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
                margin-bottom: 6px;
            }
            
            .ig-section-content {
                font-size: 13px;
                line-height: 1.6;
                word-wrap: break-word;
            }
            
            .ig-section-content a {
                color: #2271b1;
                text-decoration: none;
            }
            
            .ig-section-content a:hover {
                color: #135e96;
                text-decoration: underline;
            }
            
            .attendee-list {
                color: #333;
            }
            
            .no-attendees {
                color: #999;
                font-style: italic;
            }
        </style>';
    }
}