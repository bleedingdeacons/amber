<?php

declare(strict_types=1);

namespace Amber\Admin\Positions;

use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionViewFactoryInterface;
use Unity\Positions\Interfaces\PositionViewInterface;
use function add_action;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function wp_add_dashboard_widget;

/**
 * Position Dashboard Widget
 * 
 * Adds a dashboard panel listing all positions and members with their positions.
 */
class PositionDashboard
{
    private PositionViewFactoryInterface $positionViewFactory;
    private PositionRepositoryInterface $positionRepository;

    /**
     * Constructor
     * 
     * @param PositionViewFactoryInterface $positionViewFactory Position view factory
     * @param PositionRepositoryInterface $positionRepository Position repository
     */
    public function __construct(
        PositionViewFactoryInterface $positionViewFactory,
        PositionRepositoryInterface $positionRepository
    ) {
        $this->positionViewFactory = $positionViewFactory;
        $this->positionRepository = $positionRepository;

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
            'position_members_dashboard',
            'Positions & Members',
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
        $positions = $this->positionRepository->findAll();
        
        if (empty($positions)) {
            echo '<p>No positions found.</p>';
            return;
        }

        // Create position views for all positions
        $positionViews = [];
        foreach ($positions as $position) {
            $positionView = $this->positionViewFactory->createFrom($position->getId());
            if ($positionView) {
                $positionViews[] = $positionView;
            }
        }

        // Sort by position title
        usort($positionViews, function($a, $b) {
            return strcasecmp($a->getTitle() ?? '', $b->getTitle() ?? '');
        });

        echo '<div class="position-dashboard-widget">';
        echo '<table class="widefat striped position-members-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>Position</th>';
        echo '<th>Current Member</th>';
        echo '<th>Position Email</th>';
        echo '<th>Status</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';

        foreach ($positionViews as $positionView) {
            $this->renderPositionRow($positionView);
        }

        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    /**
     * Render a single position row
     * 
     * @param PositionViewInterface $positionView Position view object
     */
    private function renderPositionRow(PositionViewInterface $positionView): void
    {
        $position = $positionView->getPosition();
        $positionId = $position->getId();
        $positionTitle = $positionView->getTitle() ?: 'Untitled Position';
        $positionEditLink = get_edit_post_link($positionId);
        
        echo '<tr>';
        
        // Position title column
        echo '<td class="position-title">';
        if ($positionEditLink) {
            echo '<a href="' . esc_url($positionEditLink) . '">';
            echo '<strong>' . esc_html($positionTitle) . '</strong>';
            echo '</a>';
        } else {
            echo '<strong>' . esc_html($positionTitle) . '</strong>';
        }
        echo '</td>';
        
        // Current member column
        echo '<td class="position-member">';
        $this->renderMemberCell($positionView);
        echo '</td>';
        
        // Position email column
        echo '<td class="position-email">';
        $this->renderPositionEmail($positionView);
        echo '</td>';
        
        // Status column
        echo '<td class="position-status">';
        $this->renderStatusBadge($positionView);
        echo '</td>';
        
        echo '</tr>';
    }

    /**
     * Render the member cell content
     * 
     * @param PositionViewInterface $positionView Position view object
     */
    private function renderMemberCell(PositionViewInterface $positionView): void
    {
        $member = $positionView->getMember();
        
        if ($positionView->isVacant() || !$member) {
            echo '<span class="vacant-indicator">Vacant</span>';
            return;
        }
        
        $memberId = $member->getId();
//        $displayName = $member->getPrivateName();
        $displayName = $member->getAnonymousName();
        $editLink = get_edit_post_link($memberId);
        
        if ($editLink) {
            echo '<a href="' . esc_url($editLink) . '">' . esc_html($displayName) . '</a>';
        } else {
            echo esc_html($displayName);
        }
        
        // Show private email if available
        $privateEmail = $positionView->getPrivateEmail();
        if (!empty($privateEmail)) {
            echo '<br><small>';
            echo '<a href="mailto:' . esc_attr($privateEmail) . '" class="member-email">';
            echo esc_html($privateEmail);
            echo '</a>';
            echo '</small>';
        }
    }

    /**
     * Render the position email
     * 
     * @param PositionViewInterface $positionView Position view object
     */
    private function renderPositionEmail(PositionViewInterface $positionView): void
    {
        $positionEmail = $positionView->getPositionEmail();
        
        if (empty($positionEmail)) {
            echo '<span class="no-email">—</span>';
            return;
        }
        
        echo '<a href="mailto:' . esc_attr($positionEmail) . '">' . 
             esc_html($positionEmail) . '</a>';
    }

    /**
     * Render the status badge
     * 
     * @param PositionViewInterface $positionView Position view object
     */
    private function renderStatusBadge(PositionViewInterface $positionView): void
    {
        if ($positionView->isVacant()) {
            echo '<span class="status-badge status-vacant">Vacant</span>';
            return;
        }
        
        $rotationDate = $positionView->getRotationDate();
        if (!$rotationDate) {
            echo '<span class="status-badge status-unknown">No Rotation</span>';
            return;
        }
        
        $months = $positionView->getMonthsUntilRotation();
        
        if ($months === null) {
            echo '<span class="status-badge status-unknown">Unknown</span>';
            return;
        }
        
        if ($months < 0) {
            $overdueDays = abs($positionView->getDaysUntilRotation() ?? 0);
            echo '<span class="status-badge status-overdue" title="Overdue by ' . $overdueDays . ' days">Overdue</span>';
        } elseif ($months === 0) {
            echo '<span class="status-badge status-due" title="Due this month">Due</span>';
        } elseif ($months <= 3) {
            echo '<span class="status-badge status-soon" title="' . $months . ' month(s) until rotation">Soon (' . $months . 'm)</span>';
        } else {
            $formattedDate = $rotationDate->format('M Y');
            echo '<span class="status-badge status-normal" title="Rotation date: ' . $formattedDate . '">Active</span>';
        }
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
            .position-dashboard-widget {
                margin: -12px -12px 0 -12px;
            }
            
            .position-members-table {
                margin: 0;
                border: none;
            }
            
            .position-members-table th {
                background: #f9f9f9;
                font-weight: 600;
                padding: 8px 10px;
            }
            
            .position-members-table td {
                padding: 10px;
                vertical-align: top;
            }
            
            .position-members-table .position-title {
                width: 25%;
            }
            
            .position-members-table .position-member {
                width: 30%;
            }
            
            .position-members-table .position-email {
                width: 25%;
            }
            
            .position-members-table .position-status {
                width: 20%;
                text-align: center;
            }
            
            .position-members-table .member-email {
                color: #666;
                text-decoration: none;
            }
            
            .position-members-table .member-email:hover {
                color: #2271b1;
                text-decoration: underline;
            }
            
            .vacant-indicator {
                color: #999;
                font-style: italic;
            }
            
            .no-email {
                color: #ccc;
            }
            
            .status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .status-badge.status-overdue {
                background: #dc3232;
                color: white;
            }
            
            .status-badge.status-due {
                background: #f56e28;
                color: white;
            }
            
            .status-badge.status-soon {
                background: #ffb900;
                color: #333;
            }
            
            .status-badge.status-normal {
                background: #46b450;
                color: white;
            }
            
            .status-badge.status-unknown {
                background: #ddd;
                color: #666;
            }
            
            .status-badge.status-vacant {
                background: #f0f0f1;
                color: #999;
                border: 1px dashed #ccc;
            }
            
            .position-members-table tr:hover {
                background: #f9f9f9;
            }
        </style>';
    }
}
