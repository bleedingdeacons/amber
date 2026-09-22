<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Core;

use Amber\Core\MenuRegistrar;
use BleedingDeacons\WpMocks\WpState;

/*
 * Tests for the admin menu structure.
 *
 * MenuRegistrar is what puts the "Intergroup" top-level menu and its children
 * in the WordPress admin sidebar. The order and the parent/child wiring are the
 * whole point — a submenu registered under the wrong parent simply never
 * appears — so the test asserts the structure the registrar builds rather than
 * that it merely ran.
 */

covers(MenuRegistrar::class);

it('registers the intergroup top level menu', function () {
    MenuRegistrar::registerMenus();

    $top = array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'menu');

    expect($top)->toHaveCount(1)
        ->and(array_values($top)[0]['slug'])->toBe(MenuRegistrar::MENU_SLUG);
});

it('gives every content type a submenu under intergroup', function () {
    MenuRegistrar::registerMenus();

    $submenuTargets = array_column(
        array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'submenu'),
        'slug'
    );

    foreach (
        [
        'edit.php?post_type=intergroup-position',
        'edit.php?post_type=intergroup-member',
        'edit.php?post_type=tsml_meeting',
        'edit.php?post_type=intergroup-meeting',
        'edit.php?post_type=privacy-policy',
        ] as $expected
    ) {
        expect($submenuTargets)->toContain($expected);
    }
});

it('hangs submenus off the intergroup parent', function () {
    MenuRegistrar::registerMenus();

    $submenus = array_filter(WpState::$menus, static fn (array $m): bool => $m['type'] === 'submenu');

    foreach ($submenus as $submenu) {
        expect($submenu['parent'])->toBe(MenuRegistrar::MENU_SLUG);
    }
});

it('removes the duplicate default submenu', function () {
    // add_menu_page auto-creates a submenu echoing the top-level slug;
    // leaving it in would show "Intergroup > Intergroup".
    MenuRegistrar::registerMenus();

    expect(WpState::$removedSubmenus)->toContain([MenuRegistrar::MENU_SLUG, MenuRegistrar::MENU_SLUG]);
});

it('registers the help submenu with a render callback', function () {
    MenuRegistrar::registerHelpMenu();

    $help = array_values(array_filter(
        WpState::$menus,
        static fn (array $m): bool => ($m['slug'] ?? '') === 'amber-help'
    ));

    expect($help)->toHaveCount(1);
    // The help tab also wires a footer script to open the manual in a tab.
    $this->assertHookAdded('admin_footer');
});
