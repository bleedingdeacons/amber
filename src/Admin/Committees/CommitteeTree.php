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
 * An admin screen showing the committee hierarchy with its members, and
 * letting them be dragged from one committee to another.
 *
 * Deliberately not a CRUD screen. Committees are created, renamed and
 * reparented on WordPress's own term editor, which already does that job
 * properly -- slug collisions, parent loops, capabilities and all. This screen
 * answers the question that editor cannot: who is actually on each committee,
 * seen as a tree rather than one member at a time.
 *
 * Members are shown against the committee they are assigned to, never against
 * its ancestors, even though CommitteeRepository::memberIdsIn() rolls
 * descendants up by default. On a tree the rollup would print the same person
 * at every level above them and make it impossible to see where they actually
 * sit -- so this passes includeDescendants: false and lets the nesting do the
 * implying.
 */
class CommitteeTree
{
    public const PAGE_SLUG = 'amber-committees';

    private CommitteeRepository $committees;
    private MemberRepository $members;

    /** @var array<string, mixed> */
    private readonly array $memberConfig;

    /** @var array<string, mixed> */
    private readonly array $committeeConfig;

    /**
     * Every committee, keyed by parent id, so the tree is walked without a
     * query per node. Built once per render.
     *
     * @var array<int, array<int, Committee>>
     */
    private array $byParent = [];

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

        echo '<p class="description">Drag a member onto a committee to move them there. '
            . 'Hold <kbd>Ctrl</kbd> (<kbd>⌘</kbd> on a Mac) while dragging to add them to the '
            . 'second committee instead of moving them. Every member also carries a '
            . '<em>Move or copy to…</em> menu, which does the same thing from the keyboard.</p>';

        echo '<p><a href="' . esc_url($this->termEditorUrl()) . '" class="button">'
            . 'Add or rename committees</a></p>';

        echo '<div class="amber-committee-tree">';
        echo '<ul class="amber-committee-list amber-committee-roots">';
        foreach ($roots as $committee) {
            $this->renderNode($committee);
        }
        echo '</ul>';
        $this->renderUnassigned();
        echo '</div>';

