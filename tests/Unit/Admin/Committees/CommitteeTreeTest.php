<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Committees;

use Amber\Admin\Committees\CommitteeTree;
use Brain\Monkey\Functions;
use Unity\Committees\Interfaces\Committee;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/*
 * Tests for the committee tree screen.
 *
 * The cases that matter are the structural ones: that a committee's members
 * are printed under that committee and not repeated under its parents, that a
 * member with nothing filled in still renders, and that an empty taxonomy
 * produces a signpost rather than a blank page. A tree screen that silently
 * shows a person twice, or shows nothing at all, is worse than one that errors.
 */

covers(CommitteeTree::class);

/**
 * The markup of one member's move/copy select, by id.
 */
function selectFor(string $html, string $id): string
{
    $start = strpos($html, 'id="' . $id . '"');
    expect($start)->toBeInt('no select found with id ' . $id);

    $end = strpos($html, '</select>', $start);
    expect($end)->toBeInt();

    return substr($html, $start, $end - $start);
}

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(
        static fn (string $key): array => $key === Committee::class
            ? ['TAXONOMY' => 'intergroup-committee']
            : ['POST_TYPE' => 'intergroup-member']
    );

    $this->committees = $this->createMock(CommitteeRepository::class);
    $this->members    = $this->createMock(MemberRepository::class);

    // wp-mocks does not carry this one. Registered is the normal case; the
    // unregistered branch overrides it.
    Functions\when('taxonomy_exists')->justReturn(true);

    $this->tree = new CommitteeTree($config, $this->committees, $this->members);

    $this->committee = function (int $id, string $slug, string $name, int $parent = 0): Committee {
        $committee = $this->createMock(Committee::class);
        $committee->method('getId')->willReturn($id);
        $committee->method('getSlug')->willReturn($slug);
        $committee->method('getName')->willReturn($name);
        $committee->method('getParentId')->willReturn($parent);
        $committee->method('isRoot')->willReturn($parent === 0);

        return $committee;
    };

    $this->member = function (int $id, string $name): Member {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    };

    $this->render = function (): string {
        ob_start();

        try {
            $this->tree->render();
        } finally {
            // Closed in a finally so a throw inside render() does not leave the
            // buffer open and turn one failure into every later test reporting
            // "did not close its own output buffers".
            $html = (string) ob_get_clean();
        }

        return $html;
    };
});

// The taxonomy is defined in the ACF admin UI, so it lives in each site's
// database and an environment that never imported it arrives here with
// nothing registered. Found by opening the screen on a local site that had
// not been updated: it claimed "no committees exist yet" and linked to a
// term editor that answers "Invalid taxonomy."
it('says an unregistered taxonomy is unregistered rather than blaming missing terms', function () {
    Functions\when('taxonomy_exists')->justReturn(false);

    $this->committees->expects($this->never())->method('roots');

    $html = ($this->render)();

    expect($html)->toContain('is not registered on this site')
        ->toContain('intergroup-committee')
        ->toContain('ACF → Tools → Import')
        // Pointing at the term editor here would send somebody to WordPress's
        // bare "Invalid taxonomy." error.
        ->not->toContain('edit-tags.php');
});

it('points an empty taxonomy at the term editor instead of rendering a tree', function () {
    $this->committees->method('roots')->willReturn([]);
    $this->committees->expects($this->never())->method('findAll');

    $html = ($this->render)();

    expect($html)->toContain('No committees exist yet')
        ->toContain('edit-tags.php?taxonomy=intergroup-committee')
        ->not->toContain('amber-committee-tree');
});

it('renders a committee with its name and slug', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    $html = ($this->render)();

    expect($html)->toContain('data-committee="12"')
        ->toContain('Intergroup')
        ->toContain('intergroup')
        ->toContain('0 members');
});

// The rollup is deliberately off. memberIdsIn() includes descendants by
// default, which on a tree would print the same person under every
// ancestor and destroy the one thing the screen is for.
it('looks members up without the descendant rollup', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup]);

    $this->committees->expects($this->atLeastOnce())
        ->method('memberIdsIn')
        ->with($this->anything(), false)
        ->willReturn([]);

    $this->members->method('findAll')->willReturn([]);

    ($this->render)();
});

