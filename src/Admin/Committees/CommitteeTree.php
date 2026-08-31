<?php

declare(strict_types=1);

namespace Amber\Admin\Committees;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Core\MenuRegistrar;
use Unity\Committees\Interfaces\Committee;
use Unity\Committees\Interfaces\CommitteeRepository;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

use function add_action;
use function add_submenu_page;
use function admin_url;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_edit_post_link;
use function plugin_dir_url;
use function taxonomy_exists;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_localize_script;

/**
 * Committee Tree
 *
 * A two-pane admin screen: the committee hierarchy on the left, the members of
 * whichever committee is selected on the right. Members are dragged from the
 * right pane onto a committee in the left to move or copy them.
 *
 * The panes exist because one column did not survive real data. Rendering every
 * committee's members inline read fine against a handful, but the Unassigned
 * bucket alone holds ninety-odd people and the tree disappeared underneath
 * them. Splitting them means the hierarchy stays visible at all times, which is
 * the thing being navigated, while the long list gets a column of its own.
 *
 * Deliberately not a CRUD screen. Committees are created, renamed and
 * reparented on WordPress's own term editor, which already does that job
 * properly -- slug collisions, parent loops, capabilities and all. This screen
 * answers the question that editor cannot: who is actually on each committee.
 *
 * Members are shown against the committee they are assigned to, never against
 * its ancestors, even though CommitteeRepository::memberIdsIn() rolls
 * descendants up by default. Selecting a parent and being shown everybody
 * beneath it would make it impossible to see where anyone actually sits, so
 * this passes includeDescendants: false and lets the tree do the implying.
 */
class CommitteeTree
{
    public const PAGE_SLUG = 'amber-committees';

    /** The right-hand pane's id for the members of no committee. */
    private const UNASSIGNED = 0;

    private CommitteeRepository $committees;
    private MemberRepository $members;

    /** @var array<string, mixed> */
    private readonly array $memberConfig;

    /** @var array<string, mixed> */
    private readonly array $committeeConfig;

    /**
     * Every committee bucketed by parent id, so the tree is walked without a
     * query per node. Built once per render.
     *
     * @var array<int, array<int, Committee>>
     */
    private array $byParent = [];

    /**
     * Every committee by its own id, for walking back up to a root.
     *
     * @var array<int, Committee>
     */
    private array $byId = [];

    /**
     * Members by committee id, memoised because every id is asked for twice --
     * once for the tree row's count and once for its panel.
     *
     * Instance properties, not `static` locals: a static inside a method is
     * shared by every instance of the class, so a second CommitteeTree would
     * serve the first one's members. It also leaks between tests, which is how
     * this was found.
     *
     * @var array<int, array<int, Member>>
     */
    private array $memberCache = [];

    /** @var array<int, Member>|null */
    private ?array $unassignedCache = null;

    /**
     * Committee ids already drawn in the tree, so a cycle cannot be walked
     * forever.
     *
     * WordPress lets a term hierarchy be edited into a loop -- set A's parent
     * to B and B's to A and it saves without complaint. Every walk over this
     * structure has to assume that has happened, because an unguarded one does
     * not mis-draw the tree, it recurses until PHP dies and the screen returns
     * nothing at all.
     *
     * @var array<int, true>
     */
    private array $drawn = [];

    public function __construct(
        Configuration $configuration,
        CommitteeRepository $committees,
        MemberRepository $members
    ) {
        $this->committees      = $committees;
        $this->members         = $members;
        $this->memberConfig    = $configuration->getConfig(Member::class) ?? [];
        $this->committeeConfig = $configuration->getConfig(Committee::class) ?? [];

        add_action('admin_menu', [$this, 'registerPage'], 20);
        add_action('admin_head', [$this, 'renderStyles']);
    }

