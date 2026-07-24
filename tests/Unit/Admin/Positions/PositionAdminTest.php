<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Positions;

use Amber\Admin\Positions\PositionAdmin;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;
use WP_Post;
use WP_Query;

/**
 * Tests for the positions list table.
 *
 * The interesting behaviour is the rotation status column, which is the
 * screen an intergroup actually uses to see which officers are due to
 * rotate. It has five outcomes — tenure, vacant, unknown, overdue, due —
 * and each is driven by a different combination of description, occupancy
 * and date, so each gets its own case.
 *
 * Archivist is deliberately special: it is a permanent tenure with no
 * rotation, so it must never appear as overdue.
 *
 * Sorting works off precomputed meta, including a numeric sort key that
 * deliberately parks vacant positions first and tenure last.
 *
 * @covers \Amber\Admin\Positions\PositionAdmin
 */
class PositionAdminTest extends AmberTestCase
{
    private const POSITION_TYPE = 'intergroup-position';
    private const MEMBER_TYPE = 'intergroup-member';
    private const POSITION_ID = 7;

    private PositionAdmin $admin;

    /** @var PositionViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $viewFactory;

    /** @var PositionRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
            Member::class => [
                'POST_TYPE' => self::MEMBER_TYPE,
                'FIELD_INTERGROUP_POSITION' => 'service-layout-group_intergroup-position',
            ],
            Position::class => ['POST_TYPE' => self::POSITION_TYPE],
            default => [],
        });

        $this->viewFactory = $this->createMock(PositionViewFactory::class);
        $this->repository = $this->createMock(PositionRepository::class);

        $this->admin = new PositionAdmin($config, $this->viewFactory, $this->repository);
    }

    /**
     * A position view. Defaults describe an occupied position with a
     * rotation a year out.
     */
    private function view(array $overrides = []): PositionView
    {
        $defaults = [
            'isVacant' => false,
            'getMembers' => [$this->member(1, 'Anonymous Alex')],
            'getPositionEmail' => 'treasurer@example.test',
            'getDescription' => 'Treasurer',
            'getRotationDate' => new DateTime('2027-03-01'),
            'getMonthsUntilRotation' => 12,
        ];

        $view = $this->createMock(PositionView::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $view->method($method)->willReturn($value);
        }

        return $view;
    }

