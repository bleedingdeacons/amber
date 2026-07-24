<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Amber\Admin\Members\MemberAdmin;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\ResponderCertification;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionFactory;
use WP_Post;
use WP_Query;

/**
 * Tests for the members list table.
 *
 * This class decorates the WordPress members list: extra columns, sorting,
 * an extended search, and a GSR filter. Most of it is invisible until it is
 * wrong — a column that renders nothing, a sort that silently orders by the
 * wrong meta key, a search that quietly drops the position and home-group
 * matches it exists to add.
 *
 * The sort keys are precomputed into postmeta on save (WordPress cannot
 * order by a value that lives behind a factory), so those writes are
 * asserted directly.
 *
 * @covers \Amber\Admin\Members\MemberAdmin
 */
class MemberAdminTest extends AmberTestCase
{
    private const MEMBER_TYPE = 'intergroup-member';
    private const POSITION_TYPE = 'intergroup-position';
    private const GROUP_TYPE = 'tsml_group';

    private MemberAdmin $admin;

    /** @var MemberRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $members;

    /** @var PositionFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $positions;

    /** @var GroupFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $groups;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(static function (string $key): array {
            return match ($key) {
                Member::class => [
                    'POST_TYPE'                  => self::MEMBER_TYPE,
                    'FIELD_INTERGROUP_POSITION'  => 'service-layout-group_intergroup-position',
                    'FIELD_HOME_GROUP'           => 'home-layout-group_home-group',
                    'FIELD_HOMEGROUP_GSR'        => 'home-layout-group_homegroup-gsr',
                ],
                Position::class => ['POST_TYPE' => self::POSITION_TYPE],
                Group::class    => ['POST_TYPE' => self::GROUP_TYPE],
                default         => [],
            };
        });

        $this->members = $this->createMock(MemberRepository::class);
        $this->positions = $this->createMock(PositionFactory::class);
        $this->groups = $this->createMock(GroupFactory::class);

        $this->admin = new MemberAdmin($config, $this->positions, $this->members, $this->groups);
    }

    /** A member with the getters the list table reads. */
    private function member(array $overrides = []): Member
    {
        $defaults = [
            'getId' => 42,
            'isGsr' => false,
            'getIntergroupPosition' => 0,
            'getIntergroupPositionRotation' => '',
            'getHomeGroup' => 0,
            'isTwelfthStepper' => false,
            'isTelephoneResponder' => false,
            'getResponderCertification' => ResponderCertification::None,
        ];

        $member = $this->createMock(Member::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $member->method($method)->willReturn($value);
        }

        return $member;
    }

    private function position(string $longName = 'Treasurer'): Position
    {
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn($longName);

        return $position;
    }

    private function group(string $title = 'Tuesday Group', array $meetings = []): Group
    {
        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn($title);
        $group->method('getMeetings')->willReturn($meetings);

        return $group;
    }

    private function column(string $name, int $postId = 42): string
    {
        return $this->capture(fn () => $this->admin->populateCustomColumns($name, $postId));
    }

    // ── registration ─────────────────────────────────────────────────

    /** @test */
    public function it_registers_its_list_table_hooks(): void
    {
        $this->assertNotEmpty($this->hooksFor('manage_' . self::MEMBER_TYPE . '_posts_columns'));
        $this->assertNotEmpty($this->hooksFor('manage_' . self::MEMBER_TYPE . '_posts_custom_column'));
        $this->assertNotEmpty($this->hooksFor('manage_edit-' . self::MEMBER_TYPE . '_sortable_columns'));
        $this->assertNotEmpty($this->hooksFor('save_post_' . self::MEMBER_TYPE));
        $this->assertNotEmpty($this->hooksFor('restrict_manage_posts'));
        $this->assertNotEmpty($this->hooksFor('pre_get_posts'));
    }

    // ── columns ──────────────────────────────────────────────────────