    /**
     * Add the screen under the Intergroup menu.
     *
     * Registered at priority 20 so it lands after MenuRegistrar::registerMenus()
     * has created the parent menu, and before the Help item that pins itself
     * last at 999.
     */
    public function registerPage(): void
    {
        add_submenu_page(
            MenuRegistrar::MENU_SLUG,
            'Committees',
            'Committees',
            MenuRegistrar::MENU_CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    /**
     * Whether the current request is this screen.
     *
     * Checked against $_GET rather than get_current_screen(), because
     * admin_head fires for the styles before the screen object is reliable on
     * every WordPress version.
     */
    private function isCurrentScreen(): bool
    {
        return isset($_GET['page']) && $_GET['page'] === self::PAGE_SLUG;
    }

    public function render(): void
    {
        echo '<div class="wrap amber-committees">';
        echo '<h1>Committees</h1>';

        // Distinguished from "no committees yet" deliberately. The taxonomy is
        // defined in the ACF admin UI and therefore lives in each site's
        // database, so an environment that has never had it imported reaches
        // this screen with nothing registered at all. Both states produce an
        // empty tree, but only one is fixed by adding a term -- and pointing
        // somebody at the term editor here would send them to WordPress's bare
        // "Invalid taxonomy." error with no explanation.
        $taxonomy = $this->committeeTaxonomy();

        if ($taxonomy === '' || !taxonomy_exists($taxonomy)) {
            printf(
                '<p>The committee taxonomy (<code>%s</code>) is not registered on this site, '
                    . 'so there is nothing to show yet. It is defined in the ACF admin UI rather '
                    . 'than in code, which means each environment needs its own copy: import it '
                    . 'under <strong>ACF → Tools → Import</strong>, or recreate it under '
                    . '<strong>ACF → Taxonomies</strong>.</p>',
                esc_html($taxonomy !== '' ? $taxonomy : 'not configured')
            );
            echo '</div>';
            return;
        }

        $roots = $this->committees->roots();

        if ($roots === []) {
            echo '<p>No committees exist yet. Create them under '
                . '<a href="' . esc_url($this->termEditorUrl()) . '">Committees</a>, '
                . 'then come back here to assign members.</p>';
            echo '</div>';
            return;
        }

        $this->indexTree();
        $this->enqueueScript();

        echo '<p class="description">Pick a committee on the left to see its members. '
            . 'Drag a member onto a committee to move them there; hold <kbd>Ctrl</kbd> '
            . '(<kbd>⌘</kbd> on a Mac) while dragging to add them to the second committee '
            . 'instead of moving them. Every member also carries a <em>Move or copy to…</em> '
            . 'menu, which does the same thing from the keyboard.</p>';

        echo '<p><a href="' . esc_url($this->termEditorUrl()) . '" class="button">'
            . 'Add or rename committees</a></p>';

        $selected = $roots[0]->getId();

        echo '<div class="amber-committee-layout">';
        $this->renderTreePane($roots, $selected);
        $this->renderMemberPane($selected);
        echo '</div>';

        echo '</div>';
    }

    /**
     * The left pane: the hierarchy, and nothing else.
     *
     * @param array<int, Committee> $roots
     */
    private function renderTreePane(array $roots, int $selected): void
    {
        echo '<div class="amber-tree-pane">';
        echo '<h2 class="amber-pane-heading">Committees</h2>';

        echo '<ul class="amber-tree" role="tree" aria-label="Committees">';
        foreach ($roots as $committee) {
            $this->renderTreeNode($committee, $selected);
        }
        echo '</ul>';

        // Outside the tree above rather than a node in it: Unassigned is not a
        // committee, and putting it in the same tree would make it a sibling of
        // the real roots in the accessibility tree as well as visually.
        echo '<ul class="amber-tree amber-tree-loose" role="tree" aria-label="Members with no committee">';
        printf(
            '<li role="none"><div class="amber-tree-row amber-tree-unassigned" role="treeitem" '
                . 'tabindex="0" aria-selected="false" data-committee="%d">'
                . '<span class="amber-tree-name">Unassigned</span>'
                . '<span class="amber-tree-count">%d</span></div></li>',
            self::UNASSIGNED,
            count($this->unassignedMembers())
        );
        echo '</ul>';

        echo '</div>';
    }

    /**
     * One committee and everything below it.
     */
    private function renderTreeNode(Committee $committee, int $selected): void
    {
        $id = $committee->getId();

        if (isset($this->drawn[$id])) {
            return;
        }

        $this->drawn[$id] = true;

        $children = $this->byParent[$id] ?? [];

        printf(
            '<li role="none"><div class="amber-tree-row" role="treeitem" tabindex="0" '
                . 'aria-selected="%s" data-committee="%d" title="%s">'
                . '<span class="amber-tree-name">%s</span>'
                . '<span class="amber-tree-count">%d</span></div>',
            $id === $selected ? 'true' : 'false',
            $id,
            esc_attr($committee->getSlug()),
            esc_html($committee->getName()),
            count($this->membersIn($id))
        );

        if ($children !== []) {
            echo '<ul role="group">';
            foreach ($children as $child) {
                $this->renderTreeNode($child, $selected);
            }
            echo '</ul>';
        }

        echo '</li>';
    }

    /**
     * The right pane: one panel per committee, all but the selected one hidden.
     *
     * Every panel is rendered up front rather than fetched on selection.
     * Switching committees is then instant and needs no second endpoint, and
     * the whole page is a few hundred rows at the sizes this screen deals with.
     */
    private function renderMemberPane(int $selected): void
    {
        echo '<div class="amber-member-pane">';

        foreach ($this->flatten(0, 0) as [$committee, $depth]) {
            $this->renderMemberPanel(
                $committee->getId(),
                $committee->getName(),
                $this->pathLabel($committee),
                $this->membersIn($committee->getId()),
                $committee->getId() === $selected
            );
        }

        $this->renderMemberPanel(
            self::UNASSIGNED,
            'Unassigned',
            'Members who are not on any committee',
            $this->unassignedMembers(),
            false
        );

        echo '</div>';
    }

    /**
     * @param array<int, Member> $members
     */
    private function renderMemberPanel(
        int $committeeId,
        string $name,
        string $path,
        array $members,
        bool $visible
    ): void {
        printf(
            '<div class="amber-member-panel" data-committee="%d"%s>',
            $committeeId,
            $visible ? '' : ' hidden'
        );

        printf(
            '<h2 class="amber-pane-heading">%s <span class="amber-panel-count">%s</span></h2>'
                . '<p class="amber-panel-path">%s</p>',
            esc_html($name),
            esc_html($this->countLabel(count($members))),
            esc_html($path)
        );

        if ($members === []) {
            echo '<p class="amber-panel-empty">Nobody is assigned to this committee. '
                . 'Drag someone here from another committee, or from Unassigned.</p>';
            echo '</div>';
            return;
        }

        echo '<ul class="amber-member-list">';
        foreach ($members as $member) {
            $this->renderMember($member, $committeeId);
        }
        echo '</ul>';

        echo '</div>';
    }

    /**
     * One draggable member row, with its keyboard equivalent.
     *
     * @param Member $member   The member
     * @param int    $sourceId The committee they are being shown under, so a
     *                         move knows what to remove them from
     */
    private function renderMember(Member $member, int $sourceId): void
    {
        $name = $member->getAnonymousName();
        if ($name === '') {
            $name = '(no anonymous name)';
        }

        $editLink = get_edit_post_link($member->getId());

        printf(
            '<li class="amber-member" draggable="true" data-member="%d" data-source="%d">'
                . '<span class="amber-member-name">%s</span>',
            $member->getId(),
            $sourceId,
            esc_html($name)
        );

        if (is_string($editLink) && $editLink !== '') {
            printf(
                ' <a class="amber-member-edit" href="%s" title="Edit %s">edit</a>',
                esc_url($editLink),
                esc_attr($name)
            );
        }

        // The keyboard path. Drag and drop alone would put this screen out of
        // reach of anyone not using a pointer, and the optgroups give copy the
        // same standing as move rather than stranding it on a modifier key.
        printf(
            '<label class="screen-reader-text" for="amber-move-%1$d-%2$d">Move or copy %3$s to another committee</label>'
                . '<select class="amber-member-move" id="amber-move-%1$d-%2$d" data-member="%1$d" data-source="%2$d">',
            $member->getId(),
            $sourceId,
            esc_attr($name)
        );

        echo '<option value="">Move or copy to…</option>';
        echo '<optgroup label="Move to">';
        $this->renderMoveOptions('move', $sourceId);
        echo '</optgroup>';
        echo '<optgroup label="Also add to">';
        $this->renderMoveOptions('copy', $sourceId);
        echo '</optgroup>';
        echo '</select>';

        echo '</li>';
    }

    /**
     * The committee options for one optgroup, flattened with indentation.
     *
     * @param string $mode     'move' or 'copy'
     * @param int    $sourceId The committee being moved from, which is skipped
     */
    private function renderMoveOptions(string $mode, int $sourceId): void
    {
        foreach ($this->flatten(0, 0) as [$committee, $depth]) {
            if ($committee->getId() === $sourceId) {
                continue;
            }

            printf(
                '<option value="%s:%d">%s%s</option>',
                esc_attr($mode),
                $committee->getId(),
                esc_html(str_repeat('— ', $depth)),
                esc_html($committee->getName())
            );
        }

        if ($mode === 'move' && $sourceId > 0) {
            echo '<option value="move:0">(Unassigned)</option>';
        }
    }

    /**
     * Load the whole tree once and index it both ways.
     *
     * findAll() is a single query; walking with childrenOf() would be one per
     * node, and a tree screen is exactly where that adds up.
     */
    private function indexTree(): void
    {
        $this->byParent = [];
        $this->byId     = [];
        $this->drawn    = [];

        foreach ($this->committees->findAll() as $committee) {
            $this->byParent[$committee->getParentId()][] = $committee;
            $this->byId[$committee->getId()]             = $committee;
        }
    }

    /**
     * Depth-first flattening of the indexed tree.
     *
     * Cycle-guarded for the same reason as {@see renderTreeNode()}: a term
     * hierarchy edited into a loop would otherwise recurse until PHP dies.
     *
     * @param array<int, true> $seen Ids already visited on this walk
     * @return array<int, array{0: Committee, 1: int}>
     */
    private function flatten(int $parentId, int $depth, array $seen = []): array
    {
        $flat = [];

        foreach ($this->byParent[$parentId] ?? [] as $committee) {
            $id = $committee->getId();

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $flat[]    = [$committee, $depth];

            foreach ($this->flatten($id, $depth + 1, $seen) as $descendant) {
                $flat[] = $descendant;
            }
        }

        return $flat;
    }

    /**
     * "Intergroup › Public Information › Health" for a panel subtitle.
     *
     * Walked over the in-memory index rather than through
     * CommitteeRepository::pathTo(), which would be another query per panel.
     */
    private function pathLabel(Committee $committee): string
    {
        $names  = [$committee->getName()];
        $parent = $committee->getParentId();
        $guard  = 0;

        // The guard is not paranoia about the data: WordPress permits a term
        // hierarchy to be edited into a loop, and an unbounded walk here would
        // hang the whole admin screen rather than mis-draw one subtitle.
        while ($parent !== 0 && isset($this->byId[$parent]) && $guard < 50) {
            $names[] = $this->byId[$parent]->getName();
            $parent  = $this->byId[$parent]->getParentId();
            $guard++;
        }

        return implode(' › ', array_reverse($names));
    }

    /**
     * The members assigned to one committee, not counting its sub-committees.
     *
     * @return array<int, Member>
     */
    private function membersIn(int $committeeId): array
    {
        if (isset($this->memberCache[$committeeId])) {
            return $this->memberCache[$committeeId];
        }

        $ids = $this->committees->memberIdsIn($committeeId, false);

        $this->memberCache[$committeeId] = $ids === [] ? [] : $this->members->findAll([
            'post__in' => $ids,
            'orderby'  => 'title',
            'order'    => 'ASC',
        ]);

        return $this->memberCache[$committeeId];
    }

    /**
     * The members on no committee at all.
     *
     * Without this the screen would be read-only for anyone not yet assigned:
     * there would be nothing to drag from. It doubles as the "who has been
     * missed" list.
     *
     * @return array<int, Member>
     */
    private function unassignedMembers(): array
    {
        if ($this->unassignedCache !== null) {
            return $this->unassignedCache;
        }

        $taxonomy = $this->committeeTaxonomy();

        if ($taxonomy === '') {
            $this->unassignedCache = [];
            return $this->unassignedCache;
        }

        $this->unassignedCache = $this->members->findAll([
            'orderby'   => 'title',
            'order'     => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => $taxonomy,
                    'operator' => 'NOT EXISTS',
                ],
            ],
        ]);

        return $this->unassignedCache;
    }

