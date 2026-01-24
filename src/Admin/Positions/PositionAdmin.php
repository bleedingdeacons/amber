<?php

declare(strict_types=1);

namespace Amber\Admin\Positions;

use Unity\Members\MemberConstants;
use Unity\Positions\Interfaces\PositionRepositoryInterface;
use Unity\Positions\Interfaces\PositionViewFactoryInterface;
use Unity\Positions\Interfaces\PositionViewInterface;
use Unity\Positions\PositionFields;
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

        add_filter('manage_' . PositionFields::POSITION_POST_TYPE . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . PositionFields::POSITION_POST_TYPE . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . PositionFields::POSITION_POST_TYPE . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_filter('pre_get_posts', [$this, 'handleCustomColumnSorting']);
        add_action('save_post_' . PositionFields::POSITION_POST_TYPE, [$this, 'updatePositionMetadataOnSave'], 10, 3);
        add_action('save_post_' . MemberConstants::MEMBER_POST_TYPE, [$this, 'updateMemberPositionMetadata'], 10, 3);
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
                $newColumns['position_member'] = 'Current Member';
                $newColumns['position_email'] = 'Position Email';
                $newColumns['private_email'] = 'Private Email';
                $newColumns['private_contact'] = 'Mobile Number';
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
                
            case 'private_email':
                $this->displayPrivateEmail($positionView);
                break;
                
            case 'private_contact':
                $this->displayPrivateContact($positionView);
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
     * @param PositionViewInterface $positionView Position view object
     */
    private function displayPositionMember(PositionViewInterface $positionView): void
    {
        $member = $positionView->getMember();
        
        if ($positionView->isVacant() || !$member) {
            echo '-';
            return;
        }
        
        $memberId = $member->getId();
        $displayName = $member->getPrivateName();
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
     * @param PositionViewInterface $positionView Position view object
     */
    private function displayPositionEmail(PositionViewInterface $positionView): void
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
     * @param PositionViewInterface $positionView Position view object
     */
    private function displayPrivateEmail(PositionViewInterface $positionView): void
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
     * @param PositionViewInterface $positionView Position view object
     */
    private function displayPrivateContact(PositionViewInterface $positionView): void
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
     * @param PositionViewInterface $positionView Position view object
     */
    private function displayRotationDate(PositionViewInterface $positionView): void
    {
        $rotationDate = $positionView->getRotationDate();
        
        if (!$rotationDate) {
            echo '<em>Not set</em>';
            return;
        }
        
        echo esc_html($rotationDate->format('d/m/Y'));
    }

    /**
     * Display the rotation status with color coding and icons
     * 
     * @param PositionViewInterface $positionView Position view object
     */
    private function displayRotationStatus(PositionViewInterface $positionView): void
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
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== PositionFields::POSITION_POST_TYPE) {
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
                $meta_query = [
                    'relation' => 'OR',
                    'overdue' => ['key' => '_rotation_status', 'value' => 'overdue', 'compare' => '='],
                    'due' => ['key' => '_rotation_status', 'value' => 'due', 'compare' => '='],
                    'soon' => ['key' => '_rotation_status', 'value' => 'soon', 'compare' => '='],
                    'normal' => ['key' => '_rotation_status', 'value' => 'normal', 'compare' => '='],
                    'unknown' => ['key' => '_rotation_status', 'value' => 'unknown', 'compare' => '='],
                    'vacant' => ['key' => '_rotation_status', 'value' => 'vacant', 'compare' => '='],
                ];
                
                $query->set('meta_query', $meta_query);
                $query->set('orderby', [
                    'overdue' => 'DESC', 'due' => 'DESC', 'soon' => 'DESC',
                    'normal' => 'DESC', 'unknown' => 'DESC', 'vacant' => 'DESC',
                ]);
                break;
        }
        
        return $query;
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
        
        $positionId = get_field(MemberConstants::FIELD_INTERGROUP_POSITION, $postId);
        
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
        
        $this->updateMemberNameMetadata($positionId, $positionView);
        $this->updatePositionEmailMetadata($positionId, $positionView);
        $this->updateMemberContactMetadata($positionId, $positionView);
        $this->updateRotationStatusMetadata($positionId, $positionView);
    }
    
    /**
     * Update member name metadata for a position (for alphabetical sorting)
     * 
     * @param int $positionId The position ID
     * @param PositionViewInterface $positionView The position view object
     */
    private function updateMemberNameMetadata(int $positionId, PositionViewInterface $positionView): void
    {
        $member = $positionView->getMember();
        
        if ($positionView->isVacant() || !$member) {
            update_post_meta($positionId, '_position_member_name', 'zzz_vacant');
            delete_post_meta($positionId, '_position_member_id');
            return;
        }
        
        $memberName = $member->getPrivateName();
        update_post_meta($positionId, '_position_member_name', strtolower($memberName));
        update_post_meta($positionId, '_position_member_id', $member->getId());
    }
    
    /**
     * Update position email metadata for sortability
     * 
     * @param int $positionId The position ID
     * @param PositionViewInterface $positionView The position view object
     */
    private function updatePositionEmailMetadata(int $positionId, PositionViewInterface $positionView): void
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
     * @param PositionViewInterface $positionView The position view object
     */
    private function updateMemberContactMetadata(int $positionId, PositionViewInterface $positionView): void
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
     * @param PositionViewInterface $positionView The position view object
     */
    private function updateRotationStatusMetadata(int $positionId, PositionViewInterface $positionView): void
    {
        if ($positionView->isVacant()) {
            update_post_meta($positionId, '_rotation_status', 'vacant');
            delete_post_meta($positionId, '_has_rotation_date');
            delete_post_meta($positionId, '_rotation_date_sortable');
            delete_post_meta($positionId, '_months_until_rotation');
            return;
        }
        
        $rotationDate = $positionView->getRotationDate();
        if (!$rotationDate) {
            update_post_meta($positionId, '_rotation_status', 'unknown');
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
        } elseif ($months === 0) {
            update_post_meta($positionId, '_rotation_status', 'due');
        } elseif ($months <= 3) {
            update_post_meta($positionId, '_rotation_status', 'soon');
        } else {
            update_post_meta($positionId, '_rotation_status', 'normal');
        }
        
        update_post_meta($positionId, '_months_until_rotation', $months);
    }
}