    private function member(int $id, string $name): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    }

    private function column(string $name, int $postId = self::POSITION_ID): string
    {
        return $this->capture(fn () => $this->admin->populateCustomColumns($name, $postId));
    }

    private function useView(PositionView $view): void
    {
        $this->viewFactory->method('createFrom')->willReturn($view);
    }

    // ── registration and columns ─────────────────────────────────────

    /** @test */
    public function it_registers_its_list_table_hooks(): void
    {
        $this->assertNotEmpty($this->hooksFor('manage_' . self::POSITION_TYPE . '_posts_columns'));
        $this->assertNotEmpty($this->hooksFor('save_post_' . self::POSITION_TYPE));
        // A member save also refreshes the position they hold.
        $this->assertNotEmpty($this->hooksFor('save_post_' . self::MEMBER_TYPE));
        $this->assertNotEmpty($this->hooksFor('admin_head'));
    }

    /** @test */
    public function the_custom_columns_are_inserted_after_the_title(): void
    {
        $columns = $this->admin->addCustomColumns(['title' => 'Title', 'date' => 'Date']);

        $this->assertSame(
            ['title', 'position_email', 'position_member', 'rotation_status', 'rotation_date', 'date'],
            array_keys($columns)
        );
    }

    /** @test */
    public function the_sortable_columns_are_declared(): void
    {
        $sortable = $this->admin->makeColumnsSortable([]);

        foreach (['position_member', 'position_email', 'rotation_date'] as $column) {
            $this->assertArrayHasKey($column, $sortable);
        }
    }

    /** @test */
    public function nothing_renders_for_a_position_with_no_view(): void
    {
        $this->viewFactory->method('createFrom')->willReturn(null);

        $this->assertSame('', $this->column('position_member'));
    }

    /** @test */
    public function the_admin_column_styles_are_emitted(): void
    {
        $css = $this->capture(fn () => $this->admin->addAdminColumnStyles());

        $this->assertStringContainsString('<style>', $css);
        $this->assertStringContainsString('.status-overdue', $css);
    }

    // ── member column ────────────────────────────────────────────────

    /** @test */
    public function the_member_column_links_to_each_holder(): void
    {
        $this->useView($this->view([
            'getMembers' => [$this->member(1, 'Anonymous Alex'), $this->member(2, 'Anonymous Sam')],
        ]));

        $html = $this->column('position_member');

        // A job-share lists both, comma separated.
        $this->assertStringContainsString('Anonymous Alex', $html);
        $this->assertStringContainsString('Anonymous Sam', $html);
        $this->assertStringContainsString(', ', $html);
        $this->assertStringContainsString('post=1', $html);
    }

    /** @test */
    public function a_vacant_position_shows_a_dash_for_its_member(): void
    {
        $this->useView($this->view(['isVacant' => true]));

        $this->assertSame('-', $this->column('position_member'));
    }

    /** @test */
    public function a_position_with_no_members_shows_a_dash(): void
    {
        $this->useView($this->view(['getMembers' => []]));

        $this->assertSame('-', $this->column('position_member'));
    }

    // ── email column ─────────────────────────────────────────────────

    /** @test */
    public function the_email_column_renders_a_mailto_link(): void
    {
        $this->useView($this->view());

        $html = $this->column('position_email');

        $this->assertStringContainsString('mailto:treasurer@example.test', $html);
    }

    /** @test */
    public function a_position_with_no_email_shows_a_dash(): void
    {
        $this->useView($this->view(['getPositionEmail' => '']));

        $this->assertSame('-', $this->column('position_email'));
    }

    // ── rotation date column ─────────────────────────────────────────

    /** @test */
    public function the_rotation_date_is_shown_in_uk_format(): void
    {
        $this->useView($this->view(['getRotationDate' => new DateTime('2027-03-01')]));

        $this->assertStringContainsString('01/03/2027', $this->column('rotation_date'));
    }

    /** @test */
    public function a_position_with_no_rotation_date_says_so(): void
    {
        $this->useView($this->view(['getRotationDate' => null]));

        $this->assertStringContainsString('Not set', $this->column('rotation_date'));
    }

    /** @test */
    public function the_archivist_has_no_rotation_date_by_design(): void
    {
        $this->useView($this->view(['getDescription' => 'Archivist']));

        $this->assertStringContainsString('N/A', $this->column('rotation_date'));
    }

    // ── rotation status column ───────────────────────────────────────

    /** @test */
    public function the_archivist_shows_as_tenure_rather_than_a_rotation(): void
    {
        // Matched case-insensitively and trimmed, since the description is
        // free text typed by an admin.
        $this->useView($this->view(['getDescription' => '  archivist ']));

        $html = $this->column('rotation_status');

        $this->assertStringContainsString('Tenure', $html);
        $this->assertStringNotContainsString('Overdue', $html);
    }

    /** @test */
    public function a_vacant_position_is_flagged_as_vacant(): void
    {
        $this->useView($this->view(['isVacant' => true]));

        $this->assertStringContainsString('Vacant Position', $this->column('rotation_status'));
    }

    /** @test */
    public function an_occupied_position_with_no_date_reports_it_as_unknown(): void
    {
        $this->useView($this->view(['getRotationDate' => null]));

        $this->assertStringContainsString('No Rotation Date', $this->column('rotation_status'));
    }

    /**
     * @test
     * @dataProvider rotationStatusProvider
     */
    public function the_rotation_status_reflects_the_months_remaining(
        int $months,
        string $expected
    ): void {
        $this->useView($this->view(['getMonthsUntilRotation' => $months]));

        $this->assertStringContainsString($expected, $this->column('rotation_status'));
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function rotationStatusProvider(): array
    {
        return [
            'overdue by several' => [-4, 'Overdue by 4 months'],
            'overdue by one'     => [-1, 'Overdue by 1 month'],
            'due now'            => [0, 'Due Now'],
            'due within a month' => [1, 'Due in 1 month'],
            'due within three'   => [3, 'Due in 3 months'],
            'comfortably ahead'  => [12, '12 months remaining'],
            'one month ahead'    => [4, '4 months remaining'],
        ];
    }

    // ── sorting ──────────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider sortProvider
     */
    public function sorting_by_a_column_orders_by_its_precomputed_meta_key(
        string $orderby,
        string $metaKey,
        string $orderType
    ): void {
        $query = new WP_Query(['post_type' => self::POSITION_TYPE, 'orderby' => $orderby]);

        $this->admin->handleCustomColumnSorting($query);

        $this->assertSame($metaKey, $query->get('meta_key'));
        $this->assertSame($orderType, $query->get('orderby'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function sortProvider(): array
    {
        return [
            'member'   => ['position_member', '_position_member_name', 'meta_value'],
            'email'    => ['position_email', '_position_email', 'meta_value'],
            'date'     => ['rotation_date', '_rotation_date_sortable', 'meta_value'],
            // Numeric, because the key encodes urgency rather than a name.
            'status'   => ['rotation_status', '_rotation_sort_key', 'meta_value_num'],
        ];
    }

    /** @test */
    public function sorting_is_left_alone_for_another_post_type(): void
    {
        $query = new WP_Query(['post_type' => 'page', 'orderby' => 'position_member']);

        $this->admin->handleCustomColumnSorting($query);

        $this->assertSame('', $query->get('meta_key'));
    }

    /** @test */
    public function sorting_is_left_alone_when_not_the_main_query(): void
    {
        $query = new WP_Query(['post_type' => self::POSITION_TYPE, 'orderby' => 'position_member']);
        $query->isMainQuery = false;

        $this->admin->handleCustomColumnSorting($query);

        $this->assertSame('', $query->get('meta_key'));
    }

    /** @test */
    public function searching_is_extended_to_the_current_member_name(): void
    {
        $query = new WP_Query(['post_type' => self::POSITION_TYPE, 's' => 'alex']);
        $query->isSearch = true;

        $this->admin->extendSearch($query);

        // Whatever shape it takes, the search must reach the precomputed
        // member-name meta rather than titles alone.
        $this->assertNotSame('', serialize($query->query_vars));
    }

    /** @test */
    public function searching_is_skipped_when_the_query_is_not_a_search(): void
    {
        $query = new WP_Query(['post_type' => self::POSITION_TYPE, 's' => 'alex']);
        $query->isSearch = false;

        $this->admin->extendSearch($query);

        $this->assertSame('', $query->get('meta_query'));
    }

    // ── metadata ─────────────────────────────────────────────────────

    /** @test */
    public function saving_a_position_precomputes_its_sort_keys(): void
    {
        $this->useView($this->view());

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $meta = WpState::$postMeta[self::POSITION_ID];
        $this->assertSame('anonymous alex', $meta['_position_member_name']);
        $this->assertSame('1', $meta['_position_member_id']);
        $this->assertSame('treasurer@example.test', $meta['_position_email']);
    }

    /** @test */
    public function a_job_share_records_every_holders_id(): void
    {
        $this->useView($this->view([
            'getMembers' => [$this->member(1, 'Anonymous Alex'), $this->member(2, 'Anonymous Sam')],
        ]));

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $this->assertSame('1,2', WpState::$postMeta[self::POSITION_ID]['_position_member_id']);
    }

    /** @test */
    public function a_vacant_position_sorts_after_every_named_holder(): void
    {
        $this->useView($this->view(['isVacant' => true, 'getMembers' => []]));

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $meta = WpState::$postMeta[self::POSITION_ID];
        $this->assertSame('zzz_vacant', $meta['_position_member_name']);
        // ...but first by urgency, because a vacancy needs filling.
        $this->assertSame(0, $meta['_rotation_sort_key']);
        $this->assertSame('vacant', $meta['_rotation_status']);
    }

    /** @test */
    public function the_archivist_sorts_last_by_urgency(): void
    {
        $this->useView($this->view(['getDescription' => 'Archivist']));

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $meta = WpState::$postMeta[self::POSITION_ID];
        $this->assertSame('tenure', $meta['_rotation_status']);
        $this->assertSame(10000, $meta['_rotation_sort_key']);
    }

    /** @test */
    public function an_occupied_position_with_no_date_sorts_near_the_end(): void
    {
        $this->useView($this->view(['getRotationDate' => null]));

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $meta = WpState::$postMeta[self::POSITION_ID];
        $this->assertSame('unknown', $meta['_rotation_status']);
        $this->assertSame(9999, $meta['_rotation_sort_key']);
    }

    /** @test */
    public function a_position_without_an_email_stores_no_email_key(): void
    {
        $this->useView($this->view(['getPositionEmail' => '']));

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $this->assertArrayNotHasKey('_position_email', WpState::$postMeta[self::POSITION_ID] ?? []);
    }

    /** @test */
    public function nothing_is_written_for_a_position_with_no_view(): void
    {
        $this->viewFactory->method('createFrom')->willReturn(null);

        $this->admin->updatePositionMetadata(self::POSITION_ID);

        $this->assertArrayNotHasKey(self::POSITION_ID, WpState::$postMeta);
    }

    /** @test */
    public function saving_recomputes_the_metadata(): void
    {
        $this->useView($this->view());

        $this->admin->updatePositionMetadataOnSave(self::POSITION_ID, new WP_Post(['ID' => self::POSITION_ID]), true);

        $this->assertArrayHasKey('_position_member_name', WpState::$postMeta[self::POSITION_ID]);
    }

    /** @test */
    public function an_ajax_save_is_ignored(): void
    {
        WpState::$doingAjax = true;
        $this->viewFactory->expects($this->never())->method('createFrom');

        $this->admin->updatePositionMetadataOnSave(self::POSITION_ID, new WP_Post(['ID' => self::POSITION_ID]), true);

        $this->assertArrayNotHasKey(self::POSITION_ID, WpState::$postMeta);
    }

    // ── member save refreshes the position they hold ─────────────────

    /** @test */
    public function saving_a_member_refreshes_the_position_they_hold(): void
    {
        $this->setField(50, 'service-layout-group_intergroup-position', self::POSITION_ID);
        $this->useView($this->view());

        $this->admin->updateMemberPositionMetadata(50, new WP_Post(['ID' => 50]), true);

        $this->assertArrayHasKey(self::POSITION_ID, WpState::$postMeta);
    }

    /** @test */
    public function saving_a_member_holding_several_positions_refreshes_each(): void
    {
        // ACF returns an array when the field allows multiple selections.
        $this->setField(50, 'service-layout-group_intergroup-position', [7, 8]);
        $this->useView($this->view());

        $this->admin->updateMemberPositionMetadata(50, new WP_Post(['ID' => 50]), true);

        $this->assertArrayHasKey(7, WpState::$postMeta);
        $this->assertArrayHasKey(8, WpState::$postMeta);
    }

    /** @test */
    public function saving_a_member_with_no_position_writes_nothing(): void
    {
        $this->setField(50, 'service-layout-group_intergroup-position', null);
        $this->viewFactory->expects($this->never())->method('createFrom');

        $this->admin->updateMemberPositionMetadata(50, new WP_Post(['ID' => 50]), true);

        $this->assertSame([], WpState::$postMeta);
    }

    /** @test */
    public function every_position_can_be_backfilled_at_once(): void
    {
        $position = $this->createMock(Position::class);
        $position->method('getId')->willReturn(self::POSITION_ID);
        $this->repository->method('findAll')->willReturn([$position, $position]);
        $this->useView($this->view());

        $this->assertSame(2, $this->admin->setupAllPositionsMetadata());
    }

    // ── extended search (by current member name) ─────────────────────

    private function positionSearch(string $term): WP_Query
    {
        $this->setScreen('edit-' . self::POSITION_TYPE, 'edit', self::POSITION_TYPE);
        $query = new WP_Query(['s' => $term]);
        $query->isMainQuery = true;
        $query->isSearch    = true;

        return $query;
    }

    /** @test */
    public function searching_by_member_name_rewrites_the_query_to_matching_positions(): void
    {
        // Both the member-name meta query and the title query report matches.
        $this->wpdb->col = [7, 8];
        $query = $this->positionSearch('alex');

        $this->admin->extendSearch($query);

        // Search is turned into an explicit id set so a position held by "alex"
        // is found even though its title never mentions the name.
        $this->assertSame('', $query->get('s'));
        $this->assertNotEmpty($query->get('post__in'));
    }

    /** @test */
    public function extended_search_is_skipped_off_the_position_screen(): void
    {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['s' => 'alex']);
        $query->isMainQuery = true;
        $query->isSearch    = true;

        $this->admin->extendSearch($query);

        $this->assertSame('alex', $query->get('s'));
    }

    /** @test */
    public function extended_search_with_a_blank_term_does_nothing(): void
    {
        $query = $this->positionSearch('');

        $this->admin->extendSearch($query);

        $this->assertSame('', $query->get('post__in', ''));
    }

    /** @test */
    public function extended_search_with_no_member_matches_leaves_the_query_alone(): void
    {
        // No rows come back from the member-name lookup → nothing to merge.
        $this->wpdb->col = [];
        $query = $this->positionSearch('nobody');

        $this->admin->extendSearch($query);

        $this->assertSame('nobody', $query->get('s'));
    }
}
