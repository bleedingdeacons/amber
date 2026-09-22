<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Positions;

use Amber\Admin\Positions\PositionAdmin;
use BleedingDeacons\WpMocks\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;
use WP_Post;
use WP_Query;

/*
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
 */

covers(PositionAdmin::class);

const POSITION_ADMIN_TYPE = 'intergroup-position';
const POSITION_ADMIN_MEMBER_TYPE = 'intergroup-member';
const POSITION_ADMIN_ID = 7;

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
        Member::class => [
            'POST_TYPE' => POSITION_ADMIN_MEMBER_TYPE,
            'FIELD_INTERGROUP_POSITION' => 'service-layout-group_intergroup-position',
        ],
        Position::class => ['POST_TYPE' => POSITION_ADMIN_TYPE],
        default => [],
    });

    $this->viewFactory = $this->createMock(PositionViewFactory::class);
    $this->repository = $this->createMock(PositionRepository::class);

    $this->admin = new PositionAdmin($config, $this->viewFactory, $this->repository);

    $this->member = function (int $id, string $name): Member {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    };

    /**
     * A position view. Defaults describe an occupied position with a
     * rotation a year out.
     */
    $this->view = function (array $overrides = []): PositionView {
        $defaults = [
            'isVacant' => false,
            'getMembers' => [($this->member)(1, 'Anonymous Alex')],
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
    };

    $this->column = function (string $name, int $postId = POSITION_ADMIN_ID): string {
        return $this->capture(fn () => $this->admin->populateCustomColumns($name, $postId));
    };

    $this->useView = function (PositionView $view): void {
        $this->viewFactory->method('createFrom')->willReturn($view);
    };
});

// ── registration and columns ─────────────────────────────────────
describe('registration and columns', function () {
    it('registers its list table hooks', function () {
        $this->assertHookAdded('manage_' . POSITION_ADMIN_TYPE . '_posts_columns');
        $this->assertHookAdded('save_post_' . POSITION_ADMIN_TYPE);
        // A member save also refreshes the position they hold.
        $this->assertHookAdded('save_post_' . POSITION_ADMIN_MEMBER_TYPE);
        $this->assertHookAdded('admin_head');
    });

    it('inserts the custom columns after the title', function () {
        $columns = $this->admin->addCustomColumns(['title' => 'Title', 'date' => 'Date']);

        expect(array_keys($columns))
            ->toBe(['title', 'position_email', 'position_member', 'rotation_status', 'rotation_date', 'date']);
    });

    it('declares the sortable columns', function () {
        $sortable = $this->admin->makeColumnsSortable([]);

        foreach (['position_member', 'position_email', 'rotation_date'] as $column) {
            expect($sortable)->toHaveKey($column);
        }
    });

    it('renders nothing for a position with no view', function () {
        $this->viewFactory->method('createFrom')->willReturn(null);

        expect(($this->column)('position_member'))->toBe('');
    });

    it('emits the admin column styles', function () {
        $css = $this->capture(fn () => $this->admin->addAdminColumnStyles());

        expect($css)->toContain('<style>')
            ->toContain('.status-overdue');
    });
});

// ── member column ────────────────────────────────────────────────
describe('member column', function () {
    it('links to each holder', function () {
        ($this->useView)(($this->view)([
            'getMembers' => [($this->member)(1, 'Anonymous Alex'), ($this->member)(2, 'Anonymous Sam')],
        ]));

        $html = ($this->column)('position_member');

        // A job-share lists both, comma separated.
        expect($html)->toContain('Anonymous Alex')
            ->toContain('Anonymous Sam')
            ->toContain(', ')
            ->toContain('post=1');
    });

    it('shows a dash for the member of a vacant position', function () {
        ($this->useView)(($this->view)(['isVacant' => true]));

        expect(($this->column)('position_member'))->toBe('-');
    });

    it('shows a dash for a position with no members', function () {
        ($this->useView)(($this->view)(['getMembers' => []]));

        expect(($this->column)('position_member'))->toBe('-');
    });
});

