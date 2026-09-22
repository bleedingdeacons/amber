<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Meetings;

use Amber\Admin\Meetings\MeetingAdmin;
use Unity\Core\Interfaces\Configuration;
use Unity\Groups\Interfaces\Group;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupView;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Query;
use WP_Screen;

/*
 * Tests for the meeting list-table customisations.
 *
 * MeetingAdmin bolts three columns onto TSML's meeting list — Group, GSRs and
 * Email — and teaches the list to sort and search by the meeting's group even
 * though the group lives in a separate post joined only through post meta. The
 * column painters each have a distinct "no data" fallback that officers rely on
 * to spot unlinked meetings, and the search rewrite only fires for a real
 * search on the meeting screen, so the gate that decides that is exercised on
 * its own.
 */

covers(MeetingAdmin::class);

const MEETING_TYPE = 'tsml_meeting';
const MEETING_GROUP_META = 'group_id';
const MEETING_GROUP_TYPE = 'tsml_group';

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturn([
        'POST_TYPE'       => MEETING_TYPE,
        'GROUP_META_KEY'  => MEETING_GROUP_META,
        'GROUP_POST_TYPE' => MEETING_GROUP_TYPE,
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

    $this->meetingScreenQuery = function (string $orderby = '', bool $search = false, string $term = ''): WP_Query {
        $this->setScreen('edit-' . MEETING_TYPE, 'edit', MEETING_TYPE);

        $query = new WP_Query(['orderby' => $orderby, 's' => $term]);
        $query->isMainQuery = true;
        $query->isSearch    = $search;

        return $query;
    };
});

// ── columns ──────────────────────────────────────────────────────
describe('columns', function () {
    it('inserts the group, gsr and email columns after time', function () {
        $columns = $this->admin->addCustomColumns([
            'title'       => 'Title',
            'time'        => 'Time',
            'data_source' => 'Data Source',
            'region'      => 'Region',
        ]);

        // TSML's own columns are dropped; ours land immediately after time.
        expect($columns)->not->toHaveKey('data_source')
            ->not->toHaveKey('region');
        $keys = array_keys($columns);
        expect($keys)->toBe(['title', 'time', 'group', 'gsrs', 'email']);
    });

    it('makes the group column sortable', function () {
        expect($this->admin->makeSortableColumns([])['group'])->toBe('group');
    });

    it('shows the group and email columns by default on the meeting screen', function () {
        $screen = new WP_Screen(['id' => 'edit-' . MEETING_TYPE]);

        $hidden = $this->admin->setDefaultHiddenColumns(['group', 'email', 'author'], $screen);

        expect($hidden)->not->toContain('group')
            ->not->toContain('email')
            ->toContain('author');
    });

    it('leaves default hidden columns untouched on other screens', function () {
        $screen = new WP_Screen(['id' => 'edit-page']);

        expect($this->admin->setDefaultHiddenColumns(['group'], $screen))->toBe(['group']);
    });
});

// ── group column ─────────────────────────────────────────────────
describe('group column', function () {
    it('shows the group title', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('Tuesday Group');
        $this->groupRepository->method('findById')->willReturn($group);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group', 42));

        expect($html)->toContain('Tuesday Group');
    });

    it('shows n/a when the meeting has no group', function () {
        $html = $this->capture(fn () => $this->admin->populateCustomColumns('group', 42));

        expect($html)->toContain('N/A');
    });

    it('shows n/a when the group has no title', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn('');
        $this->groupRepository->method('findById')->willReturn($group);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('group', 42)))->toContain('N/A');
    });
});

// ── email column ─────────────────────────────────────────────────
describe('email column', function () {
    it('links the group email', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getEmail')->willReturn('grp@example.test');
        $this->groupRepository->method('findById')->willReturn($group);

        $html = $this->capture(fn () => $this->admin->populateCustomColumns('email', 42));

        expect($html)->toContain('mailto:grp@example.test');
    });

    it('dashes when there is no group', function () {
        expect($this->capture(fn () => $this->admin->populateCustomColumns('email', 42)))->toContain('—');
    });

    it('dashes when the group has no email', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);
        $group = $this->createMock(Group::class);
        $group->method('getEmail')->willReturn('');
        $this->groupRepository->method('findById')->willReturn($group);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('email', 42)))->toContain('—');
    });
});

// ── GSRs column ──────────────────────────────────────────────────
describe('gsrs column', function () {
    it('links each gsr in the group', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);

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

        expect($html)->toContain('Anonymous Alex');
    });

    it('dashes without a group', function () {
        expect($this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)))->toContain('—');
    });

    it('dashes when the group has no gsrs', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([]);
        $this->groupViewFactory->method('createFrom')->willReturn($view);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)))->toContain('—');
    });

    it('skips a gsr whose member record is missing', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);

        $gsr = $this->createMock(Member::class);
        $gsr->method('isGSR')->willReturn(true);
        $gsr->method('getId')->willReturn(7);

        $view = $this->createMock(GroupView::class);
        $view->method('getMembers')->willReturn([$gsr]);
        $this->groupViewFactory->method('createFrom')->willReturn($view);

        // findById returns null → the sole GSR drops out → dash.
        $this->memberRepository->method('findById')->willReturn(null);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)))->toContain('—');
    });

    it('dashes when the group view is missing', function () {
        $this->setPostMeta(42, MEETING_GROUP_META, 100);
        $this->groupViewFactory->method('createFrom')->willReturn(null);

        expect($this->capture(fn () => $this->admin->populateCustomColumns('gsrs', 42)))->toContain('—');
    });
});