it('nests a child committee under its parent', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');
    $comms      = ($this->committee)(13, 'electronic-communications', 'Electronic Communications', 12);

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup, $comms]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    $html = ($this->render)();

    $parentAt = strpos($html, 'data-committee="12"');
    $childAt  = strpos($html, 'data-committee="13"');

    expect($parentAt)->toBeInt()
        ->and($childAt)->toBeInt()
        ->and($childAt)->toBeGreaterThan($parentAt, 'the child must render inside the parent')
        ->and($html)->toContain('Electronic Communications');
});

it('makes a member draggable and has it carry the committee it sits in', function () {
    $comms = ($this->committee)(13, 'electronic-communications', 'Electronic Communications');

    $this->committees->method('roots')->willReturn([$comms]);
    $this->committees->method('findAll')->willReturn([$comms]);
    $this->committees->method('memberIdsIn')->willReturnCallback(
        static fn (int|string $c, bool $d = true): array => $c === 13 ? [31] : []
    );
    $this->members->method('findAll')->willReturn([($this->member)(31, 'Bill W')]);

    $html = ($this->render)();

    expect($html)->toContain('draggable="true"')
        ->toContain('data-member="31"')
        ->toContain('data-source="13"')
        ->toContain('Bill W')
        ->toContain('1 member');
});

// Members have no post_title worth showing — their names live in ACF — so a
// blank anonymous name is a real state, not a broken one, and must still
// produce a chip somebody can drag.
it('still renders a member with no anonymous name', function () {
    $comms = ($this->committee)(13, 'comms', 'Comms');

    $this->committees->method('roots')->willReturn([$comms]);
    $this->committees->method('findAll')->willReturn([$comms]);
    $this->committees->method('memberIdsIn')->willReturn([31]);
    $this->members->method('findAll')->willReturn([($this->member)(31, '')]);

    $html = ($this->render)();

    expect($html)->toContain('(no anonymous name)')
        ->toContain('data-member="31"');
});

// Drag and drop alone would put the screen out of reach without a pointer,
// so every member carries a select that does the same two things.
it('gives every member a keyboard reachable move and copy control', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');
    $comms      = ($this->committee)(13, 'comms', 'Comms', 12);

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup, $comms]);
    $this->committees->method('memberIdsIn')->willReturnCallback(
        static fn (int|string $c, bool $d = true): array => $c === 13 ? [31] : []
    );
    $this->members->method('findAll')->willReturn([($this->member)(31, 'Bill W')]);

    $html = ($this->render)();

    expect($html)->toContain('<optgroup label="Move to">')
        ->toContain('<optgroup label="Also add to">');

    // Scoped to the one select belonging to the member sitting in Comms.
    // Document-wide these assertions would be wrong: the same committee is
    // legitimately offered to the unassigned member further down the page.
    $select = selectFor($html, 'amber-move-31-13');

    expect($select)->toContain('value="move:12"')
        ->toContain('value="copy:12"')
        // Moving someone to the committee they are already in is a no-op, so it
        // is not offered — in either group.
        ->not->toContain('value="move:13"')
        ->not->toContain('value="copy:13"')
        // Unassigned is a destination for move and never for copy.
        ->toContain('value="move:0"')
        ->not->toContain('value="copy:0"');
});

