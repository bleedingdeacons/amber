<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Groups\Interfaces\GroupFactory;

use WP_Query;

use function add_action;
use function add_filter;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function is_admin;
use function sanitize_text_field;
use function wp_unslash;

/**
 * Member Admin Table Class
 *
 * Extends the WordPress admin table for Members post type
 * to include GSR status and service position fields
 */
class MemberAdmin
{
    private PositionFactory $positionFactory;
    private MemberRepository $memberRepository;
    private GroupFactory $groupFactory;
    private readonly array $member_config;
    private readonly array $position_config;
    private readonly array $group_config;

    /**
     * Initialize the admin table customizations
     *
     * @param PositionFactory $positionFactory
     * @param MemberRepository $memberRepository
     * @param GroupFactory $groupFactory
     */
    public function __construct(
        Configuration $configuration,
        PositionFactory $positionFactory,
        MemberRepository $memberRepository,
        GroupFactory $groupFactory
    ) {
        $this->positionFactory = $positionFactory;
        $this->memberRepository = $memberRepository;
        $this->groupFactory = $groupFactory;

        $this->member_config = $configuration->getConfig(Member::class);
        $this->position_config = $configuration->getConfig(Position::class);
        $this->group_config = $configuration->getConfig(Group::class);

        add_filter('manage_' . $this->member_config['POST_TYPE'] . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . $this->member_config['POST_TYPE'] . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . $this->member_config['POST_TYPE'] . '_sortable_columns', [$this, 'makeSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleCustomSorting']);

        // Extend search to include position name and home group name
        add_action('pre_get_posts', [$this, 'extendSearch']);

        // Add GSR filter dropdown
        add_action('restrict_manage_posts', [$this, 'addGsrFilterDropdown']);
        add_action('pre_get_posts', [$this, 'filterByGsrStatus']);
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

            if ($key === 'title') {
                $newColumns['anonymous_name'] = 'Anonymous Name';
                $newColumns['service_position'] = 'Service Position';
                $newColumns['gsr_status'] = 'Is GSR?';
                $newColumns['homegroup'] = 'Homegroup';
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
        $member = $this->memberRepository->find($postId);

        if (!$member) {
            echo '<span style="color: gray;">N/A</span>';
            return;
        }

        switch ($column) {
            case 'anonymous_name':
                echo esc_html($member->getAnonymousName() ?? '');
                break;

            case 'gsr_status':
                $isGSR = $member->isGsr();
                echo $isGSR ? '<span style="color: green;">✓ Yes</span>' : '<span style="color: gray;">✗ No</span>';
                break;

            case 'service_position':
                $positionId = $member->getIntergroupPosition();

                $position = $this->positionFactory->createFromSource($positionId);

                if ($position) {
                    $positionEditLink = get_edit_post_link($positionId);
                    if ($positionEditLink) {
                        echo '<a href="' . esc_url($positionEditLink) . '">' . esc_html($position->getLongName()) . '</a>';
                    } else {
                        echo esc_html($position->getLongName());
                    }
                } else {
                    echo '<span style="color: gray;">N/A</span>';
                }

                break;

            case 'homegroup':
                $homegroupId = $member->getHomeGroup();
                $homegroup = $this->groupFactory->createFromSource($homegroupId);

                if ($homegroup) {
                    echo esc_html($homegroup->getTitle());
                } else {
                    echo '<span style="color: gray;">N/A</span>';
                }

                break;
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
        $columns['anonymous_name'] = 'anonymous_name';
        $columns['gsr_status'] = 'gsr_status';
        $columns['service_position'] = 'service_position';
        $columns['homegroup'] = 'homegroup';

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
        if (!$screen || $screen->post_type !== $this->member_config['POST_TYPE']) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'anonymous_name':
                $query->set('meta_key', $this->member_config['FIELD_ANONYMOUS_NAME']);
                $query->set('orderby', 'meta_value');
                break;

            case 'gsr_status':
                $query->set('meta_key', $this->member_config['FIELD_HOMEGROUP_GSR']);
                $query->set('orderby', 'meta_value_num');
                break;

            case 'service_position':
                $query->set('meta_key', $this->member_config['FIELD_INTERGROUP_POSITION']);
                $query->set('orderby', 'meta_value_num');
                break;

            case 'homegroup':
                $query->set('meta_key', $this->member_config['FIELD_HOME_GROUP']);
                $query->set('orderby', 'meta_value_num');
                break;
        }
    }

    /**
     * Extend search to include position name and home group name
     *
     * When a search is performed on the member admin list, this finds
     * position and group posts whose titles (or meta values) match the
     * search term, resolves which members link to those positions/groups,
     * and merges those member IDs into the query results.
     *
     * @param WP_Query $query WordPress query object
     */
    public function extendSearch(WP_Query $query): void
    {
        $logFile = ABSPATH . 'member-search-debug.log';
        $log = function(string $msg) use ($logFile) {
            file_put_contents($logFile, date('H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
        };

        $log('extendSearch called');

        if (!is_admin()) {
            $log('bail: not admin');
            return;
        }

        if (!$query->is_main_query()) {
            return;
        }

        if (!$query->is_search()) {
            $log('bail: not search');
            return;
        }

        $screen = get_current_screen();
        $log('screen=' . ($screen ? $screen->post_type : 'NULL') . ' expected=' . $this->member_config['POST_TYPE']);

        if (!$screen || $screen->post_type !== $this->member_config['POST_TYPE']) {
            $log('bail: wrong screen');
            return;
        }

        $searchTerm = $query->get('s');
        $log('searchTerm=' . var_export($searchTerm, true));

        if (empty($searchTerm)) {
            $log('bail: empty search term');
            return;
        }

        $log('search started for: ' . $searchTerm);

        global $wpdb;

        $like = '%' . $wpdb->esc_like($searchTerm) . '%';

        // Find position post IDs whose title or meta values match the search term.
        // Position post_title holds the short description; meta values hold the long name.
        $positionIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND (p.post_title LIKE %s OR pm.meta_value LIKE %s)",
            $this->position_config['POST_TYPE'],
            $like,
            $like
        ));

        $log('Position POST_TYPE=' . $this->position_config['POST_TYPE'] . ' positionIds=' . wp_json_encode($positionIds));

        // Find group post IDs whose title matches the search term
        $groupIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ID FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status = 'publish'
               AND post_title LIKE %s",
            $this->group_config['POST_TYPE'],
            $like
        ));