    /** @test */
    public function the_custom_columns_are_inserted_after_the_title(): void
    {
        $columns = $this->admin->addCustomColumns(['cb' => '', 'title' => 'Title', 'date' => 'Date']);

        $keys = array_keys($columns);
        $this->assertSame('cb', $keys[0]);
        $this->assertSame('title', $keys[1]);
        // The title is relabelled, since a member's title is their pseudonym.
        $this->assertSame('Anonymous Name', $columns['title']);

        foreach (['service_position', 'rotation_date', 'gsr_status', 'homegroup', 'twelfth', 'responder', 'certification'] as $added) {
            $this->assertArrayHasKey($added, $columns);
        }
        // Pre-existing columns survive.
        $this->assertArrayHasKey('date', $columns);
    }

    /** @test */
    public function a_column_for_a_member_that_cannot_be_loaded_reads_not_applicable(): void
    {
        $this->members->method('findById')->willReturn(null);

        $this->assertStringContainsString('N/A', $this->column('gsr_status'));
    }

    /** @test */
    public function the_gsr_column_marks_a_gsr(): void
    {
        $this->members->method('findById')->willReturn($this->member(['isGsr' => true]));

        $this->assertStringContainsString('Yes', $this->column('gsr_status'));
    }

    /** @test */
    public function the_gsr_column_marks_a_non_gsr(): void
    {
        $this->members->method('findById')->willReturn($this->member(['isGsr' => false]));

        $this->assertStringContainsString('No', $this->column('gsr_status'));
    }

    /** @test */
    public function the_twelfth_and_responder_columns_report_their_flags(): void
    {
        $this->members->method('findById')->willReturn(
            $this->member(['isTwelfthStepper' => true, 'isTelephoneResponder' => true])
        );

        $this->assertStringContainsString('Yes', $this->column('twelfth'));
        $this->assertStringContainsString('Yes', $this->column('responder'));
    }

    /** @test */
    public function the_service_position_column_links_to_the_position(): void
    {
        $this->members->method('findById')->willReturn($this->member(['getIntergroupPosition' => 7]));
        $this->positions->method('createFromSource')->willReturn($this->position('Intergroup Treasurer'));

        $html = $this->column('service_position');

        $this->assertStringContainsString('Intergroup Treasurer', $html);
        $this->assertStringContainsString('<a href=', $html);
    }

    /** @test */
    public function a_member_with_no_position_shows_not_applicable(): void
    {
        $this->members->method('findById')->willReturn($this->member());
        $this->positions->method('createFromSource')->willReturn(null);

        $this->assertStringContainsString('N/A', $this->column('service_position'));
    }

    /** @test */
    public function the_rotation_column_shows_the_date_or_a_dash(): void
    {
        $this->members->method('findById')->willReturn(
            $this->member(['getIntergroupPositionRotation' => '01/01/2027'])
        );
        $this->assertStringContainsString('01/01/2027', $this->column('rotation_date'));
    }

    /** @test */
    public function the_homegroup_column_links_via_the_groups_first_meeting(): void
    {
        // Groups have no edit screen of their own, so the link goes to a
        // meeting the group holds.
        $meeting = $this->createMock(Meeting::class);
        $meeting->method('getId')->willReturn(99);

        $this->members->method('findById')->willReturn($this->member(['getHomeGroup' => 3]));
        $this->groups->method('createFromSource')->willReturn($this->group('Tuesday Group', [$meeting]));

        $html = $this->column('homegroup');

        $this->assertStringContainsString('Tuesday Group', $html);
        $this->assertStringContainsString('post=99', $html);
    }

    /** @test */
    public function a_homegroup_with_no_meetings_renders_as_plain_text(): void
    {
        $this->members->method('findById')->willReturn($this->member(['getHomeGroup' => 3]));
        $this->groups->method('createFromSource')->willReturn($this->group('Tuesday Group', []));

        $html = $this->column('homegroup');

        $this->assertStringContainsString('Tuesday Group', $html);
        $this->assertStringNotContainsString('<a href=', $html);
    }

