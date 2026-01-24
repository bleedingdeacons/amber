<?php

declare(strict_types=1);

namespace Amber\Admin\Groups;

use Unity\Groups\GroupFields;
use Unity\Groups\Interfaces\GroupRepositoryInterface;
use Unity\Groups\Interfaces\GroupViewFactoryInterface;
use Unity\Groups\Interfaces\GroupViewInterface;
use WP_Post;
use function add_action;
use function add_filter;
use function add_query_arg;
use function add_submenu_page;
use function check_admin_referer;
use function current_user_can;
use function delete_post_meta;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_edit_post_link;
use function is_admin;
use function update_post_meta;
use function wp_nonce_field;
use function wp_redirect;
use const DOING_AJAX;
use const DOING_AUTOSAVE;

/**
 * Group Admin
 * 
 * Adds custom columns to the admin table view for groups.
 */
class GroupAdmin
{
    private GroupViewFactoryInterface $groupViewFactory;
    private GroupRepositoryInterface $groupRepository;

    /**
     * Constructor
     * 
     * @param GroupViewFactoryInterface $groupViewFactory Group view factory
     * @param GroupRepositoryInterface $groupRepository Group repository
     */
    public function __construct(
        GroupViewFactoryInterface $groupViewFactory,
        GroupRepositoryInterface $groupRepository
    ) {
        $this->groupViewFactory = $groupViewFactory;
        $this->groupRepository = $groupRepository;

        add_filter('manage_' . GroupFields::GROUP_POST_TYPE . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . GroupFields::GROUP_POST_TYPE . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . GroupFields::GROUP_POST_TYPE . '_sortable_columns', [$this, 'makeColumnsSortable']);
        add_filter('pre_get_posts', [$this, 'handleCustomColumnSorting']);
        add_action('save_post_' . GroupFields::GROUP_POST_TYPE, [$this, 'updateGroupMetadataOnSave'], 10, 3);
        add_action('admin_head', [$this, 'addAdminColumnStyles']);
    }
    
    /**
     * Add admin menu items
     */
    public function addAdminMenus(): void
    {
        add_submenu_page(
            'tools.php',
            'Group Metadata Setup',
            'Group Metadata',
            'manage_options',
            'group-metadata-setup',
            [$this, 'renderMetadataSetupPage']
        );
        
        add_action('restrict_manage_posts', [$this, 'addRebuildMetadataButton']);
        add_action('admin_init', [$this, 'handleRebuildMetadataRequest']);
    }
    
    /**
     * Add a "Rebuild Metadata" button to the groups listing page
     */
    public function addRebuildMetadataButton(): void
    {
        global $typenow;
        
        if ($typenow !== GroupFields::GROUP_POST_TYPE) {
            return;
        }
        
        ?>
        <div style="float: right; margin-left: 8px;">
            <form method="post">
                <?php wp_nonce_field('rebuild_group_metadata_nonce', 'rebuild_group_metadata_nonce'); ?>
                <input type="submit" name="rebuild_group_metadata" class="button" 
                       value="Rebuild Group Metadata">
            </form>
        </div>
        <?php
    }
    