        echo '</div>';
    }

    /**
     * Load the whole tree once and bucket it by parent.
     *
     * findAll() is a single query; walking with childrenOf() would be one per
     * node, and a tree screen is exactly where that adds up.
     */
    private function indexTree(): void
    {
        $this->byParent = [];

        foreach ($this->committees->findAll() as $committee) {
            $this->byParent[$committee->getParentId()][] = $committee;
        }
    }

    /**
     * Render one committee and everything below it.
     */
    private function renderNode(Committee $committee): void
    {
        $id       = $committee->getId();
        $children = $this->byParent[$id] ?? [];
        $members  = $this->membersIn($id);

        printf(
            '<li class="amber-committee" data-committee="%d"><div class="amber-committee-head" '
                . 'data-committee="%d" tabindex="0"><span class="amber-committee-name">%s</span>'
                . '<code class="amber-committee-slug">%s</code>'
                . '<span class="amber-committee-count">%s</span></div>',
            $id,
            $id,
            esc_html($committee->getName()),
            esc_html($committee->getSlug()),
            esc_html($this->countLabel(count($members)))
        );

        echo '<ul class="amber-member-list">';
        foreach ($members as $member) {
            $this->renderMember($member, $id);
        }
        echo '</ul>';

        if ($children !== []) {
            echo '<ul class="amber-committee-list">';
            foreach ($children as $child) {
                $this->renderNode($child);
            }
            echo '</ul>';
        }

        echo '</li>';
    }

    /**
     * Render one draggable member chip, with its keyboard equivalent.
     *
     * @param Member $member  The member
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
        // reach of anyone not using a mouse, and the optgroups give copy the
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
     * Depth-first flattening of the indexed tree.
     *
     * @return array<int, array{0: Committee, 1: int}>
     */
    private function flatten(int $parentId, int $depth): array
    {
        $flat = [];

        foreach ($this->byParent[$parentId] ?? [] as $committee) {
            $flat[] = [$committee, $depth];
            foreach ($this->flatten($committee->getId(), $depth + 1) as $descendant) {
                $flat[] = $descendant;
            }
        }

        return $flat;
    }

    /**
     * The members assigned to one committee, not counting its sub-committees.
     *
     * @return array<int, Member>
     */
    private function membersIn(int $committeeId): array
    {
        $ids = $this->committees->memberIdsIn($committeeId, false);

        if ($ids === []) {
            return [];
        }

        return $this->members->findAll([
            'post__in' => $ids,
            'orderby'  => 'title',
            'order'    => 'ASC',
        ]);
    }

    /**
     * The members on no committee at all.
     *
     * Without this the screen would be read-only for anyone not yet assigned:
     * there would be nothing to drag from. It doubles as the "who has been
     * missed" list.
     */
    private function renderUnassigned(): void
    {
        $taxonomy = $this->committeeTaxonomy();

        if ($taxonomy === '') {
            return;
        }

        $members = $this->members->findAll([
            'orderby'   => 'title',
            'order'     => 'ASC',
            'tax_query' => [
                [
                    'taxonomy' => $taxonomy,
                    'operator' => 'NOT EXISTS',
                ],
            ],
        ]);

        echo '<div class="amber-committee amber-unassigned" data-committee="0">';
        printf(
            '<div class="amber-committee-head" data-committee="0" tabindex="0">'
                . '<span class="amber-committee-name">Unassigned</span>'
                . '<span class="amber-committee-count">%s</span></div>',
            esc_html($this->countLabel(count($members)))
        );

        echo '<ul class="amber-member-list">';
        foreach ($members as $member) {
            $this->renderMember($member, 0);
        }
        echo '</ul>';
        echo '</div>';
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
            '1.0.0',
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
        .amber-committee-tree { margin-top: 1em; }
        .amber-committee-list { list-style: none; margin: 0 0 0 1.5em; padding: 0; }
        .amber-committee-roots { margin-left: 0; }
        .amber-committee { margin: 0 0 .5em; }
        .amber-committee-head {
            display: flex; align-items: baseline; gap: .5em;
            padding: .4em .6em; background: #fff; border: 1px solid #c3c4c7;
            border-left: 4px solid #2271b1; border-radius: 3px;
        }
        .amber-committee-head:focus { outline: 2px solid #2271b1; outline-offset: 1px; }
        .amber-committee-head.amber-drop-target { background: #f0f6fc; border-left-color: #135e96; }
        .amber-committee-name { font-weight: 600; }
        .amber-committee-slug { color: #646970; background: transparent; font-size: 11px; }
        .amber-committee-count { margin-left: auto; color: #646970; font-size: 12px; }
        .amber-member-list { list-style: none; margin: .35em 0 .35em 1.5em; padding: 0; }
        .amber-member {
            display: inline-flex; align-items: center; gap: .4em;
            margin: 0 .35em .35em 0; padding: .2em .5em;
            background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 3px;
            cursor: grab; font-size: 13px;
        }
        .amber-member.amber-dragging { opacity: .5; }
        .amber-member-edit { font-size: 11px; text-decoration: none; }
        /*
            Visually hidden until the chip is hovered or something inside it has
            focus. Shown outright it is one dropdown per member, and the
            Unassigned bucket alone can hold a hundred -- the tree disappears
            behind a wall of selects.

            Clipped rather than display:none on purpose: a display:none control
            is not focusable, which would make this the second time the keyboard
            path got quietly removed. Clipping keeps it in the tab order, and
            :focus-within brings it into view the moment it is reached.
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
        .amber-unassigned { margin-top: 1.5em; }
        .amber-unassigned .amber-committee-head { border-left-color: #8c8f94; }
        </style>';
    }
}