// ── sorting ──────────────────────────────────────────────────────
describe('sorting', function () {
    it('rewrites the query to a meta sort when sorting by group', function () {
        $query = ($this->meetingScreenQuery)('group');

        $this->admin->handleCustomSorting($query);

        expect($query->get('orderby'))->toBe('meta_value_num')
            ->and($query->get('meta_query'))->not->toBeEmpty();
    });

    it('leaves sorting alone for a secondary query', function () {
        $this->setScreen('edit-' . MEETING_TYPE, 'edit', MEETING_TYPE);
        $query = new WP_Query(['orderby' => 'group']);
        $query->isMainQuery = false;   // e.g. a widget query on the same screen

        $this->admin->handleCustomSorting($query);

        expect($query->get('orderby'))->toBe('group');
    });

    it('leaves sorting alone off the meeting screen', function () {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['orderby' => 'group']);
        $query->isMainQuery = true;

        $this->admin->handleCustomSorting($query);

        expect($query->get('orderby'))->toBe('group');
    });
});

// ── search rewrite ───────────────────────────────────────────────
describe('search rewrite', function () {
    it('extends join, where and distinct for a group search', function () {
        $query = ($this->meetingScreenQuery)('', true, 'treasurer');

        $join = $this->admin->searchJoin('', $query);
        $where = $this->admin->searchWhere("(wp_posts.post_title LIKE '%treasurer%')", $query);
        $distinct = $this->admin->searchDistinct('', $query);

        expect($join)->toContain('group_post')
            // Not just that the alias is there: the join has to carry a real post
            // type. It once carried '' and therefore matched nothing.
            ->toContain("group_post.post_type = '" . MEETING_GROUP_TYPE . "'")
            ->and($where)->toContain('group_post.post_title LIKE')
            ->and($distinct)->toBe('DISTINCT');
    });

    it('is skipped when it is not a search', function () {
        $query = ($this->meetingScreenQuery)('', false);

        expect($this->admin->searchJoin('JOIN', $query))->toBe('JOIN')
            ->and($this->admin->searchWhere('WHERE', $query))->toBe('WHERE')
            ->and($this->admin->searchDistinct('', $query))->toBe('');
    });

    it('leaves the where untouched for a search with no term', function () {
        $query = ($this->meetingScreenQuery)('', true, '');

        expect($this->admin->searchWhere('WHERE', $query))->toBe('WHERE');
    });
});

// ── missing GROUP_POST_TYPE ──────────────────────────────────────
//
// The provider publishes this key; it once did not. A missing key is not
// an error PHP stops for -- it lands in the join as post_type = '', which
// matches no row, so group-name search returns nothing and still looks
// like it worked. These pin the fallback that keeps it working and the
// warning that makes it visible.
describe('missing GROUP_POST_TYPE', function () {
    beforeEach(function () {
        /** Builds an admin whose meeting config omits GROUP_POST_TYPE. */
        $this->adminWithoutGroupPostType = function (): MeetingAdmin {
            $config = $this->createMock(Configuration::class);
            $config->method('getConfig')->willReturn([
                'POST_TYPE'      => MEETING_TYPE,
                'GROUP_META_KEY' => MEETING_GROUP_META,
            ]);

            return new MeetingAdmin(
                $config,
                $this->groupRepository,
                $this->groupViewFactory,
                $this->memberRepository
            );
        };
    });

    it('falls back rather than joining on nothing', function () {
        $admin = ($this->adminWithoutGroupPostType)();
        $query = ($this->meetingScreenQuery)('', true, 'treasurer');

        $join = $admin->searchJoin('', $query);

        expect($join)->toContain("group_post.post_type = '" . MEETING_GROUP_TYPE . "'")
            ->not->toContain("group_post.post_type = ''");
    });

    it('still references the alias the join provides in the where', function () {
        // The two have to agree: searchWhere() names group_post unconditionally,
        // so searchJoin() must always supply it or the SQL is invalid.
        $admin = ($this->adminWithoutGroupPostType)();
        $query = ($this->meetingScreenQuery)('', true, 'treasurer');

        $join  = $admin->searchJoin('', $query);
        $where = $admin->searchWhere("(wp_posts.post_title LIKE '%treasurer%')", $query);

        expect($join)->toContain('AS group_post')
            ->and($where)->toContain('group_post.post_title LIKE');
    });
});