    /** @test */
    public function an_unknown_homegroup_shows_not_applicable(): void
    {
        $this->members->method('findById')->willReturn($this->member(['getHomeGroup' => 0]));
        $this->groups->method('createFromSource')->willReturn(null);

        $this->assertStringContainsString('N/A', $this->column('homegroup'));
    }

    // ── certification column ─────────────────────────────────────────

    /** @test */
    public function a_non_responder_shows_a_dash_rather_than_none(): void
    {
        // The backing field is hidden for non-responders, so every one of
        // them reads as None; showing "None" would imply a responder who has
        // not started.
        $this->members->method('findById')->willReturn(
            $this->member(['isTelephoneResponder' => false])
        );

        $html = $this->column('certification');

        $this->assertStringContainsString('—', $html);
        $this->assertStringNotContainsString('None', $html);
    }

    /**
     * @test
     * @dataProvider certificationColourProvider
     */
    public function each_certification_stage_gets_its_colour(
        ResponderCertification $stage,
        string $expectedColour
    ): void {
        $this->members->method('findById')->willReturn($this->member([
            'isTelephoneResponder' => true,
            'getResponderCertification' => $stage,
        ]));

        $html = $this->column('certification');

        $this->assertStringContainsString($stage->label(), $html);
        $this->assertStringContainsString($expectedColour, $html);
    }

    /** @return array<string, array{0: ResponderCertification, 1: string}> */
    public static function certificationColourProvider(): array
    {
        return [
            'certified reads as a pass'   => [ResponderCertification::Certified, 'green'],
            'applied is in progress'      => [ResponderCertification::Applied, '#996800'],
            'in training is in progress'  => [ResponderCertification::InTraining, '#996800'],
            'pending is in progress'      => [ResponderCertification::Pending, '#996800'],
            'none is neutral'             => [ResponderCertification::None, 'gray'],
        ];
    }

    // ── sorting ──────────────────────────────────────────────────────

    /** @test */
    public function the_sortable_columns_are_declared(): void
    {
        $sortable = $this->admin->makeSortableColumns([]);

        foreach (['gsr_status', 'service_position', 'rotation_date', 'homegroup'] as $column) {
            $this->assertArrayHasKey($column, $sortable);
        }
    }

