<?php

declare(strict_types=1);

namespace Amber\Managers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Exception;
use function get_field;
use function get_post;
use function is_wp_error;
use function wp_update_post;

/**
 * Syncs a WordPress post title with a designated ACF field value.
 *
 * Extracted from IntergroupManager to deduplicate the near-identical
 * onMemberBeforeSave / onPositionBeforeSave implementations and to
 * add a re-entrancy guard against infinite wp_update_post loops.
 */
final class PostTitleSyncer
{
    /** Re-entrancy guard — prevents wp_update_post from re-triggering the save hook. */
    private bool $isSyncing = false;

    /**
     * Sync the post title with the value of $fieldKey for the given post.
     *
     * If the field is empty or already matches the title, this is a no-op.
     * A flag prevents recursive calls when wp_update_post fires
     * additional save hooks.
     *
     * @param int    $postId   The post being saved.
     * @param string $fieldKey The ACF field whose value should become the title.
     * @param string $context  A human-readable label used in log messages (e.g. "Member", "Position").
     */
    public function sync(int $postId, string $fieldKey, string $context): void
    {
        if ($this->isSyncing) {
            return;
        }

        try {
            $post = get_post($postId);

            if (!$post) {
                return;
            }

            $postTitle  = $post->post_title;
            $fieldValue = get_field($fieldKey, $postId);

            if (empty($fieldValue) || $postTitle === $fieldValue) {
                return;
            }

            $this->isSyncing = true;

            try {
                $result = wp_update_post([
                    'ID'         => $postId,
                    'post_title' => $fieldValue,
                ]);
            } finally {
                $this->isSyncing = false;
            }

            if (is_wp_error($result)) {
                error_log("$context: wp_update_post failed for post ID $postId: " . $result->get_error_message());
            }
        } catch (Exception $e) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log("Error in $context title sync: " . $e->getMessage());
        }
    }
}