// The seventh argument to add_submenu_page() is a positional offset into
// the parent's existing submenu, so "after Intergroup Meetings" has to be
// found rather than hard-coded -- four other Amber classes add to that menu
// on their own hooks and the order depends on load order.
it('slots the page in directly after intergroup meetings', function () {
    $GLOBALS['submenu']['intergroup'] = [
        0 => ['Positions', 'edit_posts', 'edit.php?post_type=intergroup-position'],
        1 => ['Members', 'edit_posts', 'edit.php?post_type=intergroup-member'],
        // A gap, as remove_submenu_page() leaves behind.
        5 => ['Intergroup Meetings', 'edit_posts', 'edit.php?post_type=intergroup-meeting'],
        6 => ['Privacy Policy', 'edit_posts', 'edit.php?post_type=privacy-policy'],
    ];

    $position = null;
    Functions\expect('add_submenu_page')->once()->andReturnUsing(
        function (...$args) use (&$position) {
            $position = $args[6] ?? null;
            return 'amber-committees';
        }
    );

    $this->tree->registerPage();

    // Third entry by offset, not by key: the keys are 0, 1, 5, 6.
    expect($position)->toBe(3);

    unset($GLOBALS['submenu']);
});

it('appends when intergroup meetings is not there', function () {
    $GLOBALS['submenu']['intergroup'] = [
        ['Positions', 'edit_posts', 'edit.php?post_type=intergroup-position'],
    ];

    $position = 'untouched';
    Functions\expect('add_submenu_page')->once()->andReturnUsing(
        function (...$args) use (&$position) {
            $position = $args[6] ?? null;
            return 'amber-committees';
        }
    );

    $this->tree->registerPage();

    expect($position)->toBeNull('appending beats guessing at a number');

    unset($GLOBALS['submenu']);
});

it('splits the screen into a tree pane and a member pane', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    $html = ($this->render)();

    expect($html)->toContain('amber-tree-pane')
        ->toContain('amber-member-pane')
        ->toContain('role="tree"')
        ->toContain('role="treeitem"');
});

// The first root opens by default, so the screen is never blank on arrival,
// and every other panel ships hidden rather than being fetched on click.
it('selects the first root and hides the rest', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');
    $comms      = ($this->committee)(13, 'comms', 'Comms', 12);

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup, $comms]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    $html = ($this->render)();

    expect($html)->toContain('aria-selected="true" data-committee="12"')
        ->toContain('aria-selected="false" data-committee="13"')
        ->toContain('<div class="amber-member-panel" data-committee="12">')
        ->toContain('<div class="amber-member-panel" data-committee="13" hidden>');
});

it('shows the full path of a nested committee', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');
    $comms      = ($this->committee)(13, 'comms', 'Comms', 12);

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup, $comms]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    expect(($this->render)())->toContain('Intergroup › Comms');
});

// Unassigned is not a committee, so it gets its own tree rather than
// sitting alongside the real roots in the accessibility tree.
it('keeps unassigned outside the committee tree', function () {
    $intergroup = ($this->committee)(12, 'intergroup', 'Intergroup');

    $this->committees->method('roots')->willReturn([$intergroup]);
    $this->committees->method('findAll')->willReturn([$intergroup]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    $html = ($this->render)();

    expect($html)->toContain('amber-tree-loose')
        ->toContain('amber-tree-unassigned')
        ->toContain('data-committee="0"');
});

// A term hierarchy can be edited into a loop in wp-admin, and an unbounded
// walk up the parents would hang the whole screen rather than mis-draw one
// subtitle.
it('does not hang the path walk on a cyclic hierarchy', function () {
    $a = ($this->committee)(1, 'a', 'A', 2);
    $b = ($this->committee)(2, 'b', 'B', 1);

    $this->committees->method('roots')->willReturn([$a]);
    $this->committees->method('findAll')->willReturn([$a, $b]);
    $this->committees->method('memberIdsIn')->willReturn([]);
    $this->members->method('findAll')->willReturn([]);

    $html = ($this->render)();

    expect($html)->toContain('amber-panel-path');
});

it('escapes names', function () {
    $comms = ($this->committee)(13, 'comms', 'Comms');

    $this->committees->method('roots')->willReturn([$comms]);
    $this->committees->method('findAll')->willReturn([$comms]);
    $this->committees->method('memberIdsIn')->willReturn([31]);
    $this->members->method('findAll')->willReturn(
        [($this->member)(31, '<script>alert(1)</script>')]
    );

    $html = ($this->render)();

    expect($html)->not->toContain('<script>alert(1)</script>');
});
