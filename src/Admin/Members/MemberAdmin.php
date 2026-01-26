<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

use Unity\Members\Interfaces\MemberRepositoryInterface;
use Unity\Members\MemberConstants;
use Unity\Positions\Interfaces\PositionFactoryInterface;
use Unity\Groups\Interfaces\GroupFactoryInterface;
use WP_Query;
use function add_action;
use function add_filter;
use function esc_html;
use function get_current_screen;
use function is_admin;

/**
 * Member Admin Table Class
 *
 * Extends the WordPress admin table for Members post type
 * to include GSR status and service position fields
 */
class MemberAdmin
{
    private PositionFactoryInterface $positionFactory;
    private MemberRepositoryInterface $memberRepository;
    private GroupFactoryInterface $groupFactory;

    /**
     * Initialize the admin table customizations
     *
     * @param PositionFactoryInterface $positionFactory
     * @param MemberRepositoryInterface $memberRepository
     * @param GroupFactoryInterface $groupFactory
     */
    public function __construct(
        PositionFactoryInterface $positionFactory,
        MemberRepositoryInterface $memberRepository,
        GroupFactoryInterface $groupFactory
    ) {
        $this->positionFactory = $positionFactory;
        $this->memberRepository = $memberRepository;
        $this->groupFactory = $groupFactory;

        add_filter('manage_' . MemberConstants::MEMBER_POST_TYPE . '_posts_columns', [$this, 'addCustomColumns']);
        add_action('manage_' . MemberConstants::MEMBER_POST_TYPE . '_posts_custom_column', [$this, 'populateCustomColumns'], 10, 2);
        add_filter('manage_edit-' . MemberConstants::MEMBER_POST_TYPE . '_sortable_columns', [$this, 'makeSortableColumns']);
        add_action('pre_get_posts', [$this, 'handleCustomSorting']);
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
                $newColumns['gsr_status'] = 'GSR Status';
                $newColumns['service_position'] = 'Service Position';
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
//                $positionId = get_field(MemberConstants::FIELD_INTERGROUP_POSITION, $postId);
                $positionId = $member->getIntergroupPosition();

                $position = $this->positionFactory->createFromSource($positionId);

                if ($position) {
                    echo esc_html($position->getLongName());
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
        if (!$screen || $screen->post_type !== MemberConstants::MEMBER_POST_TYPE) {
            return;
        }

        $orderby = $query->get('orderby');

        switch ($orderby) {
            case 'anonymous_name':
                $query->set('meta_key', MemberConstants::FIELD_ANONYMOUS_NAME);
                $query->set('orderby', 'meta_value');
                break;

            case 'gsr_status':
                $query->set('meta_key', MemberConstants::FIELD_HOMEGROUP_GSR);
                $query->set('orderby', 'meta_value_num');
                break;

            case 'service_position':
                $query->set('meta_key', MemberConstants::FIELD_INTERGROUP_POSITION);
                $query->set('orderby', 'meta_value_num');
                break;

            case 'homegroup':
                $query->set('meta_key', MemberConstants::FIELD_HOME_GROUP);
                $query->set('orderby', 'meta_value_num');
                break;
        }
    }
}