<?php

declare(strict_types=1);

namespace Amber\Admin\Meetings;

use Unity\Groups\Interfaces\GroupRepositoryInterface;
use WP_Query;
use function add_action;
use function add_filter;
use function esc_html;
use function get_current_screen;
use function get_post_meta;
use function is_admin;

/**
 * Meeting Admin Table Class
 *
 * Extends the WordPress admin table for tsml_meeting post type
 * to include the Group column
 */
class MeetingAdmin
{
    private const POST_TYPE = 'tsml_meeting';
    private const GROUP_META_KEY = 'group_id';

    private GroupRepositoryInterface $groupRepository;

    /**
     * Initialize the admin table customizations
     *
     * @param GroupRepositoryInterface $groupRepository
     */
    public function __construct(GroupRepositoryInterface $groupRepository)
    {
        $this->groupRepository = $groupRepository;

        // Register hooks immediately - unity_loaded fires during plugins_loaded,
        // which is before admin_init when the list table columns are set up
        $this->registerHooks();
    }

    /**
     * Register the admin hooks for column customization
     */
    public function registerHooks(): void
    {
        // TSML uses manage_edit-tsml_meeting_columns filter (not manage_tsml_meeting_posts_columns)
        add_filter('manage_edit-' . self::POST_TYPE . '_columns', [$this, 'addCustomColumns'], 99);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . self::POST_TYPE . '_sortable_columns', [$this, 'makeSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleCustomSorting']);

        // Ensure the group column is visible by default
        add_filter('default_hidden_columns', [$this, 'setDefaultHiddenColumns'], 10, 2);

        // Extend search to include group names
        add_filter('posts_join', [$this, 'searchJoin'], 10, 2);
        add_filter('posts_where', [$this, 'searchWhere'], 10, 2);
        add_filter('posts_distinct', [$this, 'searchDistinct'], 10, 2);
    }

    /**
     * Ensure the group column is not hidden by default
     *
     * @param array $hidden List of hidden column names
     * @param \WP_Screen $screen Current screen object
     * @return array Modified list of hidden columns
     */
    public function setDefaultHiddenColumns(array $hidden, \WP_Screen $screen): array
    {
        if ($screen->id === 'edit-' . self::POST_TYPE) {
            // Remove 'group' from hidden columns if present
            $hidden = array_diff($hidden, ['group']);
        }

        return $hidden;
    }

    /**
     * Add custom columns to the admin table
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public function addCustomColumns(array $columns): array
    {
        $newColumns = [];

        foreach ($columns as $key => $value) {
            $newColumns[$key] = $value;

            // Insert after 'title' (standard WP) or 'meeting' (TSML's name for the title column)
            if ($key === 'title' || $key === 'meeting') {
                $newColumns['group'] = __('Group', 'amber');
            }
        }

        return $newColumns;
    }

    /**
     * Populate content for the custom columns
     *
     * @param string $column Column name
     * @param int $postId Post ID
     */
    public function populateCustomColumns(string $column, int $postId): void
    {
        if ($column !== 'group') {
            return;
        }

        $groupId = get_post_meta($postId, self::GROUP_META_KEY, true);

        if (empty($groupId)) {
            echo '<span style="color: gray;">N/A</span>';
            return;
        }

        $group = $this->groupRepository->findById((int) $groupId);

        if ($group === null) {
            echo '<span style="color: gray;">N/A</span>';
            return;
        }

        // Try different methods to get the group name
        $name = null;
        if (method_exists($group, 'getName')) {
            $name = $group->getName();
        } elseif (method_exists($group, 'name')) {
            $name = $group->name();
        } elseif (method_exists($group, 'getTitle')) {
            $name = $group->getTitle();
        } elseif (method_exists($group, 'title')) {
            $name = $group->title();
        } elseif (property_exists($group, 'name')) {
            $name = $group->name;
        } elseif (property_exists($group, 'post_title')) {
            $name = $group->post_title;
        } elseif ($group instanceof \WP_Post) {
            $name = $group->post_title;
        }

        if ($name) {
            echo esc_html($name);
        } else {
            echo '<span style="color: gray;">N/A</span>';
        }
    }

    /**
     * Make columns sortable
     *
     * @param array $columns Sortable columns
     * @return array Modified sortable columns
     */
    public function makeSortableColumns(array $columns): array
    {
        $columns['group'] = 'group';

        return $columns;
    }

    /**
     * Handle custom sorting for the columns
     *
     * @param WP_Query $query WordPress query object
     */
    public function handleCustomSorting(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'group') {
            // Use a LEFT JOIN approach via meta_query to include posts without the meta key
            $query->set('meta_query', [
                'relation' => 'OR',
                [
                    'key' => self::GROUP_META_KEY,
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => self::GROUP_META_KEY,
                    'compare' => 'NOT EXISTS',
                ],
            ]);
            $query->set('orderby', 'meta_value_num');
        }
    }

    /**
     * Check if this is a search query for our post type
     *
     * @param WP_Query $query
     * @return bool
     */
    private function isSearchQuery(WP_Query $query): bool
    {
        if (!is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return false;
        }

        $screen = get_current_screen();
        return $screen && $screen->post_type === self::POST_TYPE;
    }

    /**
     * Join the posts table with postmeta and tsml_group posts for search
     *
     * @param string $join The JOIN clause
     * @param WP_Query $query The query object
     * @return string Modified JOIN clause
     */
    public function searchJoin(string $join, WP_Query $query): string
    {
        if (!$this->isSearchQuery($query)) {
            return $join;
        }

        global $wpdb;

        // Join to get the group_id from postmeta
        $join .= " LEFT JOIN {$wpdb->postmeta} AS group_meta ON ({$wpdb->posts}.ID = group_meta.post_id AND group_meta.meta_key = '" . self::GROUP_META_KEY . "')";

        // Join to get the group post (tsml_group) to search its title
        $join .= " LEFT JOIN {$wpdb->posts} AS group_post ON (group_meta.meta_value = group_post.ID AND group_post.post_type = 'tsml_group')";

        return $join;
    }

    /**
     * Modify the WHERE clause to include group name in search
     *
     * @param string $where The WHERE clause
     * @param WP_Query $query The query object
     * @return string Modified WHERE clause
     */
    public function searchWhere(string $where, WP_Query $query): string
    {
        if (!$this->isSearchQuery($query)) {
            return $where;
        }

        global $wpdb;

        $searchTerm = $query->get('s');
        if (empty($searchTerm)) {
            return $where;
        }

        // Escape the search term for LIKE
        $like = '%' . $wpdb->esc_like($searchTerm) . '%';

        // Add group post_title to the search
        // Find the existing search condition and extend it
        $where = preg_replace(
            "/\(\s*{$wpdb->posts}\.post_title\s+LIKE\s*('[^']+')(\s*\))/",
            "({$wpdb->posts}.post_title LIKE $1 OR group_post.post_title LIKE $1$2",
            $where
        );

        return $where;
    }

    /**
     * Ensure distinct results when joining tables
     *
     * @param string $distinct The DISTINCT clause
     * @param WP_Query $query The query object
     * @return string Modified DISTINCT clause
     */
    public function searchDistinct(string $distinct, WP_Query $query): string
    {
        if (!$this->isSearchQuery($query)) {
            return $distinct;
        }

        return 'DISTINCT';
    }
}