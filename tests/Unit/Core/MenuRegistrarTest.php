<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Core;

use Amber\Core\MenuRegistrar;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;

/**
 * Tests for the admin menu structure.
 *
 * MenuRegistrar is what puts the "Intergroup" top-level menu and its children
 * in the WordPress admin sidebar. The order and the parent/child wiring are the
 * whole point — a submenu registered under the wrong parent simply never
 * appears — so the test asserts the structure the registrar builds rather than
 * that it merely ran.
 *
 * @covers \Amber\Core\MenuRegistrar
 */
class MenuRegistrarTest extends AmberTestCase
{
    /** @test */
    public function it_registers_the_intergroup_top_level_menu(): void
    {
        MenuRegistrar::registerMenus();

        $top = array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'menu');

        $this->assertCount(1, $top);
        $this->assertSame(MenuRegistrar::MENU_SLUG, array_values($top)[0]['slug']);
    }

    /** @test */
    public function every_content_type_gets_a_submenu_under_intergroup(): void
    {
        MenuRegistrar::registerMenus();

        $submenuTargets = array_column(
            array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'submenu'),
            'slug'
        );

        foreach ([
            'edit.php?post_type=intergroup-position',
            'edit.php?post_type=intergroup-member',
            'edit.php?post_type=tsml_meeting',
            'edit.php?post_type=intergroup-meeting',
            'edit.php?post_type=privacy-policy',
        ] as $expected) {
            $this->assertContains($expected, $submenuTargets);
        }
    }

    /** @test */
    public function submenus_hang_off_the_intergroup_parent(): void
    {
        MenuRegistrar::registerMenus();

        $submenus = array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'submenu');

        foreach ($submenus as $submenu) {
            $this->assertSame(MenuRegistrar::MENU_SLUG, $submenu['parent']);
        }
    }

    /** @test */
    public function the_duplicate_default_submenu_is_removed(): void
    {
        // add_menu_page auto-creates a submenu echoing the top-level slug;
        // leaving it in would show "Intergroup > Intergroup".
        MenuRegistrar::registerMenus();

        $this->assertContains(
            [MenuRegistrar::MENU_SLUG, MenuRegistrar::MENU_SLUG],
            WpState::$removedSubmenus
        );
    }

    /** @test */
    public function the_help_submenu_is_registered_with_a_render_callback(): void
    {
        MenuRegistrar::registerHelpMenu();

        $help = array_values(array_filter(
            WpState::$menus,
            static fn (array $m): bool => ($m['slug'] ?? '') === 'amber-help'
        ));

        $this->assertCount(1, $help);
        // The help tab also wires a footer script to open the manual in a tab.
        $this->assertNotEmpty($this->hooksFor('admin_footer'));
    }
}