    /**
     * Each sortable column maps to a precomputed meta key, because the value
     * shown lives behind a factory and WordPress cannot order by it.
     *
     * @test
     * @dataProvider sortProvider
     */
    public function sorting_by_a_column_orders_by_its_precomputed_meta_key(
        string $orderby,
        string $metaKey,
        string $orderType
    ): void {
        $query = new WP_Query(['post_type' => self::MEMBER_TYPE, 'orderby' => $orderby]);

        $this->admin->handleCustomSorting($query);

        $this->assertSame($metaKey, $query->get('meta_key'));
        $this->assertSame($orderType, $query->get('orderby'));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function sortProvider(): array
    {
        return [
            'gsr'      => ['gsr_status', '_member_gsr_sort', 'meta_value_num'],
            'position' => ['service_position', '_member_position_sort_name', 'meta_value'],
            'rotation' => ['rotation_date', '_member_rotation_date_sort', 'meta_value'],
            'homegroup' => ['homegroup', '_member_homegroup_sort_name', 'meta_value'],
        ];
    }

    /** @test */
    public function sorting_is_left_alone_for_another_post_type(): void
    {
        $query = new WP_Query(['post_type' => 'page', 'orderby' => 'gsr_status']);

        $this->admin->handleCustomSorting($query);

        $this->assertSame('', $query->get('meta_key'));
    }

    /** @test */
    public function sorting_is_left_alone_when_not_the_main_query(): void
    {
        $query = new WP_Query(['post_type' => self::MEMBER_TYPE, 'orderby' => 'gsr_status']);
        $query->isMainQuery = false;

        $this->admin->handleCustomSorting($query);

        $this->assertSame('', $query->get('meta_key'));
    }

    /** @test */
    public function an_unrecognised_sort_column_is_passed_through_untouched(): void
    {
        $query = new WP_Query(['post_type' => self::MEMBER_TYPE, 'orderby' => 'title']);

        $this->admin->handleCustomSorting($query);

        $this->assertSame('title', $query->get('orderby'));
        $this->assertSame('', $query->get('meta_key'));
    }

    // ── GSR filter ───────────────────────────────────────────────────

    /** @test */
    public function the_gsr_filter_dropdown_is_rendered_for_members_only(): void
    {
        $html = $this->capture(fn () => $this->admin->addGsrFilterDropdown(self::MEMBER_TYPE));

        $this->assertStringContainsString('<select name="gsr_filter">', $html);
        $this->assertStringContainsString('Is GSR', $html);
        $this->assertStringContainsString('Not GSR', $html);

        $this->assertSame('', $this->capture(fn () => $this->admin->addGsrFilterDropdown('page')));
    }

    /** @test */
    public function the_dropdown_remembers_the_current_selection(): void
    {
        $_GET['gsr_filter'] = 'yes';

        $html = $this->capture(fn () => $this->admin->addGsrFilterDropdown(self::MEMBER_TYPE));

        $this->assertStringContainsString('selected="selected"', $html);
    }

    /** @test */
    public function filtering_to_gsrs_adds_an_equality_meta_query(): void
    {
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $_GET['gsr_filter'] = 'yes';
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        $metaQuery = $query->get('meta_query');
        $this->assertSame('home-layout-group_homegroup-gsr', $metaQuery[0]['key']);
        $this->assertSame('=', $metaQuery[0]['compare']);
    }

    /** @test */
    public function filtering_to_non_gsrs_also_matches_members_with_no_value(): void
    {
        // A member who has never been a GSR has no row at all, so a plain
        // "!= 1" would miss them.
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $_GET['gsr_filter'] = 'no';
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        $metaQuery = $query->get('meta_query');
        $this->assertSame('OR', $metaQuery[0]['relation']);
        $this->assertSame('NOT EXISTS', $metaQuery[0][1]['compare']);
    }

    /** @test */
    public function no_filter_is_applied_without_a_selection(): void
    {
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        $this->assertSame('', $query->get('meta_query'));
    }

    /** @test */
    public function the_filter_is_skipped_on_another_screen(): void
    {
        $this->setScreen('edit-page', 'edit', 'page');
        $_GET['gsr_filter'] = 'yes';
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        $this->assertSame('', $query->get('meta_query'));
    }

    // ── extended search ──────────────────────────────────────────────

    /** @test */
    public function search_is_extended_to_members_linked_to_matching_positions(): void
    {
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $query = new WP_Query(['s' => 'treasurer']);
        $query->isSearch = true;

        // Position lookup, group lookup, members-by-position,
        // members-by-group, then the title matches.
        $this->wpdb->col = [7];

        $this->admin->extendSearch($query);

        // The term is cleared and replaced by an explicit id list, so the
        // extra matches are not filtered back out by the title search.
        $this->assertSame('', $query->get('s'));
        $this->assertNotEmpty($query->get('post__in'));
    }

    /** @test */
    public function search_is_left_alone_when_nothing_extra_matches(): void
    {
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $query = new WP_Query(['s' => 'nothing']);
        $query->isSearch = true;
        $this->wpdb->col = [];

        $this->admin->extendSearch($query);

        $this->assertSame('nothing', $query->get('s'), 'WordPress keeps its own title search.');
    }

    /** @test */
    public function search_is_skipped_for_an_empty_term(): void
    {
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $query = new WP_Query(['s' => '']);
        $query->isSearch = true;

        $this->admin->extendSearch($query);

        $this->assertSame([], $this->wpdb->queries, 'No term, no lookups.');
    }

    /** @test */
    public function search_is_skipped_when_the_query_is_not_a_search(): void
    {
        $this->setScreen('edit-member', 'edit', self::MEMBER_TYPE);
        $query = new WP_Query(['s' => 'treasurer']);
        $query->isSearch = false;

        $this->admin->extendSearch($query);

        $this->assertSame([], $this->wpdb->queries);
    }

    /** @test */
    public function search_is_skipped_on_another_post_type_screen(): void
    {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['s' => 'treasurer']);
        $query->isSearch = true;

        $this->admin->extendSearch($query);

        $this->assertSame([], $this->wpdb->queries);
    }

