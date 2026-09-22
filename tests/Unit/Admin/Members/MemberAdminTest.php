<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Amber\Admin\Members\MemberAdmin;
use BleedingDeacons\WpMocks\WpState;
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

/*
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
 */

covers(MemberAdmin::class);

const MEMBER_ADMIN_TYPE = 'intergroup-member';
const MEMBER_ADMIN_POSITION_TYPE = 'intergroup-position';
const MEMBER_ADMIN_GROUP_TYPE = 'tsml_group';

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(static function (string $key): array {
        return match ($key) {
            Member::class => [
                'POST_TYPE'                  => MEMBER_ADMIN_TYPE,
                'FIELD_INTERGROUP_POSITION'  => 'service-layout-group_intergroup-position',
                'FIELD_HOME_GROUP'           => 'home-layout-group_home-group',
                'FIELD_HOMEGROUP_GSR'        => 'home-layout-group_homegroup-gsr',
            ],
            Position::class => ['POST_TYPE' => MEMBER_ADMIN_POSITION_TYPE],
            Group::class    => ['POST_TYPE' => MEMBER_ADMIN_GROUP_TYPE],
            default         => [],
        };
    });

    $this->members = $this->createMock(MemberRepository::class);
    $this->positions = $this->createMock(PositionFactory::class);
    $this->groups = $this->createMock(GroupFactory::class);

    $this->admin = new MemberAdmin($config, $this->positions, $this->members, $this->groups);

    /** A member with the getters the list table reads. */
    $this->member = function (array $overrides = []): Member {
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
    };

    $this->position = function (string $longName = 'Treasurer'): Position {
        $position = $this->createMock(Position::class);
        $position->method('getLongName')->willReturn($longName);

        return $position;
    };

    $this->group = function (string $title = 'Tuesday Group', array $meetings = []): Group {
        $group = $this->createMock(Group::class);
        $group->method('getTitle')->willReturn($title);
        $group->method('getMeetings')->willReturn($meetings);

        return $group;
    };

    $this->column = function (string $name, int $postId = 42): string {
        return $this->capture(fn () => $this->admin->populateCustomColumns($name, $postId));
    };
});

// ── registration ─────────────────────────────────────────────────
it('registers its list table hooks', function () {
    $this->assertHookAdded('manage_' . MEMBER_ADMIN_TYPE . '_posts_columns');
    $this->assertHookAdded('manage_' . MEMBER_ADMIN_TYPE . '_posts_custom_column');
    $this->assertHookAdded('manage_edit-' . MEMBER_ADMIN_TYPE . '_sortable_columns');
    $this->assertHookAdded('save_post_' . MEMBER_ADMIN_TYPE);
    $this->assertHookAdded('restrict_manage_posts');
    $this->assertHookAdded('pre_get_posts');
});

