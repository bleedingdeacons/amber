<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

use Scrutiny\Privacy\DataObscurer;

use function add_action;
use function current_user_can;
use function get_current_screen;
use function plugin_dir_url;
use function wp_enqueue_script;
use function wp_localize_script;

/**
 * Adds "Clear" buttons next to the Personal Email and Mobile Number
 * ACF fields on the member edit screen. When clicked a confirmation
 * dialog is shown; if accepted the field value is cleared.
 */
class PersonalDataClearer
{
    private readonly array $memberConfig;

    public function __construct(Configuration $configuration)
    {
        $this->memberConfig = $configuration->getConfig(Member::class);

        add_action('acf/input/admin_enqueue_scripts', [$this, 'enqueueScripts']);
    }

    /**
     * Enqueue the clear-button JS only on the member edit screen.
     */
    public function enqueueScripts(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== $this->memberConfig['POST_TYPE']) {
            return;
        }

        wp_enqueue_script(
            'amber-personal-data-clear',
            plugin_dir_url(dirname(__DIR__, 3) . '/Amber.php') . 'assets/js/personal-data-clear.js',
            ['jquery', 'acf-input'],
            '1.0.0',
            true
        );

        wp_localize_script('amber-personal-data-clear', 'amberPersonalData', [
            'canEdit' => current_user_can(DataObscurer::EDIT_CAPABILITY),
        ]);
    }
}