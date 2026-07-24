<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Meetings;

use Amber\Admin\Meetings\MeetingAdmin;
use Amber\Tests\AmberTestCase;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupView;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\Meetings\Interfaces\Meeting;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Query;
use WP_Screen;

/**
 * Tests for the meeting list-table customisations.
 *
 * MeetingAdmin bolts three columns onto TSML's meeting list — Group, GSRs and
 * Email — and teaches the list to sort and search by the meeting's group even
 * though the group lives in a separate post joined only through post meta. The
 * column painters each have a distinct "no data" fallback that officers rely on
 * to spot unlinked meetings, and the search rewrite only fires for a real
 * search on the meeting screen, so the gate that decides that is exercised on
 * its own.
 *
 * @covers \Amber\Admin\Meetings\MeetingAdmin
 */
class MeetingAdminTest extends AmberTestCase
{
    private const MEETING_TYPE = 'tsml_meeting';
    private const GROUP_META   = 'group_id';
    private const GROUP_TYPE   = 'tsml_group';

    private MeetingAdmin $admin;

    /** @var GroupRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $groupRepository;

    /** @var GroupViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $groupViewFactory;

    /** @var MemberRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $memberRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturn([
            'POST_TYPE'       => self::MEETING_TYPE,
            'GROUP_META_KEY'  => self::GROUP_META,
            'GROUP_POST_TYPE' => self::GROUP_TYPE,
        ]);

        $this->groupRepository  = $this->createMock(GroupRepository::class);
        $this->groupViewFactory = $this->createMock(GroupViewFactory::class);
        $this->memberRepository = $this->createMock(MemberRepository::class);

        $this->admin = new MeetingAdmin(
            $config,
            $this->groupRepository,
            $this->groupViewFactory,
            $this->memberRepository
        );
    }

    private function meetingScreenQuery(string $orderby = '', bool $search = false, string $term = ''): WP_Query
    {
        $this->setScreen('edit-' . self::MEETING_TYPE, 'edit', self::MEETING_TYPE);

        $query = new WP_Query(['orderby' => $orderby, 's' => $term]);
        $query->isMainQuery = true;
        $query->isSearch    = $search;

        return $query;
    }

    // ── columns ──────────────────────────────────────────────────────

    /** @test */
    public function the_group_gsr_and_email_columns_are_inserted_after_time(): void
    {
        $columns = $this->admin->addCustomColumns([
            'title'       => 'Title',
            'time'        => 'Time',
            'data_source' => 'Data Source',
            'region'      => 'Region',
        ]);

        // TSML's own columns are dropped; ours land immediately after time.
        $this->assertArrayNotHasKey('data_source', $columns);
        $this->assertArrayNotHasKey('region', $columns);
        $keys = array_keys($columns);
        $this->assertSame(['title', 'time', 'group', 'gsrs', 'email'], $keys);
    }

    /** @test */
    public function the_group_column_is_sortable(): void
    {
        $this->assertSame('group', $this->admin->makeSortableColumns([])['group']);
    }

    /** @test */
    public function the_group_and_email_columns_are_shown_by_default_on_the_meeting_screen(): void
    {
        $screen = new WP_Screen(['id' => 'edit-' . self::MEETING_TYPE]);

        $hidden = $this->admin->setDefaultHiddenColumns(['group', 'email', 'author'], $screen);

        $this->assertNotContains('group', $hidden);
        $this->assertNotContains('email', $hidden);
        $this->assertContains('author', $hidden);
    }

    /** @test */
    public function default_hidden_columns_are_untouched_on_other_screens(): void
    {
        $screen = new WP_Screen(['id' => 'edit-page']);

        $this->assertSame(['group'], $this->admin->setDefaultHiddenColumns(['group'], $screen));
    }

    // ── group column ─────────────────────────────────────────────────

    /** @test */
    public function the_group_column_shows_the_group_title(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->groupRepository->method('findById')->willReturn($group);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group', 42));

        $this->assertStringContainsString('Tuesday Group', $html);
    }

    /** @test */
    public function the_group_column_shows_na_when_the_meeting_has_no_group(): void
    {
        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group', 42));

        $this->assertStringContainsString('N/A', $html);
    }

    /** @test */
    public function the_group_column_shows_na_when_the_group_has_no_title(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('');
        $this->groupRepository->method('findById')->willReturn($group);

        $this->assertStringContainsString('N/A', $this->capture(fn () => $this->admin->populateCustomColumns('group', 42)));
    }

    // ── email column ─────────────────────────────────────────────────

    /** @test */
    public function the_email_column_links_the_group_email(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getEmail')->willReturn('grp@example.test');
        $this->groupRepository->method('findById')->willReturn($group);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('email', 42));