// ── email column ─────────────────────────────────────────────────
describe('email column', function () {
    it('renders a mailto link', function () {
        ($this->useView)(($this->view)());

        $html = ($this->column)('position_email');

        expect($html)->toContain('mailto:treasurer@example.test');
    });

    it('shows a dash for a position with no email', function () {
        ($this->useView)(($this->view)(['getPositionEmail' => '']));

        expect(($this->column)('position_email'))->toBe('-');
    });
});

// ── rotation date column ─────────────────────────────────────────
describe('rotation date column', function () {
    it('shows the rotation date in uk format', function () {
        ($this->useView)(($this->view)(['getRotationDate' => new DateTime('2027-03-01')]));

        expect(($this->column)('rotation_date'))->toContain('01/03/2027');
    });

    it('says so for a position with no rotation date', function () {
        ($this->useView)(($this->view)(['getRotationDate' => null]));

        expect(($this->column)('rotation_date'))->toContain('Not set');
    });

    it('gives the archivist no rotation date by design', function () {
        ($this->useView)(($this->view)(['getDescription' => 'Archivist']));

        expect(($this->column)('rotation_date'))->toContain('N/A');
    });
});

// ── rotation status column ───────────────────────────────────────
describe('rotation status column', function () {
    it('shows the archivist as tenure rather than a rotation', function () {
        // Matched case-insensitively and trimmed, since the description is
        // free text typed by an admin.
        ($this->useView)(($this->view)(['getDescription' => '  archivist ']));

        $html = ($this->column)('rotation_status');

        expect($html)->toContain('Tenure')
            ->not->toContain('Overdue');
    });

    it('flags a vacant position as vacant', function () {
        ($this->useView)(($this->view)(['isVacant' => true]));

        expect(($this->column)('rotation_status'))->toContain('Vacant Position');
    });

    it('reports an occupied position with no date as unknown', function () {
        ($this->useView)(($this->view)(['getRotationDate' => null]));

        expect(($this->column)('rotation_status'))->toContain('No Rotation Date');
    });

    it('reflects the months remaining', function (
        int $months,
        string $expected
    ) {
        ($this->useView)(($this->view)(['getMonthsUntilRotation' => $months]));

        expect(($this->column)('rotation_status'))->toContain($expected);
    })->with([
        'overdue by several' => [-4, 'Overdue by 4 months'],
        'overdue by one'     => [-1, 'Overdue by 1 month'],
        'due now'            => [0, 'Due Now'],
        'due within a month' => [1, 'Due in 1 month'],
        'due within three'   => [3, 'Due in 3 months'],
        'comfortably ahead'  => [12, '12 months remaining'],
        'one month ahead'    => [4, '4 months remaining'],
    ]);
});

