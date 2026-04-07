<?php

declare(strict_types=1);

namespace Amber\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function add_menu_page;
use function add_submenu_page;
use function remove_submenu_page;

/**
 * Menu Registrar
 *
 * Handles all WordPress admin menu and submenu registration for Amber.
 * Extracted from Plugin to keep menu structure changes isolated.
 */
class MenuRegistrar
{
    public const MENU_SLUG = 'intergroup';
    public const MENU_CAPABILITY = 'edit_posts';

    /**
     * Register the Intergroup admin menu and sub-menus.
     *
     * Intended to be called on the 'admin_menu' hook.
     */
    public static function registerMenus(): void
    {
        add_menu_page(
            'Intergroup',
            'Intergroup',
            self::MENU_CAPABILITY,
            self::MENU_SLUG,
            '',
            'dashicons-admin-multisite',
            3
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Positions',
            'Positions',
            self::MENU_CAPABILITY,
            'edit.php?post_type=intergroup-position'
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Members',
            'Members',
            self::MENU_CAPABILITY,
            'edit.php?post_type=intergroup-member'
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Meetings',
            'Groups / Meetings',
            self::MENU_CAPABILITY,
            'edit.php?post_type=tsml_meeting'
        );

        add_submenu_page(
            self::MENU_SLUG,
            'Intergroup Meetings',
            'Intergroup Meetings',
            self::MENU_CAPABILITY,
            'edit.php?post_type=intergroup-meeting'
        );

        // Remove the default Intergroup submenu item
        remove_submenu_page(self::MENU_SLUG, self::MENU_SLUG);
    }

    /**
     * Register the Help sub-menu at a late priority so it always appears last,
     * after any other plugins have added their submenu items.
     *
     * Intended to be called on the 'admin_menu' hook at priority 999.
     */
    public static function registerHelpMenu(): void
    {
        add_submenu_page(
            self::MENU_SLUG,
            'Help',
            'Help',
            self::MENU_CAPABILITY,
            'amber-help',
            [HelpPage::class, 'render']
        );

        // Intercept Help clicks to open amber.html in a new tab
        add_action('admin_footer', [HelpPage::class, 'enqueueHelpTabScript']);
    }
}
