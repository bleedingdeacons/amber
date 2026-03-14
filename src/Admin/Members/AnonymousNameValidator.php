<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use WP_Query;

use function add_action;
use function add_filter;
use function check_ajax_referer;
use function esc_html;
use function get_the_ID;
use function intval;
use function is_admin;
use function plugin_dir_url;
use function sanitize_text_field;
use function wp_create_nonce;
use function wp_enqueue_script;
use function wp_localize_script;
use function wp_send_json_success;

/**
 * Enforces uniqueness of the `anonymous-name` ACF field across all
 * intergroup-member posts.
 *
 * Provides:
 *  1. A real-time AJAX endpoint for live feedback while editing.
 *  2. Server-side ACF validation on save as a safety net.
 */
class AnonymousNameValidator
{
    private readonly array $memberConfig;

    /**
     * The ACF meta key for the anonymous-name sub-field.
     *
     * Because the field lives inside the `about-layout-group` ACF group
     * the stored meta_key is: about-layout-group_anonymous-name
     */
    private const META_KEY = 'about-layout-group_anonymous-name';

    public function __construct(Configuration $configuration)
    {
        $this->memberConfig = $configuration->getConfig(Member::class);

        // Enqueue the front-end validator script on member edit screens.
        add_action('acf/input/admin_enqueue_scripts', [$this, 'enqueueScripts']);

        // AJAX handlers (logged-in users only).
        add_action('wp_ajax_amber_validate_anonymous_name', [$this, 'handleAjax']);

        // Server-side ACF validation on save.
        add_filter(
            'acf/validate_value/key=field_66461796ab271',
            [$this, 'validateOnSave'],
            10,
            4
        );
    }

    /**
     * Enqueue the validation JS only on the member edit screen.
     */
    public function enqueueScripts(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->post_type !== $this->memberConfig['POST_TYPE']) {
            return;
        }

        wp_enqueue_script(
            'amber-unique-field-validator',
            plugin_dir_url(dirname(__DIR__, 2) . '/Amber.php') . 'assets/js/unique-field-validator.js',
            ['jquery', 'acf-input'],
            '1.0.0',
            true
        );

        wp_localize_script('amber-unique-field-validator', 'amberUniqueFields', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'post_id' => (string) (get_the_ID() ?: 0),
            'fields'  => [
                [
                    'field_name' => 'anonymous-name',
                    'action'     => 'amber_validate_anonymous_name',
                    'nonce'      => wp_create_nonce('amber_anon_name'),
                ],
            ],
        ]);
    }

    /**
     * AJAX handler — checks whether the submitted anonymous name
     * is already in use by another member post.
     */
    public function handleAjax(): void
    {
        check_ajax_referer('amber_anon_name', 'nonce');

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
                    'This anonymous name is already assigned to another member (Post #%d).',
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

        $postId = intval($_POST['post_id'] ?? 0);

        $duplicate = $this->findDuplicate($value, $postId);

        if ($duplicate) {
            return sprintf(
                'The anonymous name "%s" is already in use by another member (Post #%d).',
                esc_html($value),
                $duplicate
            );
        }

        return $valid;
    }

    /**
     * Query for any other intergroup-member post that already uses
     * the given anonymous name.
     *
     * @param string $value  The anonymous name to check.
     * @param int    $excludePostId  Post ID to exclude (the post being edited).
     *
     * @return int|null  The duplicate post ID, or null if unique.
     */
    private function findDuplicate(string $value, int $excludePostId): ?int
    {
        $query = new WP_Query([
            'post_type'      => $this->memberConfig['POST_TYPE'],
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