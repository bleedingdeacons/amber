<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use WP_Post;

use function add_action;
use function add_meta_box;
use function current_user_can;
use function esc_html;

/**
 * "Extended values" box on the member edit screen.
 *
 * Lists the member's Unity extended values: what other plugins keep against
 * a member beyond Unity's own fields, such as Reach's `AppleUserId`. They are
 * stored outside ACF, so without this box an administrator has no way to see
 * them at all.
 *
 * <p><b>Read-only.</b> Values are written by the plugin that owns them, which
 * knows what they mean; an edit box here would let somebody break a sign-in
 * link without knowing it was one.</p>
 */
class MemberValuesMetaBox
{
    public const BOX_ID = 'amber-member-extended-values';

    private readonly string $postType;

    public function __construct(
        Configuration $configuration,
        private readonly MemberRepository $members,
    ) {
        $config = $configuration->getConfig(Member::class) ?? [];
        $this->postType = is_string($config['POST_TYPE'] ?? null) ? $config['POST_TYPE'] : '';

        if ($this->postType !== '') {
            add_action('add_meta_boxes_' . $this->postType, [$this, 'addMetaBox']);
        }
    }

    public function addMetaBox(WP_Post $post): void
    {
        if (!current_user_can('edit_post', $post->ID)) {
            return;
        }

        add_meta_box(self::BOX_ID, 'Extended values', [$this, 'render'], $this->postType, 'side', 'low');
    }

    public function render(WP_Post $post): void
    {
        $values = $this->members->findById($post->ID)?->allUnityExtendedValues() ?? [];

        if ($values === []) {
            echo '<p class="description">No extended values.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>';
        foreach ($values as $key => $value) {
            echo '<tr><td><code>' . esc_html($key) . '</code></td>'
                . '<td style="word-break: break-all;"><code>' . esc_html($value) . '</code></td></tr>';
        }
        echo '</tbody></table>';
    }
}