// ── columns ──────────────────────────────────────────────────────
describe('columns', function () {
    it('inserts the custom columns after the title', function () {
        $columns = $this->admin->addCustomColumns(['cb' => '', 'title' => 'Title', 'date' => 'Date']);

        $keys = array_keys($columns);
        expect($keys[0])->toBe('cb')
            ->and($keys[1])->toBe('title')
            // The title is relabelled, since a member's title is their pseudonym.
            ->and($columns['title'])->toBe('Anonymous Name');

        foreach (['service_position', 'rotation_date', 'gsr_status', 'homegroup', 'twelfth', 'responder', 'certification'] as $added) {
            expect($columns)->toHaveKey($added);
        }
        // Pre-existing columns survive.
        expect($columns)->toHaveKey('date');
    });

    it('reads not applicable for a member that cannot be loaded', function () {
        $this->members->method('findById')->willReturn(null);

        expect(($this->column)('gsr_status'))->toContain('N/A');
    });

    it('marks a gsr in the gsr column', function () {
        $this->members->method('findById')->willReturn(($this->member)(['isGsr' => true]));

        expect(($this->column)('gsr_status'))->toContain('Yes');
    });

    it('marks a non gsr in the gsr column', function () {
        $this->members->method('findById')->willReturn(($this->member)(['isGsr' => false]));

        expect(($this->column)('gsr_status'))->toContain('No');
    });

    it('reports the twelfth and responder flags', function () {
        $this->members->method('findById')->willReturn(
            ($this->member)(['isTwelfthStepper' => true, 'isTelephoneResponder' => true])
        );

        expect(($this->column)('twelfth'))->toContain('Yes')
            ->and(($this->column)('responder'))->toContain('Yes');
    });

    it('links to the position in the service position column', function () {
        $this->members->method('findById')->willReturn(($this->member)(['getIntergroupPosition' => 7]));
        $this->positions->method('createFromSource')->willReturn(($this->position)('Intergroup Treasurer'));

        $html = ($this->column)('service_position');

        expect($html)->toContain('Intergroup Treasurer')
            ->toContain('<a href=');
    });

    it('shows not applicable for a member with no position', function () {
        $this->members->method('findById')->willReturn(($this->member)());
        $this->positions->method('createFromSource')->willReturn(null);

        expect(($this->column)('service_position'))->toContain('N/A');
    });

    it('shows the date or a dash in the rotation column', function () {
        $this->members->method('findById')->willReturn(
            ($this->member)(['getIntergroupPositionRotation' => '01/01/2027'])
        );
        expect(($this->column)('rotation_date'))->toContain('01/01/2027');
    });

    it("links the homegroup column via the group's first meeting", function () {
        // Groups have no edit screen of their own, so the link goes to a
        // meeting the group holds.
        $meeting = $this->createMock(Meeting::class);
        $meeting->method('getId')->willReturn(99);

        $this->members->method('findById')->willReturn(($this->member)(['getHomeGroup' => 3]));
        $this->groups->method('createFromSource')->willReturn(($this->group)('Tuesday Group', [$meeting]));

        $html = ($this->column)('homegroup');

        expect($html)->toContain('Tuesday Group')
            ->toContain('post=99');
    });

    it('renders a homegroup with no meetings as plain text', function () {
        $this->members->method('findById')->willReturn(($this->member)(['getHomeGroup' => 3]));
        $this->groups->method('createFromSource')->willReturn(($this->group)('Tuesday Group', []));

        $html = ($this->column)('homegroup');

        expect($html)->toContain('Tuesday Group')
            ->not->toContain('<a href=');
    });

    it('shows not applicable for an unknown homegroup', function () {
        $this->members->method('findById')->willReturn(($this->member)(['getHomeGroup' => 0]));
        $this->groups->method('createFromSource')->willReturn(null);

        expect(($this->column)('homegroup'))->toContain('N/A');
    });
});

// ── certification column ─────────────────────────────────────────
describe('certification column', function () {
    it('shows a dash rather than none for a non responder', function () {
        // The backing field is hidden for non-responders, so every one of
        // them reads as None; showing "None" would imply a responder who has
        // not started.
        $this->members->method('findById')->willReturn(
            ($this->member)(['isTelephoneResponder' => false])
        );

        $html = ($this->column)('certification');

        expect($html)->toContain('—')
            ->not->toContain('None');
    });

    it('gives each certification stage its colour', function (
        ResponderCertification $stage,
        string $expectedColour
    ) {
        $this->members->method('findById')->willReturn(($this->member)([
            'isTelephoneResponder' => true,
            'getResponderCertification' => $stage,
        ]));

        $html = ($this->column)('certification');

        expect($html)->toContain($stage->label())
            ->toContain($expectedColour);
    })->with([
        'certified reads as a pass'   => [ResponderCertification::Certified, 'green'],
        'applied is in progress'      => [ResponderCertification::Applied, '#996800'],
        'in training is in progress'  => [ResponderCertification::InTraining, '#996800'],
        'pending is in progress'      => [ResponderCertification::Pending, '#996800'],
        'none is neutral'             => [ResponderCertification::None, 'gray'],
    ]);
});

