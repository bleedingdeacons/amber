<?php

declare(strict_types=1);

namespace Amber\Admin\Positions;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionViewFactory;
use Unity\Positions\Interfaces\PositionView;

use WP_Post;
use WP_Query;
use function add_action;
use function add_filter;
use function delete_post_meta;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_edit_post_link;
use function get_field;
use function is_admin;
use function maybe_unserialize;
use function update_post_meta;
use const DOING_AJAX;
use const DOING_AUTOSAVE;

/**
 * Position Admin
 * 
 * Adds custom columns to the admin table view for positions.
 */
class PositionAdmin
{
    private PositionViewFactory $positionViewFactory;
    private PositionRepository $positionRepository;
    private readonly array $member_config;
    private readonly array $position_config;

    /**
     * Constructor
     * 
     * @param PositionViewFactory $positionViewFactory Position view factory
     * @param PositionRepository $positionRepository Position repository
     */
    public function __construct(
        Configuration $configuration,
        PositionViewFactory $positionViewFactory,
        PositionRepository $positionRepository
    ) {
        $this->positionViewFactory = $positionViewFactory;
        $this->positionRepository = $positionRepository;

        $this->member_config = $configuration->getConfig(Member::class);
        $this->position_config = $configuration->getConfig(Position::class);

        add_filter('manage_' . $this->position_config['POST_TYPE'] . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . $this->position_config['POST_TYPE'] . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . $this->position_config['POST_TYPE'] . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_filter('pre_get_posts', [$this, 'handleCustomColumnSorting']);
        add_action('pre_get_posts', [$this, 'extendSearch']);
        add_action('save_post_' . $this->position_config['POST_TYPE'], [$this, 'updatePositionMetadataOnSave'], 10, 3);
        add_action('save_post_' . $this->member_config['POST_TYPE'], [$this, 'updateMemberPositionMetadata'], 10, 3);
        add_action('admin_head', [$this, 'addAdminColumnStyles']);
    }
    
    /**
     * Set up metadata for all positions
     * 
     * @return int Number of positions updated
     */
    public function setupAllPositionsMetadata(): int
    {
        $positions = $this->positionRepository->findAll();
        $count = 0;
        
        foreach ($positions as $position) {
            $positionId = $position->getId();
            $this->updatePositionMetadata($positionId);
            $count++;
        }
        
        return $count;
    }

    /**
     * Add custom columns to the positions admin table
     * 
     * @param array $columns Current admin columns
     * @return array Modified admin columns
     */
    public function addCustomColumns(array $columns): array
    {
        $newColumns = [];
        
        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;
            
            if ($key === 'title') {
                $newColumns['position_email'] = 'Position Email';
                $newColumns['position_member'] = 'Current Member';
                $newColumns['rotation_status'] = 'Status';
                $newColumns['rotation_date'] = 'Rotation Date';
            }
        }
        
        return $newColumns;
    }
    
    /**
     * Populate the custom columns with data
     * 
     * @param string $columnName Name of the column
     * @param int $postId Post ID
     */
    public function populateCustomColumns(string $columnName, int $postId): void
    {
        $positionView = $this->positionViewFactory->createFrom($postId);
        
        if (!$positionView) {
            return;
        }
        
        switch ($columnName) {
            case 'position_member':
                $this->displayPositionMember($positionView);
                break;
                
            case 'position_email':
                $this->displayPositionEmail($positionView);
                break;

            case 'rotation_date':
                $this->displayRotationDate($positionView);
                break;
                
            case 'rotation_status':
                $this->displayRotationStatus($positionView);
                break;
        }
    }

    /**
     * Make certain columns sortable
     * 
     * @param array $columns Current sortable columns
     * @return array Modified sortable columns
     */
    public function makeColumnsSortable(array $columns): array
    {
        $columns['position_member'] = 'position_member';
        $columns['position_email'] = 'position_email';
        $columns['private_email'] = 'private_email';
        $columns['private_contact'] = 'private_contact';
        $columns['rotation_date'] = 'rotation_date';
        $columns['rotation_status'] = 'rotation_status';
        return $columns;
    }