        $this->assertStringContainsString('mailto:grp@example.test', $html);
    }

    /** @test */
    public function the_email_column_dashes_when_there_is_no_group(): void
    {
        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('email', 42)));
    }

    /** @test */
    public function the_email_column_dashes_when_the_group_has_no_email(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getEmail')->willReturn('');
        $this->groupRepository->method('findById')->willReturn($group);

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('email', 42)));
    }

    // ── GSRs column ──────────────────────────────────────────────────

    /** @test */
    public function the_gsrs_column_links_each_gsr_in_the_group(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);

        $gsr = $this->createMock(Member::class);
        $gsr->method('isGSR')->willReturn(true);
        $gsr->method('getId')->willReturn(7);

        $nonGsr = $this->createMock(Member::class);
        $nonGsr->method('isGSR')->willReturn(false);
        $nonGsr->method('getId')->willReturn(8);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([$gsr, $nonGsr]);
        $this->groupViewFactory->method('createFrom')->willReturn($view);

        $member = $this->createMock(Member::class);
        $member->method('getAnonymousName')->willReturn('Anonymous Alex');
        $this->memberRepository->method('findById')->willReturn($member);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42));

        $this->assertStringContainsString('Anonymous Alex', $html);
    }

    /** @test */
    public function the_gsrs_column_dashes_without_a_group(): void
    {
        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)));
    }

    /** @test */
    public function the_gsrs_column_dashes_when_the_group_has_no_gsrs(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([]);
        $this->groupViewFactory->method('createFrom')->willReturn($view);

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)));
    }

    /** @test */
    public function a_gsr_whose_member_record_is_missing_is_skipped(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);

        $gsr = $this->createMock(Member::class);
        $gsr->method('isGSR')->willReturn(true);
        $gsr->method('getId')->willReturn(7);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([$gsr]);
        $this->groupViewFactory->method('createFrom')->willReturn($view);

        // findById returns null → the sole GSR drops out → dash.
        $this->memberRepository->method('findById')->willReturn(null);

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)));
    }

    // ── sorting ──────────────────────────────────────────────────────

    /** @test */
    public function sorting_by_group_rewrites_the_query_to_a_meta_sort(): void
    {
        $query = $this->meetingScreenQuery('group');

        $this->admin->handleCustomSorting($query);

        $this->assertSame('meta_value_num', $query->get('orderby'));
        $this->assertNotEmpty($query->get('meta_query'));
    }

    /** @test */
    public function sorting_is_left_alone_for_a_secondary_query(): void
    {
        $this->setScreen('edit-' . self::MEETING_TYPE, 'edit', self::MEETING_TYPE);
        $query = new WP_Query(['orderby' => 'group']);
        $query->isMainQuery = false;   // e.g. a widget query on the same screen

        $this->admin->handleCustomSorting($query);

        $this->assertSame('group', $query->get('orderby'));
    }

    /** @test */
    public function the_gsrs_column_dashes_when_the_group_view_is_missing(): void
    {
        $this->setPostMeta(42, self::GROUP_META, 100);
        $this->groupViewFactory->method('createFrom')->willReturn(null);

        $this->assertStringContainsString('—', $this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)));
    }

    /** @test */
    public function sorting_is_left_alone_off_the_meeting_screen(): void
    {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['orderby' => 'group']);
        $query->isMainQuery = true;

        $this->admin->handleCustomSorting($query);

        $this->assertSame('group', $query->get('orderby'));
    }

    // ── search rewrite ───────────────────────────────────────────────

    /** @test */
    public function a_group_search_extends_join_where_and_distinct(): void
    {
        $query = $this->meetingScreenQuery('', true, 'treasurer');

        $join = $this->admin->searchJoin('', $query);
        $where = $this->admin->searchWhere("(wp_posts.post_title LIKE '%treasurer%')", $query);
        $distinct = $this->admin->searchDistinct('', $query);

        $this->assertStringContainsString('group_post', $join);
        $this->assertStringContainsString('group_post.post_title LIKE', $where);
        $this->assertSame('DISTINCT', $distinct);
    }

    /** @test */
    public function the_search_rewrite_is_skipped_when_it_is_not_a_search(): void
    {
        $query = $this->meetingScreenQuery('', false);

        $this->assertSame('JOIN', $this->admin->searchJoin('JOIN', $query));
        $this->assertSame('WHERE', $this->admin->searchWhere('WHERE', $query));
        $this->assertSame('', $this->admin->searchDistinct('', $query));
    }

    /** @test */
    public function a_search_with_no_term_leaves_the_where_untouched(): void
    {
        $query = $this->meetingScreenQuery('', true, '');

        $this->assertSame('WHERE', $this->admin->searchWhere('WHERE', $query));
    }
}