    /**
     * The committee taxonomy name, as published by tsml-for-unity's field map.
     */
    private function committeeTaxonomy(): string
    {
        return (string) ($this->committeeConfig['TAXONOMY'] ?? '');
    }

    private function countLabel(int $count): string
    {
        return $count === 1 ? '1 member' : $count . ' members';
    }

    /**
     * The native term editor for committees.
     *
     * Reached through the member post type so WordPress renders it with the
     * Intergroup menu highlighted rather than orphaned under Posts.
     */
    private function termEditorUrl(): string
    {
        return admin_url(
            'edit-tags.php?taxonomy=' . $this->committeeTaxonomy()
            . '&post_type=' . (string) ($this->memberConfig['POST_TYPE'] ?? '')
        );
    }

    private function enqueueScript(): void
    {
        wp_enqueue_script(
            'amber-committee-tree',
            plugin_dir_url(dirname(__DIR__, 3) . '/amber.php') . 'assets/js/committee-tree.js',
            [],
            '1.1.0',
            true
        );

        wp_localize_script('amber-committee-tree', 'amberCommitteeTree', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => CommitteeAssignmentController::ACTION,
            'nonce'   => wp_create_nonce(CommitteeAssignmentController::NONCE),
        ]);
    }

    /**
     * Screen styles.
     *
     * Inline on admin_head rather than a stylesheet, matching PositionDashboard
     * and the other Amber screens; the plugin ships no assets/css directory.
     */
    public function renderStyles(): void
    {
        if (!$this->isCurrentScreen()) {
            return;
        }

        echo '<style>
        .amber-committee-layout {
            display: grid; grid-template-columns: minmax(240px, 22em) 1fr;
            gap: 1.5em; align-items: start; margin-top: 1em;
        }
        @media screen and (max-width: 782px) {
            .amber-committee-layout { grid-template-columns: 1fr; }
        }
        .amber-tree-pane, .amber-member-pane {
            background: #fff; border: 1px solid #c3c4c7; border-radius: 3px;
            padding: 1em;
        }
        .amber-pane-heading { margin: 0 0 .5em; font-size: 14px; }
        .amber-panel-count { color: #646970; font-weight: 400; }
        .amber-panel-path { margin: 0 0 1em; color: #646970; font-size: 12px; }
        .amber-panel-empty { color: #646970; }

        .amber-tree, .amber-tree ul { list-style: none; margin: 0; padding: 0; }
        /* Nested levels get the connecting rule that makes it read as a tree. */
        .amber-tree ul { margin-left: .75em; padding-left: .75em; border-left: 1px solid #dcdcde; }
        .amber-tree-loose { margin-top: 1em; padding-top: 1em; border-top: 1px solid #dcdcde; }
        .amber-tree-row {
            display: flex; align-items: center; gap: .5em;
            padding: .35em .5em; border-radius: 3px; cursor: pointer;
        }
        .amber-tree-row:hover { background: #f0f0f1; }
        .amber-tree-row:focus { outline: 2px solid #2271b1; outline-offset: -2px; }
        .amber-tree-row[aria-selected="true"] { background: #2271b1; color: #fff; }
        .amber-tree-row[aria-selected="true"] .amber-tree-count { color: #f0f6fc; }
        .amber-tree-row.amber-drop-target { box-shadow: inset 0 0 0 2px #135e96; background: #f0f6fc; color: #1d2327; }
        .amber-tree-name { flex: 1 1 auto; }
        .amber-tree-count { color: #646970; font-size: 12px; }

        .amber-member-list { list-style: none; margin: 0; padding: 0; }
        .amber-member {
            display: flex; align-items: center; gap: .5em;
            padding: .3em .5em; border-bottom: 1px solid #f0f0f1;
            cursor: grab; font-size: 13px;
        }
        .amber-member:last-child { border-bottom: 0; }
        .amber-member:hover { background: #f6f7f7; }
        .amber-member.amber-dragging { opacity: .5; }
        .amber-member-name { flex: 1 1 auto; }
        .amber-member-edit { font-size: 11px; text-decoration: none; }
        /*
            Visually hidden until the row is hovered or something inside it has
            focus. Shown outright it is one dropdown per member, and Unassigned
            alone can hold a hundred.

            Clipped rather than display:none on purpose: a display:none control
            is not focusable, which would quietly remove the keyboard path this
            select exists to provide.
        */
        .amber-member-move {
            position: absolute; width: 1px; height: 1px;
            margin: -1px; padding: 0; overflow: hidden;
            clip: rect(0 0 0 0); white-space: nowrap; border: 0;
        }
        .amber-member:hover .amber-member-move,
        .amber-member:focus-within .amber-member-move {
            position: static; width: auto; height: auto;
            margin: 0; overflow: visible; clip: auto;
            white-space: normal; border: 1px solid #8c8f94;
            font-size: 11px; max-width: 12em;
        }
        </style>';
    }
}