    /**
     * Handle the rebuild metadata request
     */
    public function handleRebuildMetadataRequest(): void
    {
        if (!isset($_POST['rebuild_group_metadata'])) {
            return;
        }
        
        if (!check_admin_referer('rebuild_group_metadata_nonce', 'rebuild_group_metadata_nonce')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $count = $this->setupAllGroupsMetadata();
        
        add_action('admin_notices', function() use ($count) {
            echo '<div class="notice notice-success is-dismissible"><p>' . 
                 sprintf('Successfully updated metadata for %d groups.', $count) . 
                 '</p></div>';
        });
        
        $redirect_url = add_query_arg([
            'post_type' => GroupFields::GROUP_POST_TYPE,
            'metadata_updated' => 1,
        ], admin_url('edit.php'));
        
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Render the metadata setup page
     */
    public function renderMetadataSetupPage(): void
    {
        $message = '';
        if (isset($_POST['setup_group_metadata']) && check_admin_referer('setup_group_metadata_nonce')) {
            $count = $this->setupAllGroupsMetadata();
            $message = sprintf(
                '<div class="notice notice-success"><p>Successfully updated metadata for %d groups.</p></div>',
                $count
            );
        }
        
        ?>
        <div class="wrap">
            <h1>Group Metadata Setup</h1>
            
            <?php echo $message; ?>
            
            <div class="card">
                <h2>Initialize Group Metadata</h2>
                <p>
                    This tool will set up all the necessary metadata for groups, enabling proper sorting and display 
                    in the groups admin table. Use this if you've just installed or updated the plugin, or if 
                    you notice issues with the sorting of groups.
                </p>
                <p>
                    <strong>Note:</strong> This process may take a moment depending on how many groups you have.
                </p>
                
                <form method="post">
                    <?php wp_nonce_field('setup_group_metadata_nonce'); ?>
                    <p>
                        <input type="submit" name="setup_group_metadata" class="button button-primary" 
                               value="Initialize Group Metadata">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Set up metadata for all groups
     * 
     * @return int Number of groups updated
     */
    public function setupAllGroupsMetadata(): int
    {
        $groups = $this->groupRepository->findAll();
        $count = 0;
        
        foreach ($groups as $group) {
            $groupId = $group->getId();
            $this->updateGroupMetadata($groupId);
            $count++;
        }
        
        return $count;
    }

    /**
     * Add custom columns to the groups admin table
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
                $newColumns['group_email'] = 'Email';
                $newColumns['meeting_count'] = 'Meeting Count';
                $newColumns['meetings'] = 'Meetings';
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
        $groupView = $this->groupViewFactory->createFrom($postId);
        
        if (!$groupView) {
            return;
        }
        
        switch ($columnName) {
            case 'group_email':
                $this->displayGroupEmail($groupView);
                break;
                
            case 'meeting_count':
                $this->displayMeetingCount($groupView);
                break;
                
            case 'meetings':
                $this->displayMeetings($groupView);
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
        $columns['group_email'] = 'group_email';
        $columns['meeting_count'] = 'meeting_count';
        return $columns;
    }

    /**
     * Display the group email address as a mailto link
     * 
     * @param GroupViewInterface $groupView Group view object
     */
    private function displayGroupEmail(GroupViewInterface $groupView): void
    {
        $email = $groupView->getEmail();
        
        if (empty($email)) {
            echo '-';
            return;
        }
        
        echo '<a href="mailto:' . esc_attr($email) . '">' . 
             esc_html($email) . '</a>';
    }

    /**
     * Display the count of meetings associated with the group
     * 
     * @param GroupViewInterface $groupView Group view object
     */
    private function displayMeetingCount(GroupViewInterface $groupView): void
    {
        $meetings = $groupView->getMeetings();
        $count = count($meetings);
        
        if ($count === 0) {
            echo '<span class="no-meetings">0</span>';
        } else {
            echo '<span class="has-meetings">' . esc_html($count) . '</span>';
        }
    }

    /**
     * Display the meetings associated with the group
     * 
     * @param GroupViewInterface $groupView Group view object
     */
    private function displayMeetings(GroupViewInterface $groupView): void
    {
        $meetings = $groupView->getMeetings();
        
        if (empty($meetings)) {
            echo '-';
            return;
        }
        
        $meetingLinks = [];
        
        foreach ($meetings as $meeting) {
            $meetingId = $meeting->getId();
            $meetingName = $meeting->getName();
            
            $editLink = get_edit_post_link($meetingId);
            
            if ($editLink) {
                $meetingLinks[] = '<a href="' . esc_url($editLink) . '">' . esc_html($meetingName) . '</a>';
            } else {
                $meetingLinks[] = esc_html($meetingName);
            }
        }
        
        $displayCount = min(count($meetingLinks), 3);
        $remainingCount = count($meetingLinks) - $displayCount;
        
        echo implode(', ', array_slice($meetingLinks, 0, $displayCount));
        
        if ($remainingCount > 0) {
            echo ' <span class="more-indicator">+' . esc_html($remainingCount) . ' more</span>';
        }
    }

    /**
     * Add custom styles for the admin columns
     */
    public function addAdminColumnStyles(): void
    {
        echo '<style>
        .has-meetings {
            font-weight: bold;
            color: #2e7d32;
        }
        .no-meetings {
            color: #f44336;
            font-weight: bold;
        }
        .more-indicator {
            color: #777;
            font-style: italic;
            font-size: 0.9em;
        }
    </style>';
    }

    /**
     * Handle custom column sorting
     * 
     * @param \WP_Query $query The WordPress query object
     * @return \WP_Query The modified query
     */
    public function handleCustomColumnSorting($query)
    {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== GroupFields::GROUP_POST_TYPE) {
            return $query;
        }
        
        $orderby = $query->get('orderby');
        
        switch ($orderby) {
            case 'group_email':
                $query->set('meta_key', '_group_email');
                $query->set('orderby', 'meta_value');
                break;
                
            case 'meeting_count':
                $query->set('meta_key', '_meeting_count');
                $query->set('orderby', 'meta_value_num');
                break;
        }
        
        return $query;
    }
    
    /**
     * Update group metadata when a group is saved
     * 
     * @param int $postId The group post ID
     * @param WP_Post $post The group post object
     * @param bool $update Whether this is an update or a new post
     */
    public function updateGroupMetadataOnSave(int $postId, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;
        
        $this->updateGroupMetadata($postId);
    }
    
    /**
     * Update all metadata for a group
     * 
     * @param int $groupId The group ID
     */
    public function updateGroupMetadata(int $groupId): void
    {
        $groupView = $this->groupViewFactory->createFrom($groupId);
        if (!$groupView) {
            return;
        }
        
        $this->updateEmailMetadata($groupId, $groupView);
        $this->updateMeetingMetadata($groupId, $groupView);
    }
    
    /**
     * Update email metadata for sortability
     * 
     * @param int $groupId The group ID
     * @param GroupViewInterface $groupView The group view object
     */
    private function updateEmailMetadata(int $groupId, GroupViewInterface $groupView): void
    {
        $email = $groupView->getEmail();
        
        if ($email) {
            update_post_meta($groupId, '_group_email', strtolower($email));
        } else {
            delete_post_meta($groupId, '_group_email');
        }
    }
    
    /**
     * Update meeting metadata for a group
     * 
     * @param int $groupId The group ID
     * @param GroupViewInterface $groupView The group view object
     */
    private function updateMeetingMetadata(int $groupId, GroupViewInterface $groupView): void
    {
        $meetings = $groupView->getMeetings();
        
        update_post_meta($groupId, '_meeting_count', count($meetings));
        
        $meetingIds = array_map(function($meeting) {
            return $meeting->getId();
        }, $meetings);
        
        update_post_meta($groupId, '_meeting_ids', $meetingIds);
    }
}
