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

use WP_Post;
use WP_Query;

use function add_action;
use function add_filter;
use function delete_post_meta;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function get_edit_post_link;
use function is_admin;
use function sanitize_text_field;
use function update_post_meta;
use function wp_unslash;
use const DOING_AJAX;
use const DOING_AUTOSAVE;

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

        // Update sort metadata on save
        add_action('save_post_' . $this->member_config['POST_TYPE'], [$this, 'updateMemberMetadataOnSave'], 10, 3);
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
        $member = $this->memberRepository->findById($postId);

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
                    // Groups have no editable admin page of their own,
                    // so link to the first meeting the group contains.
                    $meetings = $homegroup->getMeetings();
                    $firstMeeting = $meetings[0] ?? null;
                    $meetingEditLink = $firstMeeting ? get_edit_post_link($firstMeeting->getId()) : null;

                    if ($meetingEditLink) {
                        echo '<a href="' . esc_url($meetingEditLink) . '">' . esc_html($homegroup->getTitle()) . '</a>';
                    } else {
                        echo esc_html($homegroup->getTitle());
                    }
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
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== $this->member_config['POST_TYPE']) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'gsr_status':
                $query->set('meta_key', '_member_gsr_sort');
                $query->set('orderby', 'meta_value_num');
                break;

            case 'service_position':
                $query->set('meta_key', '_member_position_sort_name');
                $query->set('orderby', 'meta_value');
                break;

            case 'rotation_date':
                $query->set('meta_key', '_member_rotation_date_sort');
                $query->set('orderby', 'meta_value');
                break;

            case 'homegroup':
                $query->set('meta_key', '_member_homegroup_sort_name');
                $query->set('orderby', 'meta_value');
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

        // Do not log the raw term: admins routinely search by email/phone,
        // which would accumulate PII in debug.log. Log a length + short hash
        // so recurring searches can still be correlated across log lines.
        self::logDebug('extendSearch started', [
            'term_length' => strlen($searchTerm),
            'term_hash'   => substr(hash('sha256', $searchTerm), 0, 8),
        ]);

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

    /**
     * Set up metadata for all members
     *
     * @return int Number of members updated
     */
    public function setupAllMembersMetadata(): int
    {
        $members = $this->memberRepository->findAll();
        $count = 0;

        foreach ($members as $member) {
            $this->updateMemberMetadata($member->getId());
            $count++;
        }

        return $count;
    }

    /**
     * Update member metadata when a member is saved
     *
     * @param int $postId The member post ID
     * @param WP_Post $post The member post object
     * @param bool $update Whether this is an update or a new post
     */
    public function updateMemberMetadataOnSave(int $postId, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (defined('DOING_AJAX') && DOING_AJAX) return;

        $this->updateMemberMetadata($postId);
    }

    /**
     * Update all sort metadata for a member
     *
     * @param int $memberId The member post ID
     */
    public function updateMemberMetadata(int $memberId): void
    {
        $member = $this->memberRepository->findById($memberId);
        if (!$member) {
            return;
        }

        // Clear all computed sort keys first to prevent duplicate meta rows
        delete_post_meta($memberId, '_member_gsr_sort');
        delete_post_meta($memberId, '_member_position_sort_name');
        delete_post_meta($memberId, '_member_rotation_date_sort');
        delete_post_meta($memberId, '_member_homegroup_sort_name');

        $this->updateGsrSortMetadata($memberId, $member);
        $this->updatePositionSortMetadata($memberId, $member);
        $this->updateRotationDateSortMetadata($memberId, $member);
        $this->updateHomegroupSortMetadata($memberId, $member);
    }

    /**
     * Update GSR sort metadata
     *
     * @param int $memberId The member post ID
     * @param Member $member The member object
     */
    private function updateGsrSortMetadata(int $memberId, Member $member): void
    {
        $isGsr = $member->isGsr() ? 1 : 0;
        update_post_meta($memberId, '_member_gsr_sort', $isGsr);
    }

    /**
     * Update service position sort metadata
     *
     * @param int $memberId The member post ID
     * @param Member $member The member object
     */
    private function updatePositionSortMetadata(int $memberId, Member $member): void
    {
        $positionId = $member->getIntergroupPosition();
        $position = $this->positionFactory->createFromSource($positionId);

        if ($position) {
            update_post_meta($memberId, '_member_position_sort_name', strtolower($position->getLongName()));
        } else {
            update_post_meta($memberId, '_member_position_sort_name', 'zzz_none');
        }
    }

    /**
     * Update rotation date sort metadata
     *
     * @param int $memberId The member post ID
     * @param Member $member The member object
     */
    private function updateRotationDateSortMetadata(int $memberId, Member $member): void
    {
        $rotation = $member->getIntergroupPositionRotation();

        if (!empty($rotation)) {
            // Parse d/m/Y format to Y-m-d for proper sorting
            $date = \DateTime::createFromFormat('d/m/Y', $rotation);
            if ($date) {
                update_post_meta($memberId, '_member_rotation_date_sort', $date->format('Y-m-d'));
            } else {
                update_post_meta($memberId, '_member_rotation_date_sort', $rotation);
            }
        } else {
            update_post_meta($memberId, '_member_rotation_date_sort', 'zzz_none');
        }
    }

    /**
     * Update homegroup sort metadata
     *
     * @param int $memberId The member post ID
     * @param Member $member The member object
     */
    private function updateHomegroupSortMetadata(int $memberId, Member $member): void
    {
        $homegroupId = $member->getHomeGroup();
        $homegroup = $this->groupFactory->createFromSource($homegroupId);

        if ($homegroup) {
            update_post_meta($memberId, '_member_homegroup_sort_name', strtolower($homegroup->getTitle()));
        } else {
            update_post_meta($memberId, '_member_homegroup_sort_name', 'zzz_none');
        }
    }
}