// ── sorting ──────────────────────────────────────────────────────
describe('sorting', function () {
    it('declares the sortable columns', function () {
        $sortable = $this->admin->makeSortableColumns([]);

        foreach (['gsr_status', 'service_position', 'rotation_date', 'homegroup'] as $column) {
            expect($sortable)->toHaveKey($column);
        }
    });

    // Each sortable column maps to a precomputed meta key, because the value
    // shown lives behind a factory and WordPress cannot order by it.
    it('orders by the precomputed meta key of the column', function (
        string $orderby,
        string $metaKey,
        string $orderType
    ) {
        $query = new WP_Query(['post_type' => MEMBER_ADMIN_TYPE, 'orderby' => $orderby]);

        $this->admin->handleCustomSorting($query);

        expect($query->get('meta_key'))->toBe($metaKey)
            ->and($query->get('orderby'))->toBe($orderType);
    })->with([
        'gsr'      => ['gsr_status', '_member_gsr_sort', 'meta_value_num'],
        'position' => ['service_position', '_member_position_sort_name', 'meta_value'],
        'rotation' => ['rotation_date', '_member_rotation_date_sort', 'meta_value'],
        'homegroup' => ['homegroup', '_member_homegroup_sort_name', 'meta_value'],
    ]);

    it('leaves sorting alone for another post type', function () {
        $query = new WP_Query(['post_type' => 'page', 'orderby' => 'gsr_status']);

        $this->admin->handleCustomSorting($query);

        expect($query->get('meta_key'))->toBe('');
    });

    it('leaves sorting alone when not the main query', function () {
        $query = new WP_Query(['post_type' => MEMBER_ADMIN_TYPE, 'orderby' => 'gsr_status']);
        $query->isMainQuery = false;

        $this->admin->handleCustomSorting($query);

        expect($query->get('meta_key'))->toBe('');
    });

    it('passes an unrecognised sort column through untouched', function () {
        $query = new WP_Query(['post_type' => MEMBER_ADMIN_TYPE, 'orderby' => 'title']);

        $this->admin->handleCustomSorting($query);

        expect($query->get('orderby'))->toBe('title')
            ->and($query->get('meta_key'))->toBe('');
    });
});

// ── GSR filter ───────────────────────────────────────────────────
describe('GSR filter', function () {
    it('renders the gsr filter dropdown for members only', function () {
        $html = $this->capture(fn () => $this->admin->addGsrFilterDropdown(MEMBER_ADMIN_TYPE));

        expect($html)->toContain('<select name="gsr_filter">')
            ->toContain('Is GSR')
            ->toContain('Not GSR')
            ->and($this->capture(fn () => $this->admin->addGsrFilterDropdown('page')))->toBe('');
    });

    it('remembers the current selection in the dropdown', function () {
        $_GET['gsr_filter'] = 'yes';

        $html = $this->capture(fn () => $this->admin->addGsrFilterDropdown(MEMBER_ADMIN_TYPE));

        expect($html)->toContain('selected="selected"');
    });

    it('adds an equality meta query when filtering to gsrs', function () {
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $_GET['gsr_filter'] = 'yes';
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        $metaQuery = $query->get('meta_query');
        expect($metaQuery[0]['key'])->toBe('home-layout-group_homegroup-gsr')
            ->and($metaQuery[0]['compare'])->toBe('=');
    });

    it('also matches members with no value when filtering to non gsrs', function () {
        // A member who has never been a GSR has no row at all, so a plain
        // "!= 1" would miss them.
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $_GET['gsr_filter'] = 'no';
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        $metaQuery = $query->get('meta_query');
        expect($metaQuery[0]['relation'])->toBe('OR')
            ->and($metaQuery[0][1]['compare'])->toBe('NOT EXISTS');
    });

    it('applies no filter without a selection', function () {
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        expect($query->get('meta_query'))->toBe('');
    });

    it('skips the filter on another screen', function () {
        $this->setScreen('edit-page', 'edit', 'page');
        $_GET['gsr_filter'] = 'yes';
        $query = new WP_Query([]);

        $this->admin->filterByGsrStatus($query);

        expect($query->get('meta_query'))->toBe('');
    });
});

// ── extended search ──────────────────────────────────────────────
describe('extended search', function () {
    it('extends search to members linked to matching positions', function () {
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $query = new WP_Query(['s' => 'treasurer']);
        $query->isSearch = true;

        // Position lookup, group lookup, members-by-position,
        // members-by-group, then the title matches.
        $this->wpdb->col = [7];

        $this->admin->extendSearch($query);

        // The term is cleared and replaced by an explicit id list, so the
        // extra matches are not filtered back out by the title search.
        expect($query->get('s'))->toBe('')
            ->and($query->get('post__in'))->not->toBeEmpty();
    });

    it('leaves search alone when nothing extra matches', function () {
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $query = new WP_Query(['s' => 'nothing']);
        $query->isSearch = true;
        $this->wpdb->col = [];

        $this->admin->extendSearch($query);

        expect($query->get('s'))->toBe('nothing', 'WordPress keeps its own title search.');
    });

    it('skips search for an empty term', function () {
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $query = new WP_Query(['s' => '']);
        $query->isSearch = true;

        $this->admin->extendSearch($query);

        expect($this->wpdb->queries)->toBe([], 'No term, no lookups.');
    });

    it('skips search when the query is not a search', function () {
        $this->setScreen('edit-member', 'edit', MEMBER_ADMIN_TYPE);
        $query = new WP_Query(['s' => 'treasurer']);
        $query->isSearch = false;

        $this->admin->extendSearch($query);

        expect($this->wpdb->queries)->toBe([]);
    });

    it('skips search on another post type screen', function () {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['s' => 'treasurer']);
        $query->isSearch = true;

        $this->admin->extendSearch($query);

        expect($this->wpdb->queries)->toBe([]);
    });
});