        $log('Group POST_TYPE=' . $this->group_config['POST_TYPE'] . ' groupIds=' . wp_json_encode($groupIds));

        // Find member IDs linked to matching positions
        $memberIdsFromPositions = [];
        if (!empty($positionIds)) {
            $placeholders = implode(',', array_fill(0, count($positionIds), '%s'));
            $memberIdsFromPositions = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value IN ($placeholders)",
                array_merge(
                    [$this->member_config['FIELD_INTERGROUP_POSITION']],
                    $positionIds
                )
            ));
        }

        $log('FIELD_INTERGROUP_POSITION=' . $this->member_config['FIELD_INTERGROUP_POSITION'] . ' memberIdsFromPositions=' . wp_json_encode($memberIdsFromPositions));

        // Find member IDs linked to matching groups
        $memberIdsFromGroups = [];
        if (!empty($groupIds)) {
            $placeholders = implode(',', array_fill(0, count($groupIds), '%s'));
            $memberIdsFromGroups = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND meta_value IN ($placeholders)",
                array_merge(
                    [$this->member_config['FIELD_HOME_GROUP']],
                    $groupIds
                )
            ));
        }

        $log('FIELD_HOME_GROUP=' . $this->member_config['FIELD_HOME_GROUP'] . ' memberIdsFromGroups=' . wp_json_encode($memberIdsFromGroups));

        $extraMemberIds = array_unique(array_merge(
            array_map('intval', $memberIdsFromPositions),
            array_map('intval', $memberIdsFromGroups)
        ));

        $log('extraMemberIds=' . wp_json_encode($extraMemberIds));

        if (empty($extraMemberIds)) {
            $log('No extra member IDs found, returning early');
            return;
        }

        // Get member IDs that WordPress would normally find via title search
        $titleMatchIds = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status IN ('publish', 'draft', 'pending', 'private')
               AND post_title LIKE %s",
            $this->member_config['POST_TYPE'],
            $like
        ));

        $log('titleMatchIds=' . wp_json_encode($titleMatchIds));

        $allMatchIds = array_unique(array_merge(
            array_map('intval', $titleMatchIds),
            $extraMemberIds
        ));

        $log('allMatchIds=' . wp_json_encode($allMatchIds));

        if (!empty($allMatchIds)) {
            // Remove the default search and use post__in instead
            $query->set('s', '');
            $query->set('post__in', $allMatchIds);
            $log('Query modified: cleared s, set post__in');
        }
    }

    /**
     * Render the GSR status filter dropdown on the admin list table
     *
     * @param string $postType The current post type
     */
    public function addGsrFilterDropdown(string $postType): void
    {
        if ($postType !== $this->member_config['POST_TYPE']) {
            return;
        }

        $selected = isset($_GET['gsr_filter']) ? sanitize_text_field(wp_unslash($_GET['gsr_filter'])) : '';

        echo '<select name="gsr_filter">';
        echo '<option value="">' . esc_html('All GSR Status') . '</option>';
        echo '<option value="yes"' . ($selected === 'yes' ? ' selected="selected"' : '') . '>' . esc_html('Is GSR') . '</option>';
        echo '<option value="no"' . ($selected === 'no' ? ' selected="selected"' : '') . '>' . esc_html('Not GSR') . '</option>';
        echo '</select>';
    }

    /**
     * Filter the member list by GSR status when the dropdown is used
     *
     * @param WP_Query $query WordPress query object
     */
    public function filterByGsrStatus(WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== $this->member_config['POST_TYPE']) {
            return;
        }

        if (empty($_GET['gsr_filter'])) {
            return;
        }

        $gsrFilter = sanitize_text_field(wp_unslash($_GET['gsr_filter']));

        $metaQuery = $query->get('meta_query') ?: [];

        if ($gsrFilter === 'yes') {
            $metaQuery[] = [
                'key'     => $this->member_config['FIELD_HOMEGROUP_GSR'],
                'value'   => '1',
                'compare' => '=',
            ];
        } elseif ($gsrFilter === 'no') {
            $metaQuery[] = [
                'relation' => 'OR',
                [
                    'key'     => $this->member_config['FIELD_HOMEGROUP_GSR'],
                    'value'   => '1',
                    'compare' => '!=',
                ],
                [
                    'key'     => $this->member_config['FIELD_HOMEGROUP_GSR'],
                    'compare' => 'NOT EXISTS',
                ],
            ];
        }

        $query->set('meta_query', $metaQuery);
    }
}
