<?php

declare(strict_types=1);

namespace Amber\Admin\Positions;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionViewFactory;
use Unity\Positions\Interfaces\PositionView;
use function add_action;
use function admin_url;
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
    private PositionViewFactory $positionViewFactory;
    private PositionRepository $positionRepository;
    private readonly array $member_config;

    /**
     * Constructor
     *
     * @param PositionViewFactory $positionViewFactory Position view factory
     * @param PositionRepository $positionRepository Position repository
     * @param Configuration $configuration Amber configuration
     */
    public function __construct(
        Configuration $configuration,
        PositionViewFactory $positionViewFactory,
        PositionRepository $positionRepository
    ) {
        $this->positionViewFactory = $positionViewFactory;
        $this->positionRepository = $positionRepository;
        $this->member_config = $configuration->getConfig(Member::class);

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

        foreach ($positionViews as $positionView) {
            $this->renderPositionCard($positionView);
        }

        echo '</div>';
    }

    /**
     * Render a single position card
     *
     * @param PositionView $positionView Position view object
     */
    private function renderPositionCard(PositionView $positionView): void
    {
        $position = $positionView->getPosition();
        $positionId = $position->getId();
        $positionTitle = $positionView->getTitle() ?: 'Untitled Position';
        $positionEditLink = get_edit_post_link($positionId);

        echo '<div class="position-card">';

        // Header with position title and status badge
        echo '<div class="position-card-header">';

        // Position title
        echo '<div class="position-card-title">';
        if ($positionEditLink) {
            echo '<a href="' . esc_url($positionEditLink) . '">';
            echo '<strong>' . esc_html($positionTitle) . '</strong>';
            echo '</a>';
        } else {
            echo '<strong>' . esc_html($positionTitle) . '</strong>';
        }
        echo '</div>';

        // Status badge
        echo '<div class="position-card-status">';
        $this->renderStatusBadge($positionView);
        echo '</div>';

        echo '</div>'; // .position-card-header

        // Content area with grid layout
        echo '<div class="position-card-content">';

        // Member field
        echo '<div class="position-card-field">';
        echo '<div class="field-label">Current Member</div>';
        echo '<div class="field-value">';
        $this->renderMemberCell($positionView);
        echo '</div>';
        echo '</div>';

        // Email field
        echo '<div class="position-card-field">';
        echo '<div class="field-label">Position Email</div>';
        echo '<div class="field-value">';
        $this->renderPositionEmail($positionView);
        echo '</div>';
        echo '</div>';

        echo '</div>'; // .position-card-content

        echo '</div>'; // .position-card
    }

    /**
     * Render the member cell content
     *
     * @param PositionView $positionView Position view object
     */
    private function renderMemberCell(PositionView $positionView): void
    {
        $members = $positionView->getMembers();

        if ($positionView->isVacant() || empty($members)) {
            echo '<span class="vacant-indicator">Vacant</span>';
            $membersUrl = admin_url('edit.php?post_type=' . $this->member_config['POST_TYPE']);
            echo ' <a href="' . esc_url($membersUrl) . '" class="vacant-action-btn" title="View Members">..</a>';
            return;
        }

        foreach ($members as $index => $member) {
            if ($index > 0) {
                echo '<br>';
            }

            $memberId = $member->getId();
            $displayName = $member->getAnonymousName();
            $editLink = get_edit_post_link($memberId);

            if ($editLink) {
                echo '<a href="' . esc_url($editLink) . '">' . esc_html($displayName) . '</a>';
            } else {
                echo esc_html($displayName);
            }
        }

        // Show months until rotation (shared across members with same rotation date)
        $months = $positionView->getMonthsUntilRotation();
        if ($months !== null) {
            if ($months < 0) {
                $overdueMonths = abs($months);
                echo '<br><small class="member-rotation rotation-overdue">';
                echo 'Overdue ' . $overdueMonths . ' month' . ($overdueMonths !== 1 ? 's' : '');
                echo '</small>';
            } else {
                echo '<br><small class="member-rotation">';
                echo $months . ' month' . ($months !== 1 ? 's' : '') . ' until rotation';
                echo '</small>';
            }
        }
    }

    /**
     * Render the position email
     *
     * @param PositionView $positionView Position view object
     */
    private function renderPositionEmail(PositionView $positionView): void
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
     * @param PositionView $positionView Position view object
     */
    private function renderStatusBadge(PositionView $positionView): void
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
            echo '<span class="status-badge status-normal" title="Rotation date: ' . $formattedDate . '">Filled</span>';
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
            
            .position-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                margin: 0 0 12px 0;
                transition: box-shadow 0.2s;
            }
            
            .position-card:hover {
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .position-card:last-child {
                margin-bottom: 0;
            }
            
            .position-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 16px;
                background: #f9f9f9;
                border-bottom: 1px solid #e0e0e0;
                border-radius: 4px 4px 0 0;
                gap: 12px;
            }
            
            .position-card-title {
                flex: 1;
                min-width: 0;
                font-size: 14px;
            }
            
            .position-card-title a {
                text-decoration: none;
                color: #2271b1;
            }
            
            .position-card-title a:hover {
                color: #135e96;
            }
            
            .position-card-status {
                flex-shrink: 0;
            }
            
            .position-card-content {
                padding: 12px 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 16px;
            }
            
            @media (max-width: 600px) {
                .position-card-content {
                    grid-template-columns: 1fr;
                }
            }
            
            .position-card-field {
                min-width: 0;
            }
            
            .field-label {
                font-size: 10px;
                text-transform: uppercase;
                color: #666;
                font-weight: 600;
                letter-spacing: 0.5px;
                margin-bottom: 4px;
            }
            
            .field-value {
                font-size: 13px;
                line-height: 1.5;
                word-wrap: break-word;
            }
            
            .field-value a {
                color: #2271b1;
                text-decoration: none;
            }
            
            .field-value a:hover {
                color: #135e96;
                text-decoration: underline;
            }
            
            .member-rotation {
                color: #666;
                display: block;
                margin-top: 2px;
            }
            
            .rotation-overdue {
                color: #dc3232;
                font-weight: 600;
            }
            
            .vacant-indicator {
                color: #999;
                font-style: italic;
            }
            
            .vacant-action-btn {
                display: inline-block;
                margin-left: 6px;
                padding: 2px 8px;
                background: #f0f0f1;
                border: 1px solid #ccc;
                border-radius: 3px;
                color: #666;
                text-decoration: none;
                font-weight: 600;
                font-size: 11px;
                line-height: 18px;
                cursor: pointer;
                letter-spacing: 0.5px;
                vertical-align: baseline;
            }
            
            .vacant-action-btn:hover {
                background: #e0e0e0;
                border-color: #999;
                color: #333;
            }
            
            .no-email {
                color: #999;
                font-style: italic;
            }
            
            .status-badge {
                display: inline-block;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                white-space: nowrap;
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
        </style>';
    }
}