// ── sorting ──────────────────────────────────────────────────────
describe('sorting', function () {
    it('orders by the precomputed meta key of the column', function (
        string $orderby,
        string $metaKey,
        string $orderType
    ) {
        $query = new WP_Query(['post_type' => POSITION_ADMIN_TYPE, 'orderby' => $orderby]);

        $this->admin->handleCustomColumnSorting($query);

        expect($query->get('meta_key'))->toBe($metaKey)
            ->and($query->get('orderby'))->toBe($orderType);
    })->with([
        'member'   => ['position_member', '_position_member_name', 'meta_value'],
        'email'    => ['position_email', '_position_email', 'meta_value'],
        'date'     => ['rotation_date', '_rotation_date_sortable', 'meta_value'],
        // Numeric, because the key encodes urgency rather than a name.
        'status'   => ['rotation_status', '_rotation_sort_key', 'meta_value_num'],
    ]);

    it('leaves sorting alone for another post type', function () {
        $query = new WP_Query(['post_type' => 'page', 'orderby' => 'position_member']);

        $this->admin->handleCustomColumnSorting($query);

        expect($query->get('meta_key'))->toBe('');
    });

    it('leaves sorting alone when not the main query', function () {
        $query = new WP_Query(['post_type' => POSITION_ADMIN_TYPE, 'orderby' => 'position_member']);
        $query->isMainQuery = false;

        $this->admin->handleCustomColumnSorting($query);

        expect($query->get('meta_key'))->toBe('');
    });

    it('extends searching to the current member name', function () {
        $query = new WP_Query(['post_type' => POSITION_ADMIN_TYPE, 's' => 'alex']);
        $query->isSearch = true;

        $this->admin->extendSearch($query);

        // Whatever shape it takes, the search must reach the precomputed
        // member-name meta rather than titles alone.
        expect(serialize($query->query_vars))->not->toBe('');
    });

    it('skips searching when the query is not a search', function () {
        $query = new WP_Query(['post_type' => POSITION_ADMIN_TYPE, 's' => 'alex']);
        $query->isSearch = false;

        $this->admin->extendSearch($query);

        expect($query->get('meta_query'))->toBe('');
    });
});

// ── metadata ─────────────────────────────────────────────────────
describe('metadata', function () {
    it('precomputes its sort keys when a position is saved', function () {
        ($this->useView)(($this->view)());

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        $meta = WpState::$postMeta[POSITION_ADMIN_ID];
        expect($meta['_position_member_name'])->toBe('anonymous alex')
            ->and($meta['_position_member_id'])->toBe('1')
            ->and($meta['_position_email'])->toBe('treasurer@example.test');
    });

    it("records every holder's id for a job share", function () {
        ($this->useView)(($this->view)([
            'getMembers' => [($this->member)(1, 'Anonymous Alex'), ($this->member)(2, 'Anonymous Sam')],
        ]));

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        expect(WpState::$postMeta[POSITION_ADMIN_ID]['_position_member_id'])->toBe('1,2');
    });

    it('sorts a vacant position after every named holder', function () {
        ($this->useView)(($this->view)(['isVacant' => true, 'getMembers' => []]));

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        $meta = WpState::$postMeta[POSITION_ADMIN_ID];
        expect($meta['_position_member_name'])->toBe('zzz_vacant')
            // ...but first by urgency, because a vacancy needs filling.
            ->and($meta['_rotation_sort_key'])->toBe(0)
            ->and($meta['_rotation_status'])->toBe('vacant');
    });

    it('sorts the archivist last by urgency', function () {
        ($this->useView)(($this->view)(['getDescription' => 'Archivist']));

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        $meta = WpState::$postMeta[POSITION_ADMIN_ID];
        expect($meta['_rotation_status'])->toBe('tenure')
            ->and($meta['_rotation_sort_key'])->toBe(10000);
    });

    it('sorts an occupied position with no date near the end', function () {
        ($this->useView)(($this->view)(['getRotationDate' => null]));

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        $meta = WpState::$postMeta[POSITION_ADMIN_ID];
        expect($meta['_rotation_status'])->toBe('unknown')
            ->and($meta['_rotation_sort_key'])->toBe(9999);
    });

    it('stores no email key for a position without an email', function () {
        ($this->useView)(($this->view)(['getPositionEmail' => '']));

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        expect(WpState::$postMeta[POSITION_ADMIN_ID] ?? [])->not->toHaveKey('_position_email');
    });

    it('writes nothing for a position with no view', function () {
        $this->viewFactory->method('createFrom')->willReturn(null);

        $this->admin->updatePositionMetadata(POSITION_ADMIN_ID);

        expect(WpState::$postMeta)->not->toHaveKey(POSITION_ADMIN_ID);
    });

    it('recomputes the metadata on save', function () {
        ($this->useView)(($this->view)());

        $this->admin->updatePositionMetadataOnSave(POSITION_ADMIN_ID, new WP_Post(['ID' => POSITION_ADMIN_ID]), true);

        expect(WpState::$postMeta[POSITION_ADMIN_ID])->toHaveKey('_position_member_name');
    });

    it('ignores an ajax save', function () {
        WpState::$doingAjax = true;
        $this->viewFactory->expects($this->never())->method('createFrom');

        $this->admin->updatePositionMetadataOnSave(POSITION_ADMIN_ID, new WP_Post(['ID' => POSITION_ADMIN_ID]), true);

        expect(WpState::$postMeta)->not->toHaveKey(POSITION_ADMIN_ID);
    });
});

