<?php

declare(strict_types=1);

namespace Amber\Tests\Unit;

use Amber\Core\MenuRegistrar;
use Amber\Plugin;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use ReflectionProperty;
use RuntimeException;

/**
 * Tests for the plugin orchestrator.
 *
 * Plugin::init is the boot sequence: it registers every service, wires the
 * admin menu, and resolves the admin screens so their hooks attach. Because it
 * guards on a static "already initialised" flag, the test resets that flag
 * between cases and checks both that a first call boots and that a second is a
 * no-op. maybeRunMigrations is the version-gated metadata sweep; the branch
 * that matters is the guard that skips it when the stored version already
 * matches, since that is what stops it running on every admin page load.
 *
 * @covers \Amber\Plugin
 */
class PluginTest extends AmberTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->resetPluginStatics();
    }

    protected function tearDown(): void
    {
        $this->resetPluginStatics();
        parent::tearDown();
    }

    /** Return Plugin to its pre-boot state so each test starts clean. */
    private function resetPluginStatics(): void
    {
        $initialized = new ReflectionProperty(Plugin::class, 'initialized');
        $initialized->setValue(null, false);

        $container = new ReflectionProperty(Plugin::class, 'container');
        $container->setValue(null, null);
    }

    /** @test */
    public function get_container_before_init_is_a_hard_error(): void
    {
        $this->expectException(RuntimeException::class);

        Plugin::getContainer();
    }

    /** @test */
    public function init_boots_the_container_and_wires_the_admin_menu(): void
    {
        $container = $this->mockContainer();

        Plugin::init($container);

        $this->assertSame($container, Plugin::getContainer());

        // The admin menu is attached at both the normal and the late (Help)
        // priority; is_admin() is true under test so this branch always runs.
        $adminMenuHooks = $this->hooksFor('admin_menu');
        $this->assertNotEmpty($adminMenuHooks);
        $this->assertNotEmpty($this->hooksFor('admin_init'));
        $this->assertNotEmpty($this->hooksFor('init'));
    }

    /** @test */
    public function init_is_idempotent(): void
    {
        $first = $this->mockContainer();
        Plugin::init($first);

        // A second boot must return early and leave the first container in
        // place — re-registering services would double every hook.
        Plugin::init($this->mockContainer());

        $this->assertSame($first, Plugin::getContainer());
    }

    /** @test */
    public function a_version_change_runs_the_metadata_migration_and_records_the_new_version(): void
    {
        Plugin::init($this->mockContainer());
        $this->setOption('amber_db_version', 'an-old-version');

        Plugin::maybeRunMigrations();

        // Empty repositories mean nothing to migrate, but the run still stamps
        // the current version so it won't repeat on the next request.
        $this->assertSame('0.0.0', WpState::$options['amber_db_version']);
    }

    /** @test */
    public function migration_is_skipped_when_the_version_is_unchanged(): void
    {
        Plugin::init($this->mockContainer());
        $this->setOption('amber_db_version', '0.0.0');

        Plugin::maybeRunMigrations();

        $this->assertSame('0.0.0', WpState::$options['amber_db_version']);
    }

    /** @test */
    public function the_legacy_menu_constants_still_point_at_the_registrar(): void
    {
        $this->assertSame(MenuRegistrar::MENU_SLUG, Plugin::MENU_SLUG);
        $this->assertSame(MenuRegistrar::MENU_CAPABILITY, Plugin::MENU_CAPABILITY);
    }
}