// ── sort metadata ────────────────────────────────────────────────
describe('sort metadata', function () {
    it('precomputes every sort key when saving a member', function () {
        $meeting = $this->createMock(Meeting::class);
        $meeting->method('getId')->willReturn(99);

        $this->members->method('findById')->willReturn(($this->member)([
            'isGsr' => true,
            'getIntergroupPosition' => 7,
            'getIntergroupPositionRotation' => '01/03/2027',
            'getHomeGroup' => 3,
        ]));
        $this->positions->method('createFromSource')->willReturn(($this->position)('Treasurer'));
        $this->groups->method('createFromSource')->willReturn(($this->group)('Tuesday Group', [$meeting]));

        $this->admin->updateMemberMetadata(42);

        $meta = WpState::$postMeta[42];
        expect($meta['_member_gsr_sort'])->toBe(1)
            ->and($meta['_member_position_sort_name'])->toBe('treasurer', 'lower-cased for a stable sort')
            // d/m/Y is reordered so a string sort is a chronological sort.
            ->and($meta['_member_rotation_date_sort'])->toBe('2027-03-01')
            ->and($meta['_member_homegroup_sort_name'])->toBe('tuesday group');
    });

    it('sorts a member with nothing set last', function () {
        $this->members->method('findById')->willReturn(($this->member)());
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->admin->updateMemberMetadata(42);

        $meta = WpState::$postMeta[42];
        expect($meta['_member_gsr_sort'])->toBe(0)
            // A sentinel that sorts after every real name.
            ->and($meta['_member_position_sort_name'])->toBe('zzz_none')
            ->and($meta['_member_rotation_date_sort'])->toBe('zzz_none')
            ->and($meta['_member_homegroup_sort_name'])->toBe('zzz_none');
    });

    it('stores an unparseable rotation date as given', function () {
        $this->members->method('findById')->willReturn(
            ($this->member)(['getIntergroupPositionRotation' => 'sometime soon'])
        );
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->admin->updateMemberMetadata(42);

        expect(WpState::$postMeta[42]['_member_rotation_date_sort'])->toBe('sometime soon');
    });

    it('writes no metadata for a member that cannot be loaded', function () {
        $this->members->method('findById')->willReturn(null);

        $this->admin->updateMemberMetadata(42);

        expect(WpState::$postMeta)->not->toHaveKey(42);
    });

    it('recomputes the sort keys on save', function () {
        $this->members->method('findById')->willReturn(($this->member)());
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        $this->admin->updateMemberMetadataOnSave(42, new WP_Post(['ID' => 42]), true);

        expect(WpState::$postMeta[42])->toHaveKey('_member_gsr_sort');
    });

    it('ignores an ajax save', function () {
        // ACF fires save_post over AJAX mid-edit; recomputing then would use
        // half-written field values.
        WpState::$doingAjax = true;
        $this->members->expects($this->never())->method('findById');

        $this->admin->updateMemberMetadataOnSave(42, new WP_Post(['ID' => 42]), true);

        expect(WpState::$postMeta)->not->toHaveKey(42);
    });

    it('can backfill every member at once', function () {
        $this->members->method('findAll')->willReturn([
            ($this->member)(['getId' => 1]),
            ($this->member)(['getId' => 2]),
        ]);
        $this->members->method('findById')->willReturn(($this->member)());
        $this->positions->method('createFromSource')->willReturn(null);
        $this->groups->method('createFromSource')->willReturn(null);

        expect($this->admin->setupAllMembersMetadata())->toBe(2);
    });
});
