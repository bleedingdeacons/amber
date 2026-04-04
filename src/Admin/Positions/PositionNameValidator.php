<?php

declare(strict_types=1);

namespace Amber\Admin\Positions;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Positions\Interfaces\Position;
use WP_Query;

use function add_action;
use function add_filter;
use function check_ajax_referer;
use function esc_html;
use function get_the_ID;
use function intval;
use function plugin_dir_url;
use function sanitize_text_field;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_localize_script;
use function wp_send_json_success;

/**
 * Enforces uniqueness of the `position-long-name` ACF field across all
 * intergroup-position posts.
 *
 * Provides:
 *  1. A real-time AJAX endpoint for live feedback while editing.
 *  2. Server-side ACF validation on save as a safety net.
 */
class PositionNameValidator
{
    private readonly array $positionConfig;

    /**
     * The ACF meta key for position-long-name.
     *
     * This is a top-level field (not inside a group), so the meta key
     * matches the field name directly.
     */
    private const META_KEY = 'position-long-name';

    public function __construct(Configuration $configuration)
    {
        $this->positionConfig = $configuration->getConfig(Position::class);

        // Enqueue the validator script on position edit screens.
        // Use both hooks: ACF-specific for classic editor, standard for block editor.
        add_action('acf/input/admin_enqueue_scripts', [$this, 'enqueueScripts']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueScripts']);

        // AJAX handler (logged-in users only).
        add_action('wp_ajax_amber_validate_position_name', [$this, 'handleAjax']);

        // Server-side ACF validation on save.
        add_filter(
            'acf/validate_value/key=field_66720958da8b5',
            [$this, 'validateOnSave'],
            10,
            4
        );
    }

    /**
     * Enqueue the validation JS only on the position edit screen.
     */
    public function enqueueScripts(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== $this->positionConfig['POST_TYPE']) {
            return;
        }

        wp_enqueue_script(
            'amber-position-name-validator',
            plugin_dir_url(dirname(__DIR__, 3) . '/amber.php') . 'assets/js/position-name-validator.js',
            ['jquery', 'acf-input'],
            '1.0.0',
            true
        );

        wp_localize_script('amber-position-name-validator', 'amberPositionName', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'post_id' => (string) (get_the_ID() ?: intval($_GET['post'] ?? 0)),
            'nonce'   => wp_create_nonce('amber_position_name'),
        ]);
    }

    /**
     * AJAX handler — checks whether the submitted position name
     * is already in use by another position post.
     */
    public function handleAjax(): void
    {
        check_ajax_referer('amber_position_name', 'nonce');

        $value  = sanitize_text_field($_POST['value'] ?? '');
        $postId = intval($_POST['post_id'] ?? 0);

        if ($value === '') {
            wp_send_json_success(['valid' => true]);
        }

        $duplicate = $this->findDuplicate($value, $postId);

        if ($duplicate) {
            wp_send_json_success([
                'valid'   => false,
                'message' => sprintf(
                    'This position name is already assigned to another position (Post #%d).',
                    $duplicate
                ),
            ]);
        }

        wp_send_json_success(['valid' => true]);
    }

    /**
     * ACF server-side validation — prevents saving if a duplicate exists.
     *
     * @param bool|string $valid   Current validity.
     * @param mixed       $value   Submitted field value.
     * @param array       $field   ACF field array.
     * @param string      $input   Input name attribute.
     *
     * @return bool|string
     */
    public function validateOnSave($valid, $value, $field, $input)
    {
        if ($valid !== true) {
            return $valid;
        }

        $value = sanitize_text_field((string) $value);

        if ($value === '') {
            return $valid;
        }

        // ACF stores the post ID in $_POST['_acf_post_id'] during
        // server-side validation, NOT $_POST['post_id'] (which is
        // only present in the custom AJAX handler). Fall back to
        // $_POST['post_ID'] (WordPress's own field) as a safety net.
        $postId = intval(
            $_POST['_acf_post_id']
            ?? $_POST['post_id']
            ?? $_POST['post_ID']
            ?? 0
        );

        $duplicate = $this->findDuplicate($value, $postId);

        if ($duplicate) {
            return sprintf(
                'The position name "%s" is already in use by another position.',
                esc_html($value)
            );
        }

        return $valid;
    }

    /**
     * Query for any other intergroup-position post that already uses
     * the given position name.
     *
     * @param string $value          The position name to check.
     * @param int    $excludePostId  Post ID to exclude (the post being edited).
     *
     * @return int|null  The duplicate post ID, or null if unique.
     */
    private function findDuplicate(string $value, int $excludePostId): ?int
    {
        $query = new WP_Query([
            'post_type'      => $this->positionConfig['POST_TYPE'],
            'post_status'    => ['publish', 'draft', 'pending', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => [
                [
                    'key'     => self::META_KEY,
                    'value'   => $value,
                    'compare' => '=',
                ],
            ],
            'post__not_in' => $excludePostId ? [$excludePostId] : [],
        ]);

        $ids = $query->posts;

        return !empty($ids) ? (int) $ids[0] : null;
    }
}