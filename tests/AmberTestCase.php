<?php

declare(strict_types=1);

namespace Amber\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Base case for tests that touch the WordPress stubs.
 *
 * Resets the shared state between tests — it lives in statics and a global,
 * because the stubs are plain functions with nowhere else to keep it — and
 * offers the handful of helpers every admin test needs: seeding options,
 * fields and posts, and capturing what a render method echoes.
 */
abstract class AmberTestCase extends TestCase
{
    protected FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WpState::reset();

        $this->wpdb = $GLOBALS['wpdb'];
        $this->wpdb->reset();

        $_GET = [];
        $_POST = [];
        $_REQUEST = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_POST = [];
        $_REQUEST = [];

        parent::tearDown();
    }

    // ── seeding ──────────────────────────────────────────────────────

    protected function setOption(string $name, mixed $value): void
    {
        WpState::$options[$name] = $value;
    }

    /** Seed an ACF field value for a post. */
    protected function setField(int $postId, string $selector, mixed $value): void
    {
        WpState::$fields[$postId . '|' . $selector] = $value;
    }

    /** Seed a post, returning it. */
    protected function makePost(int $id, string $type = 'post', array $extra = []): \WP_Post
    {
        $post = new \WP_Post(array_merge([
            'ID'         => $id,
            'post_type'  => $type,
            'post_title' => 'Post ' . $id,
            'post_status' => 'publish',
        ], $extra));

        WpState::$posts[$id] = $post;
        WpState::$postTypes[$id] = $type;

        return $post;
    }

    protected function setPostMeta(int $postId, string $key, mixed $value): void
    {
        WpState::$postMeta[$postId][$key] = $value;
    }

    /** Set the screen get_current_screen() reports. */
    protected function setScreen(string $id, string $base = 'post', string $postType = ''): void
    {
        WpState::$screen = new \WP_Screen([
            'id' => $id,
            'base' => $base,
            'post_type' => $postType,
        ]);
    }

    /** Make current_user_can() answer false for everything. */
    protected function denyCapability(): void
    {
        WpState::$userCan = false;
    }

    // ── container ────────────────────────────────────────────────────

    /**
     * A container that resolves any dependency to a usable double.
     *
     * Configuration yields a config array carrying every key Amber's
     * constructors read; the final, side-effect-free PersonalDataPolicy is
     * built for real; any repository's findAll() answers with an empty array
     * so migration sweeps iterate cleanly; everything else is a bare mock.
     */
    protected function mockContainer(): RecordingContainer
    {
        return new RecordingContainer(function (string $id) {
            if ($id === \Unity\Core\Interfaces\Configuration::class) {
                $config = $this->createMock(\Unity\Core\Interfaces\Configuration::class);
                $config->method('getConfig')->willReturn([
                    'POST_TYPE'                 => 'intergroup-member',
                    'FIELD_ANONYMOUS_NAME'      => 'anon-name',
                    'SHORT_DESCRIPTION'         => 'short-desc',
                    'FIELD_MEETING_TITLE'       => 'meeting-title',
                    'FIELD_POSITION_LONG_NAME'  => 'position-long-name',
                    'FIELD_POSITION_SHORT_NAME' => 'position-short-name',
                ]);

                return $config;
            }

            if ($id === \Scrutiny\Privacy\PersonalDataPolicy::class) {
                return new \Scrutiny\Privacy\PersonalDataPolicy();
            }

            $mock = $this->createMock($id);
            if (method_exists($id, 'findAll')) {
                $mock->method('findAll')->willReturn([]);
            }

            return $mock;
        });
    }

    // ── assertions helpers ───────────────────────────────────────────

    /** Capture everything a callable echoes. */
    protected function capture(callable $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /** Hooks registered for a given WordPress action/filter. */
    protected function hooksFor(string $hook): array
    {
        return WpState::$hooks[$hook] ?? [];
    }

    /** Menu entries registered via add_menu_page()/add_submenu_page(). */
    protected function registeredMenuSlugs(): array
    {
        return array_column(WpState::$menus, 'slug');
    }
}