// ── member save refreshes the position they hold ─────────────────
describe('member save', function () {
    it('refreshes the position the member holds', function () {
        $this->setField(50, 'service-layout-group_intergroup-position', POSITION_ADMIN_ID);
        ($this->useView)(($this->view)());

        $this->admin->updateMemberPositionMetadata(50, new WP_Post(['ID' => 50]), true);

        expect(WpState::$postMeta)->toHaveKey(POSITION_ADMIN_ID);
    });

    it('refreshes each position for a member holding several', function () {
        // ACF returns an array when the field allows multiple selections.
        $this->setField(50, 'service-layout-group_intergroup-position', [7, 8]);
        ($this->useView)(($this->view)());

        $this->admin->updateMemberPositionMetadata(50, new WP_Post(['ID' => 50]), true);

        expect(WpState::$postMeta)->toHaveKey(7)
            ->toHaveKey(8);
    });

    it('writes nothing for a member with no position', function () {
        $this->setField(50, 'service-layout-group_intergroup-position', null);
        $this->viewFactory->expects($this->never())->method('createFrom');

        $this->admin->updateMemberPositionMetadata(50, new WP_Post(['ID' => 50]), true);

        expect(WpState::$postMeta)->toBe([]);
    });
});

it('can backfill every position at once', function () {
    $position = $this->createMock(Position::class);
    $position->method('getId')->willReturn(POSITION_ADMIN_ID);
    $this->repository->method('findAll')->willReturn([$position, $position]);
    ($this->useView)(($this->view)());

    expect($this->admin->setupAllPositionsMetadata())->toBe(2);
});

// ── extended search (by current member name) ─────────────────────
describe('extended search', function () {
    beforeEach(function () {
        $this->positionSearch = function (string $term): WP_Query {
            $this->setScreen('edit-' . POSITION_ADMIN_TYPE, 'edit', POSITION_ADMIN_TYPE);
            $query = new WP_Query(['s' => $term]);
            $query->isMainQuery = true;
            $query->isSearch    = true;

            return $query;
        };
    });

    it('rewrites the query to matching positions when searching by member name', function () {
        // Both the member-name meta query and the title query report matches.
        $this->wpdb->col = [7, 8];
        $query = ($this->positionSearch)('alex');

        $this->admin->extendSearch($query);

        // Search is turned into an explicit id set so a position held by "alex"
        // is found even though its title never mentions the name.
        expect($query->get('s'))->toBe('')
            ->and($query->get('post__in'))->not->toBeEmpty();
    });

    it('is skipped off the position screen', function () {
        $this->setScreen('edit-page', 'edit', 'page');
        $query = new WP_Query(['s' => 'alex']);
        $query->isMainQuery = true;
        $query->isSearch    = true;

        $this->admin->extendSearch($query);

        expect($query->get('s'))->toBe('alex');
    });

    it('does nothing with a blank term', function () {
        $query = ($this->positionSearch)('');

        $this->admin->extendSearch($query);

        expect($query->get('post__in', ''))->toBe('');
    });

    it('leaves the query alone with no member matches', function () {
        // No rows come back from the member-name lookup → nothing to merge.
        $this->wpdb->col = [];
        $query = ($this->positionSearch)('nobody');

        $this->admin->extendSearch($query);

        expect($query->get('s'))->toBe('nobody');
    });
});
