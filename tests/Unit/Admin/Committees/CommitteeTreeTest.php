<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Committees;

use Amber\Admin\Committees\CommitteeTree;
use Amber\Tests\AmberTestCase;
use Unity\Committees\Interfaces\Committee;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Tests for the committee tree screen.
 *
 * The cases that matter are the structural ones: that a committee's members
 * are printed under that committee and not repeated under its parents, that a
 * member with nothing filled in still renders, and that an empty taxonomy
 * produces a signpost rather than a blank page. A tree screen that silently
 * shows a person twice, or shows nothing at all, is worse than one that errors.
 *
 * @covers \Amber\Admin\Committees\CommitteeTree
 */
class CommitteeTreeTest extends AmberTestCase
{
    /** @var CommitteeRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $committees;

    /** @var MemberRepository&\PHPUnit\Framework\MockObject\MockObject */
    private $members;

    private CommitteeTree $tree;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(
            static fn (string $key): array => $key === Committee::class
                ? ['TAXONOMY' => 'intergroup-committee']
                : ['POST_TYPE' => 'intergroup-member']
        );

        $this->committees = $this->createMock(CommitteeRepository::class);
        $this->members    = $this->createMock(MemberRepository::class);

        $this->tree = new CommitteeTree($config, $this->committees, $this->members);
    }

    private function committee(int $id, string $slug, string $name, int $parent = 0): Committee
    {
        $committee = $this->createMock(Committee::class);
        $committee->method('getId')->willReturn($id);
        $committee->method('getSlug')->willReturn($slug);
        $committee->method('getName')->willReturn($name);
        $committee->method('getParentId')->willReturn($parent);
        $committee->method('isRoot')->willReturn($parent === 0);

        return $committee;
    }

    private function member(int $id, string $name): Member
    {
        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn($id);
        $member->method('getAnonymousName')->willReturn($name);

        return $member;
    }

    private function render(): string
    {
        ob_start();
        $this->tree->render();

        return (string) ob_get_clean();
    }

    /**
     * @test
     */
    public function an_empty_taxonomy_points_at_the_term_editor_instead_of_rendering_a_tree(): void
    {
        $this->committees->method('roots')->willReturn([]);
        $this->committees->expects($this->never())->method('findAll');

        $html = $this->render();

        $this->assertStringContainsString('No committees exist yet', $html);
        $this->assertStringContainsString('edit-tags.php?taxonomy=intergroup-committee', $html);
        $this->assertStringNotContainsString('amber-committee-tree', $html);
    }

    /**
     * @test
     */
    public function it_renders_a_committee_with_its_name_and_slug(): void
    {
        $intergroup = $this->committee(12, 'intergroup', 'Intergroup');

        $this->committees->method('roots')->willReturn([$intergroup]);
        $this->committees->method('findAll')->willReturn([$intergroup]);
        $this->committees->method('memberIdsIn')->willReturn([]);
        $this->members->method('findAll')->willReturn([]);

        $html = $this->render();

        $this->assertStringContainsString('data-committee="12"', $html);
        $this->assertStringContainsString('Intergroup', $html);
        $this->assertStringContainsString('intergroup', $html);
        $this->assertStringContainsString('0 members', $html);
    }

    /**
     * The rollup is deliberately off. memberIdsIn() includes descendants by
     * default, which on a tree would print the same person under every
     * ancestor and destroy the one thing the screen is for.
     *
     * @test
     */
    public function members_are_looked_up_without_the_descendant_rollup(): void
    {
        $intergroup = $this->committee(12, 'intergroup', 'Intergroup');

        $this->committees->method('roots')->willReturn([$intergroup]);
        $this->committees->method('findAll')->willReturn([$intergroup]);

        $this->committees->expects($this->atLeastOnce())
            ->method('memberIdsIn')
            ->with($this->anything(), false)
            ->willReturn([]);

        $this->members->method('findAll')->willReturn([]);

        $this->render();
    }

    /**
     * @test
     */
    public function a_child_committee_is_nested_under_its_parent(): void
    {
        $intergroup = $this->committee(12, 'intergroup', 'Intergroup');
        $comms      = $this->committee(13, 'electronic-communications', 'Electronic Communications', 12);

        $this->committees->method('roots')->willReturn([$intergroup]);
        $this->committees->method('findAll')->willReturn([$intergroup, $comms]);
        $this->committees->method('memberIdsIn')->willReturn([]);
        $this->members->method('findAll')->willReturn([]);

        $html = $this->render();

        $parentAt = strpos($html, 'data-committee="12"');
        $childAt  = strpos($html, 'data-committee="13"');

        $this->assertIsInt($parentAt);
        $this->assertIsInt($childAt);
        $this->assertGreaterThan($parentAt, $childAt, 'the child must render inside the parent');
        $this->assertStringContainsString('Electronic Communications', $html);
    }

    /**
     * @test
     */
    public function a_member_is_draggable_and_carries_the_committee_it_sits_in(): void
    {
        $comms = $this->committee(13, 'electronic-communications', 'Electronic Communications');

        $this->committees->method('roots')->willReturn([$comms]);
        $this->committees->method('findAll')->willReturn([$comms]);
        $this->committees->method('memberIdsIn')->willReturnCallback(
            static fn (int|string $c, bool $d = true): array => $c === 13 ? [31] : []
        );
        $this->members->method('findAll')->willReturn([$this->member(31, 'Bill W')]);

        $html = $this->render();

        $this->assertStringContainsString('draggable="true"', $html);
        $this->assertStringContainsString('data-member="31"', $html);
        $this->assertStringContainsString('data-source="13"', $html);
        $this->assertStringContainsString('Bill W', $html);
        $this->assertStringContainsString('1 member', $html);
    }

    /**
     * Members have no post_title worth showing — their names live in ACF — so a
     * blank anonymous name is a real state, not a broken one, and must still
     * produce a chip somebody can drag.
     *
     * @test
     */
    public function a_member_with_no_anonymous_name_still_renders(): void
    {
        $comms = $this->committee(13, 'comms', 'Comms');

        $this->committees->method('roots')->willReturn([$comms]);
        $this->committees->method('findAll')->willReturn([$comms]);
        $this->committees->method('memberIdsIn')->willReturn([31]);
        $this->members->method('findAll')->willReturn([$this->member(31, '')]);

        $html = $this->render();

        $this->assertStringContainsString('(no anonymous name)', $html);
        $this->assertStringContainsString('data-member="31"', $html);
    }

    /**
     * Drag and drop alone would put the screen out of reach without a pointer,
     * so every member carries a select that does the same two things.
     *
     * @test
     */
    public function every_member_gets_a_keyboard_reachable_move_and_copy_control(): void
    {
        $intergroup = $this->committee(12, 'intergroup', 'Intergroup');
        $comms      = $this->committee(13, 'comms', 'Comms', 12);

        $this->committees->method('roots')->willReturn([$intergroup]);
        $this->committees->method('findAll')->willReturn([$intergroup, $comms]);
        $this->committees->method('memberIdsIn')->willReturnCallback(
            static fn (int|string $c, bool $d = true): array => $c === 13 ? [31] : []
        );
        $this->members->method('findAll')->willReturn([$this->member(31, 'Bill W')]);

        $html = $this->render();

        $this->assertStringContainsString('<optgroup label="Move to">', $html);
        $this->assertStringContainsString('<optgroup label="Also add to">', $html);

        // Scoped to the one select belonging to the member sitting in Comms.
        // Document-wide these assertions would be wrong: the same committee is
        // legitimately offered to the unassigned member further down the page.
        $select = $this->selectFor($html, 'amber-move-31-13');

        $this->assertStringContainsString('value="move:12"', $select);
        $this->assertStringContainsString('value="copy:12"', $select);

        // Moving someone to the committee they are already in is a no-op, so it
        // is not offered — in either group.
        $this->assertStringNotContainsString('value="move:13"', $select);
        $this->assertStringNotContainsString('value="copy:13"', $select);

        // Unassigned is a destination for move and never for copy.
        $this->assertStringContainsString('value="move:0"', $select);
        $this->assertStringNotContainsString('value="copy:0"', $select);
    }

    /**
     * The markup of one member's move/copy select, by id.
     */
    private function selectFor(string $html, string $id): string
    {
        $start = strpos($html, 'id="' . $id . '"');
        $this->assertIsInt($start, 'no select found with id ' . $id);

        $end = strpos($html, '</select>', $start);
        $this->assertIsInt($end);

        return substr($html, $start, $end - $start);
    }

    /**
     * @test
     */
    public function names_are_escaped(): void
    {
        $comms = $this->committee(13, 'comms', 'Comms');

        $this->committees->method('roots')->willReturn([$comms]);
        $this->committees->method('findAll')->willReturn([$comms]);
        $this->committees->method('memberIdsIn')->willReturn([31]);
        $this->members->method('findAll')->willReturn(
            [$this->member(31, '<script>alert(1)</script>')]
        );

        $html = $this->render();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }
}