    /**
     * Display the current member assigned to the position
     * 
     * @param PositionView $positionView Position view object
     */
    private function displayPositionMember(PositionView $positionView): void
    {
        $member = $positionView->getMember();
        
        if ($positionView->isVacant() || !$member) {
            echo '-';
            return;
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

    /**
     * Display the position email address as a mailto link
     * 
     * @param PositionView $positionView Position view object
     */
    private function displayPositionEmail(PositionView $positionView): void
    {
        $positionEmail = $positionView->getPositionEmail();
        
        if (empty($positionEmail)) {
            echo '-';
            return;
        }
        
        echo '<a href="mailto:' . esc_attr($positionEmail) . '">' . 
             esc_html($positionEmail) . '</a>';
    }

    /**
     * Display the private email for the position holder as a mailto link
     * 
     * @param PositionView $positionView Position view object
     */
    private function displayPrivateEmail(PositionView $positionView): void
    {
        if ($positionView->isVacant()) {
            echo '-';
            return;
        }
        
        $privateEmail = $positionView->getPrivateEmail();
        
        if (empty($privateEmail)) {
            echo '-';
            return;
        }
        
        echo '<a href="mailto:' . esc_attr($privateEmail) . '">' . 
             esc_html($privateEmail) . '</a>';
    }

    /**
     * Display the private mobile contact for the position holder as a tel link
     * 
     * @param PositionView $positionView Position view object
     */
    private function displayPrivateContact(PositionView $positionView): void
    {
        if ($positionView->isVacant()) {
            echo '-';
            return;
        }
        
        $privateContact = $positionView->getPrivateContact();
        
        if (empty($privateContact)) {
            echo '-';
            return;
        }
        
        echo '<a href="tel:' . esc_attr($privateContact) . '">' . 
             esc_html($privateContact) . '</a>';
    }

    /**
     * Display the rotation date for the position
     * 
     * @param PositionView $positionView Position view object
     */
    private function displayRotationDate(PositionView $positionView): void
    {
        $rotationDate = $positionView->getRotationDate();
        
        if (!$rotationDate) {
            echo '<em>Not set</em>';
            return;
        }
        
        echo esc_html(wp_date('d/m/Y', $rotationDate->getTimestamp()));
    }

    /**
     * Display the rotation status with color coding and icons
     * 
     * @param PositionView $positionView Position view object
     */
    private function displayRotationStatus(PositionView $positionView): void
    {
        if ($positionView->isVacant()) {
            echo '<span class="status-vacant"><span class="dashicons dashicons-warning"></span> Vacant Position</span>';
            return;
        }
        
        $rotationDate = $positionView->getRotationDate();
        if (!$rotationDate) {
            echo '<span class="status-unknown"><span class="dashicons dashicons-editor-help"></span> No Rotation Date</span>';
            return;
        }
        
        $months = $positionView->getMonthsUntilRotation();

        if ($months < 0) {
            $absMonths = abs($months);
            $monthText = $absMonths === 1 ? 'month' : 'months';
            echo '<span class="status-overdue"><span class="dashicons dashicons-no-alt"></span> Overdue by ' . 
                 esc_html($absMonths . ' ' . $monthText) . '</span>';
        } elseif ($months === 0) {
            echo '<span class="status-due"><span class="dashicons dashicons-clock"></span> Due Now</span>';
        } elseif ($months <= 3) {
            $monthText = $months === 1 ? 'month' : 'months';
            echo '<span class="status-soon"><span class="dashicons dashicons-flag"></span> Due in ' . 
                 esc_html($months . ' ' . $monthText) . '</span>';
        } else {
            $monthText = $months === 1 ? 'month' : 'months';
            echo '<span class="status-normal"><span class="dashicons dashicons-yes-alt"></span> ' . 
                 esc_html($months . ' ' . $monthText) . ' remaining</span>';
        }
    }

    /**
     * Add custom styles for the admin columns
     */
    public function addAdminColumnStyles(): void
    {
        echo '<style>
        .vacant-position { color: #f44336; font-weight: bold; }
        .status-normal { color: #2e7d32; display: flex; align-items: center; }
        .status-soon { color: #ff9800; font-weight: bold; display: flex; align-items: center; }
        .status-due { color: #f44336; font-weight: bold; display: flex; align-items: center; }
        .status-overdue { color: #f44336; font-weight: bold; display: flex; align-items: center; }
        .status-vacant { color: #f44336; font-weight: bold; display: flex; align-items: center; }
        .status-unknown { color: #777; font-style: italic; display: flex; align-items: center; }
        .dashicons { margin-right: 4px; font-size: 18px; height: 18px; width: 18px; }
    </style>';
    }

    /**
     * Handle custom column sorting
     * 
     * @param WP_Query $query The WordPress query object
     * @return WP_Query The modified query
     */
    public function handleCustomColumnSorting(WP_Query $query): WP_Query
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== $this->position_config['POST_TYPE']) {
            return $query;
        }
        
        $orderby = $query->get('orderby');
        
        switch ($orderby) {
            case 'position_member':
                $query->set('meta_key', '_position_member_name');
                $query->set('orderby', 'meta_value');
                break;
                
            case 'position_email':
                $query->set('meta_key', '_position_email');
                $query->set('orderby', 'meta_value');
                break;
                
            case 'private_email':
                $query->set('meta_key', '_member_private_email');
                $query->set('orderby', 'meta_value');
                break;
                
            case 'private_contact':
                $query->set('meta_key', '_member_private_contact');
                $query->set('orderby', 'meta_value');
                break;
                
            case 'rotation_date':
                $query->set('meta_key', '_rotation_date_sortable');
                $query->set('orderby', 'meta_value');
                break;
                
            case 'rotation_status':
                $query->set('meta_key', '_rotation_sort_key');
                $query->set('orderby', 'meta_value_num');
                $query->set('order', $query->get('order') ?: 'ASC');
                break;
        }
        
        return $query;
    }
    
    /**
     * Extend search to include current member name
     *
     * When searching in the positions admin list, this also matches
     * positions whose current member's name contains the search term
     * (stored in _position_member_name meta).
     *
     * @param WP_Query $query WordPress query object
     */
    public function extendSearch(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== $this->position_config['POST_TYPE']) {
            return;
        }

        $searchTerm = $query->get('s');
        if (empty($searchTerm)) {
            return;
        }

        global $wpdb;

        $like = '%' . $wpdb->esc_like($searchTerm) . '%';

        // Find position IDs where the current member name matches
        $memberMatchIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_position_member_name'
               AND meta_value LIKE %s
               AND meta_value != 'zzz_vacant'",
            $like
        ));

        if (empty($memberMatchIds)) {
            return;
        }

        // Also get positions matching by title (WordPress default)
        $titleMatchIds = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status IN ('publish', 'draft', 'pending', 'private')
               AND post_title LIKE %s",
            $this->position_config['POST_TYPE'],
            $like
        ));

        $allMatchIds = array_unique(array_merge(
            array_map('intval', $titleMatchIds),
            array_map('intval', $memberMatchIds)
        ));

        if (!empty($allMatchIds)) {
            $query->set('s', '');
            $query->set('post__in', $allMatchIds);
        }
    }

    /**
     * Update position metadata when a position is saved
     * 
     * @param int $postId The position post ID
     * @param WP_Post $post The position post object
     * @param bool $update Whether this is an update or a new post
     */
    public function updatePositionMetadataOnSave(int $postId, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        
        $this->updatePositionMetadata($postId);
    }
    
    /**
     * Update position metadata when a member is saved
     * 
     * @param int $postId The member post ID
     * @param WP_Post $post The member post object
     * @param bool $update Whether this is an update or a new post
     */
    public function updateMemberPositionMetadata(int $postId, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        $positionId = get_field($this->member_config['FIELD_INTERGROUP_POSITION'], $postId);
        
        if ($positionId) {
            if (is_string($positionId) && strpos($positionId, 'a:') === 0) {
                $positionId = maybe_unserialize($positionId);
            }
            
            if (is_array($positionId)) {
                foreach ($positionId as $pid) {
                    $this->updatePositionMetadata((int)$pid);
                }
            } else {
                $this->updatePositionMetadata((int)$positionId);
            }
        }
    }
    
    /**
     * Update all metadata for a position
     * 
     * @param int $positionId The position ID
     */
    public function updatePositionMetadata(int $positionId): void
    {
        $positionView = $this->positionViewFactory->createFrom($positionId);
        if (!$positionView) {
            return;
        }

        // Clear all computed sort keys first to prevent duplicate meta rows
        delete_post_meta($positionId, '_position_member_name');
        delete_post_meta($positionId, '_position_member_id');
        delete_post_meta($positionId, '_position_email');
        delete_post_meta($positionId, '_member_private_email');
        delete_post_meta($positionId, '_member_private_contact');
        delete_post_meta($positionId, '_rotation_status');
        delete_post_meta($positionId, '_rotation_sort_key');
        delete_post_meta($positionId, '_has_rotation_date');
        delete_post_meta($positionId, '_rotation_date_sortable');
        delete_post_meta($positionId, '_months_until_rotation');
        
        $this->updateMemberNameMetadata($positionId, $positionView);
        $this->updatePositionEmailMetadata($positionId, $positionView);
        $this->updateMemberContactMetadata($positionId, $positionView);
        $this->updateRotationStatusMetadata($positionId, $positionView);
    }
    
    /**
     * Update member name metadata for a position (for alphabetical sorting)
     * 
     * @param int $positionId The position ID
     * @param PositionView $positionView The position view object
     */
    private function updateMemberNameMetadata(int $positionId, PositionView $positionView): void
    {
        $member = $positionView->getMember();
        
        if ($positionView->isVacant() || !$member) {
            update_post_meta($positionId, '_position_member_name', 'zzz_vacant');
            delete_post_meta($positionId, '_position_member_id');
            return;
        }
        
        $memberName = $member->getAnonymousName();
        update_post_meta($positionId, '_position_member_name', strtolower($memberName));
        update_post_meta($positionId, '_position_member_id', $member->getId());
    }
    
    /**
     * Update position email metadata for sortability
     * 
     * @param int $positionId The position ID
     * @param PositionView $positionView The position view object
     */
    private function updatePositionEmailMetadata(int $positionId, PositionView $positionView): void
    {
        $positionEmail = $positionView->getPositionEmail();
        
        if ($positionEmail) {
            update_post_meta($positionId, '_position_email', strtolower($positionEmail));
        } else {
            delete_post_meta($positionId, '_position_email');
        }
    }
    
    /**
     * Update member contact metadata for a position (for email and phone sorting)
     * 
     * @param int $positionId The position ID
     * @param PositionView $positionView The position view object
     */
    private function updateMemberContactMetadata(int $positionId, PositionView $positionView): void
    {
        if ($positionView->isVacant()) {
            delete_post_meta($positionId, '_member_private_email');
            delete_post_meta($positionId, '_member_private_contact');
            return;
        }
        
        $privateEmail = $positionView->getPrivateEmail();
        $privateContact = $positionView->getPrivateContact();
        
        if ($privateEmail) {
            update_post_meta($positionId, '_member_private_email', strtolower($privateEmail));
        } else {
            delete_post_meta($positionId, '_member_private_email');
        }
        
        if ($privateContact) {
            $contactSortable = preg_replace('/[^0-9]/', '', $privateContact);
            update_post_meta($positionId, '_member_private_contact', $contactSortable);
        } else {
            delete_post_meta($positionId, '_member_private_contact');
        }
    }
    
    /**
     * Update rotation status metadata for a position
     * 
     * @param int $positionId The position ID
     * @param PositionView $positionView The position view object
     */
    private function updateRotationStatusMetadata(int $positionId, PositionView $positionView): void
    {
        if ($positionView->isVacant()) {
            update_post_meta($positionId, '_rotation_status', 'vacant');
            update_post_meta($positionId, '_rotation_sort_key', 0);
            delete_post_meta($positionId, '_has_rotation_date');
            delete_post_meta($positionId, '_rotation_date_sortable');
            delete_post_meta($positionId, '_months_until_rotation');
            return;
        }
        
        $rotationDate = $positionView->getRotationDate();
        if (!$rotationDate) {
            update_post_meta($positionId, '_rotation_status', 'unknown');
            update_post_meta($positionId, '_rotation_sort_key', 9999);
            delete_post_meta($positionId, '_has_rotation_date');
            delete_post_meta($positionId, '_rotation_date_sortable');
            delete_post_meta($positionId, '_months_until_rotation');
            return;
        }
        
        update_post_meta($positionId, '_has_rotation_date', '1');
        update_post_meta($positionId, '_rotation_date_sortable', $rotationDate->format('Y-m-d'));
        
        $months = $positionView->getMonthsUntilRotation();
        
        if ($months < 0) {
            update_post_meta($positionId, '_rotation_status', 'overdue');
            // Overdue: sort by how overdue (more overdue = lower number = higher in list)
            update_post_meta($positionId, '_rotation_sort_key', 1);
        } elseif ($months === 0) {
            update_post_meta($positionId, '_rotation_status', 'due');
            update_post_meta($positionId, '_rotation_sort_key', 2);
        } elseif ($months <= 3) {
            update_post_meta($positionId, '_rotation_status', 'soon');
            // Soon: 100 + months so 1 month = 101, 3 months = 103
            update_post_meta($positionId, '_rotation_sort_key', 100 + $months);
        } else {
            update_post_meta($positionId, '_rotation_status', 'normal');
            // Normal: 100 + months so 12 months = 112, 24 months = 124
            update_post_meta($positionId, '_rotation_sort_key', 100 + $months);
        }
        
        update_post_meta($positionId, '_months_until_rotation', $months);
    }
}
