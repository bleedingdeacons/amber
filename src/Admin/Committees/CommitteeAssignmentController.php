<?php

declare(strict_types=1);

namespace Amber\Admin\Committees;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Committees\Interfaces\Committee;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function get_post_type;
use function is_wp_error;
use function wp_get_object_terms;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_set_object_terms;

/**
 * Committee Assignment Controller
 *
 * The write half of {@see CommitteeTree}: one admin-ajax endpoint that moves or
 * copies a member between committees.
 *
 * It writes term relationships rather than the ACF field. That is the same
 * source of truth Unity's CommitteeRepository reads, and the ACF fields are
 * configured with Load Terms on, so a change made here shows up correctly on
 * the member's edit screen. Writing the ACF meta instead would update the copy
 * and leave the real relationships untouched.
 *
 * Unity's CommitteeRepository is deliberately read-only, and this does not
 * change that: it never creates, renames or reparents a committee. The tree
 * belongs to whoever maintains it in wp-admin; only who sits in it is editable
 * here.
 */
class CommitteeAssignmentController
{
    public const ACTION = 'amber_committee_assign';
    public const NONCE = 'amber_committee_assign';

    /** @var array<string, mixed> */
    private readonly array $committeeConfig;

    /** @var array<string, mixed> */
    private readonly array $memberConfig;

    public function __construct(Configuration $configuration)
    {
        $this->committeeConfig = $configuration->getConfig(Committee::class) ?? [];
        $this->memberConfig    = $configuration->getConfig(Member::class) ?? [];

        add_action('wp_ajax_' . self::ACTION, [$this, 'handle']);
    }

    /**
     * Move or copy one member between committees.
     *
     * Expects: nonce, member (int), target (int term id), source (int term id
     * or 0), mode ('move'|'copy').
     */
    public function handle(): void
    {
        check_ajax_referer(self::NONCE, 'nonce');

        // Matches MenuRegistrar::MENU_CAPABILITY and the taxonomy's own
        // assign_terms capability, so anyone who can reach the screen can use
        // it and nobody else can.
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'You are not allowed to change committee assignments.'], 403);
        }

        $taxonomy = (string) ($this->committeeConfig['TAXONOMY'] ?? '');
        $postType = (string) ($this->memberConfig['POST_TYPE'] ?? '');

        if ($taxonomy === '' || $postType === '') {
            wp_send_json_error(['message' => 'Committee support is not configured on this site.'], 500);
        }

        $memberId = isset($_POST['member']) ? (int) $_POST['member'] : 0;
        $targetId = isset($_POST['target']) ? (int) $_POST['target'] : 0;
        $sourceId = isset($_POST['source']) ? (int) $_POST['source'] : 0;
        $mode     = (isset($_POST['mode']) && $_POST['mode'] === 'copy') ? 'copy' : 'move';

        if ($memberId <= 0 || get_post_type($memberId) !== $postType) {
            wp_send_json_error(['message' => 'That is not a member.'], 400);
        }

        // A target of 0 means "unassigned", which is only meaningful as the
        // destination of a move -- copying a member to nowhere is a no-op
        // dressed up as an action.
        if ($targetId === 0 && $mode === 'copy') {
            wp_send_json_error(['message' => 'A member cannot be copied to Unassigned.'], 400);
        }

        $current = wp_get_object_terms($memberId, $taxonomy, ['fields' => 'ids']);

        if (is_wp_error($current) || !is_array($current)) {
            wp_send_json_error(['message' => 'Could not read the member’s committees.'], 500);
        }

        $next = array_map('intval', $current);

        if ($mode === 'move' && $sourceId > 0) {
            $next = array_diff($next, [$sourceId]);
        }

        if ($targetId > 0) {
            $next[] = $targetId;
        }

        $next = array_values(array_unique($next));

        // wp_set_object_terms() validates every id against the taxonomy and
        // returns a WP_Error for anything that is not one of its terms, so a
        // forged target id fails here rather than being written.
        $result = wp_set_object_terms($memberId, $next, $taxonomy, false);

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => 'That committee does not exist.'], 400);
        }

        wp_send_json_success([
            'member'     => $memberId,
            'committees' => $next,
        ]);
    }
}