    // ── sort metadata ────────────────────────────────────────────────

    /** @test */
    public function saving_a_member_precomputes_every_sort_key(): void
    {
        $meeting = $this->createMock(Meeting::class);
        $meeting->method('getId')->willReturn(99);

        $this->members->method('findById')->willReturn($this->member([
            'isGsr' => true,
            'getIntergroupPosition' => 7,
            'getIntergroupPositionRotation' => '01/03/2027',
            'getHomeGroup' => 3,
        ]));
        $this->positions->method('createFromSource')->willReturn($this->position('Treasurer'));
        $this->groups->method('createFromSource')->willReturn($this->group('Tuesday Group', [$meeting]));

        $this->admin->updateMemberMetadata(42);

        $meta = WpState::$postMeta[42];
        $this->assertSame(1, $meta['_member_gsr_sort']);
        $this->assertSame('treasurer', $meta['_member_position_sort_name'], 'lower-cased for a stable sort');
        // d/m/Y is reordered so a string sort is a chronological sort.
        $this->assertSame('2027-03-01', $meta['_member_rotation_date_sort']);
        $this->assertSame('tuesday group', $meta['_member_homegroup_sort_name']);
    }

    /** @test */
    public function a_member_with_nothing_set_sorts_last(): void
    {
        $this->members->method('findById')->willReturn($this->member());
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->admin->updateMemberMetadata(42);

        $meta = WpState::$postMeta[42];
        $this->assertSame(0, $meta['_member_gsr_sort']);
        // A sentinel that sorts after every real name.
        $this->assertSame('zzz_none', $meta['_member_position_sort_name']);
        $this->assertSame('zzz_none', $meta['_member_rotation_date_sort']);
        $this->assertSame('zzz_none', $meta['_member_homegroup_sort_name']);
    }

    /** @test */
    public function an_unparseable_rotation_date_is_stored_as_given(): void
    {
        $this->members->method('findById')->willReturn(
            $this->member(['getIntergroupPositionRotation' => 'sometime soon'])
        );
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->admin->updateMemberMetadata(42);

        $this->assertSame('sometime soon', WpState::$postMeta[42]['_member_rotation_date_sort']);
    }

    /** @test */
    public function metadata_is_not_written_for_a_member_that_cannot_be_loaded(): void
    {
        $this->members->method('findById')->willReturn(null);

        $this->admin->updateMemberMetadata(42);

        $this->assertArrayNotHasKey(42, WpState::$postMeta);
    }

    /** @test */
    public function saving_recomputes_the_sort_keys(): void
    {
        $this->members->method('findById')->willReturn($this->member());
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->admin->updateMemberMetadataOnSave(42, new WP_Post(['ID' => 42]), true);

        $this->assertArrayHasKey('_member_gsr_sort', WpState::$postMeta[42]);
    }

    /** @test */
    public function an_ajax_save_is_ignored(): void
    {
        // ACF fires save_post over AJAX mid-edit; recomputing then would use
        // half-written field values.
        WpState::$doingAjax = true;
        $this->members->expects($this->never())->method('findById');

        $this->admin->updateMemberMetadataOnSave(42, new WP_Post(['ID' => 42]), true);

        $this->assertArrayNotHasKey(42, WpState::$postMeta);
    }

    /** @test */
    public function every_member_can_be_backfilled_at_once(): void
    {
        $this->members->method('findAll')->willReturn([
            $this->member(['getId' => 1]),
            $this->member(['getId' => 2]),
        ]);
        $this->members->method('findById')->willReturn($this->member());
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->assertSame(2, $this->admin->setupAllMembersMetadata());
    }
}
