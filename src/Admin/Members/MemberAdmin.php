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

use Amber\Logger\HasLogger;

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
    use HasLogger;

    protected static function logChannel(): string
    {
        return 'amber';
    }
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
            if ($key === 'title') {
                $newColumns[$key] = 'Anonymous Name';
                $newColumns['service_position'] = 'Service Position';
                $newColumns['rotation_date'] = 'Rotation Date';
                $newColumns['gsr_status'] = 'Is GSR?';
                $newColumns['homegroup'] = 'Homegroup';
            } else {
                $newColumns[$key] = $value;
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

            case 'rotation_date':
                $rotation = $member->getIntergroupPositionRotation();
                if (!empty($rotation)) {
                    echo esc_html($rotation);
                } else {
                    echo '<span style="color: gray;">—</span>';
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
        $columns['gsr_status'] = 'gsr_status';
        $columns['service_position'] = 'service_position';
        $columns['rotation_date'] = 'rotation_date';
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
            case 'gsr_status':
                $query->set('meta_query', [
                    'relation' => 'OR',
                    '_gsr_clause' => [
                        'key' => $this->member_config['FIELD_HOMEGROUP_GSR'],
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key' => $this->member_config['FIELD_HOMEGROUP_GSR'],
                        'compare' => 'NOT EXISTS',
                    ],
                ]);
                $query->set('orderby', '_gsr_clause');
                break;

            case 'service_position':
                $query->set('meta_query', [
                    'relation' => 'OR',
                    '_position_clause' => [
                        'key' => $this->member_config['FIELD_INTERGROUP_POSITION'],
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key' => $this->member_config['FIELD_INTERGROUP_POSITION'],
                        'compare' => 'NOT EXISTS',
                    ],
                ]);
                $query->set('orderby', '_position_clause');
                break;

            case 'rotation_date':
                $query->set('meta_query', [
                    'relation' => 'OR',
                    '_rotation_clause' => [
                        'key' => $this->member_config['FIELD_INTERGROUP_POSITION_ROTATION'],
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key' => $this->member_config['FIELD_INTERGROUP_POSITION_ROTATION'],
                        'compare' => 'NOT EXISTS',
                    ],
                ]);
                $query->set('orderby', '_rotation_clause');
                break;

            case 'homegroup':
                $query->set('meta_query', [
                    'relation' => 'OR',
                    '_homegroup_clause' => [
                        'key' => $this->member_config['FIELD_HOME_GROUP'],
                        'compare' => 'EXISTS',
                    ],
                    [
                        'key' => $this->member_config['FIELD_HOME_GROUP'],
                        'compare' => 'NOT EXISTS',
                    ],
                ]);
                $query->set('orderby', '_homegroup_clause');
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
        if (!is_admin() || !$query->is_main_query() || !$query->is_search()) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== $this->member_config['POST_TYPE']) {
            return;
        }

        $searchTerm = $query->get('s');
        if (empty($searchTerm)) {
            return;
        }

        self::logDebug('extendSearch started', ['term' => $searchTerm]);

        global $wpdb;

        $like = '%' . $wpdb->esc_like($searchTerm) . '%';

        // Find position post IDs whose long name matches the search term.
        // The column displays getLongName() which is stored in 'position-long-name' meta.
        $positionIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'position-long-name'
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               AND pm.meta_value LIKE %s",
            $this->position_config['POST_TYPE'],
            $like
        ));

        self::logDebug('Matching positions', [
            'POST_TYPE' => $this->position_config['POST_TYPE'],
            'positionIds' => $positionIds,
        ]);

        // Find group post IDs whose title matches the search term
        $groupIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT ID FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status = 'publish'
               AND post_title LIKE %s",
            $this->group_config['POST_TYPE'],
            $like
        ));

        self::logDebug('Matching groups', [
            'POST_TYPE' => $this->group_config['POST_TYPE'],
            'groupIds' => $groupIds,
        ]);

        // Find member IDs linked to matching positions.
        // ACF stores some values as plain IDs and others as serialized arrays,
        // so we check for both exact match and LIKE for serialized format.
        $memberIdsFromPositions = [];
        if (!empty($positionIds)) {
            $conditions = [];
            $params = [$this->member_config['FIELD_INTERGROUP_POSITION']];
            foreach ($positionIds as $pid) {
                $conditions[] = 'meta_value = %s';
                $params[] = $pid;
                // Match serialized: s:3:"838"; (ACF serialized array format)
                $conditions[] = 'meta_value LIKE %s';
                $params[] = '%"' . $pid . '"%';
            }
            $conditionSql = implode(' OR ', $conditions);
            $memberIdsFromPositions = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND ($conditionSql)",
                $params
            ));
        }

        self::logDebug('Members from positions', [
            'meta_key' => $this->member_config['FIELD_INTERGROUP_POSITION'],
            'memberIds' => $memberIdsFromPositions,
        ]);

        // Find member IDs linked to matching groups (same plain/serialized handling)
        $memberIdsFromGroups = [];
        if (!empty($groupIds)) {
            $conditions = [];
            $params = [$this->member_config['FIELD_HOME_GROUP']];
            foreach ($groupIds as $gid) {
                $conditions[] = 'meta_value = %s';
                $params[] = $gid;
                $conditions[] = 'meta_value LIKE %s';
                $params[] = '%"' . $gid . '"%';
            }
            $conditionSql = implode(' OR ', $conditions);
            $memberIdsFromGroups = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = %s AND ($conditionSql)",
                $params
            ));
        }

        self::logDebug('Members from groups', [
            'meta_key' => $this->member_config['FIELD_HOME_GROUP'],
            'memberIds' => $memberIdsFromGroups,
        ]);

        $extraMemberIds = array_unique(array_merge(
            array_map('intval', $memberIdsFromPositions),
            array_map('intval', $memberIdsFromGroups)
        ));

        if (empty($extraMemberIds)) {
            self::logDebug('No extra member IDs found, returning early');
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

        $allMatchIds = array_unique(array_merge(
            array_map('intval', $titleMatchIds),
            $extraMemberIds
        ));

        self::logDebug('Final results', [
            'titleMatchIds' => $titleMatchIds,
            'extraMemberIds' => $extraMemberIds,
            'allMatchIds' => $allMatchIds,
        ]);

        if (!empty($allMatchIds)) {
            $query->set('s', '');
            $query->set('post__in', $allMatchIds);
            self::logDebug('Query modified: cleared s, set post__in');
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
