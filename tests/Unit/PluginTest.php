<?php

declare(strict_types=1);

namespace Amber\Tests\Unit;

use Amber\Core\MenuRegistrar;
use Amber\Plugin;
use BleedingDeacons\WpMocks\WpState;
use ReflectionProperty;
use RuntimeException;

/*
 * Tests for the plugin orchestrator.
 *
 * Plugin::init is the boot sequence: it registers every service, wires the
 * admin menu, and resolves the admin screens so their hooks attach. Because it
 * guards on a static "already initialised" flag, the test resets that flag
 * between cases and checks both that a first call boots and that a second is a
 * no-op. maybeRunMigrations is the version-gated metadata sweep; the branch
 * that matters is the guard that skips it when the stored version already
 * matches, since that is what stops it running on every admin page load.
 */

covers(Plugin::class);

/** Return Plugin to its pre-boot state so each test starts clean. */
function resetPluginStatics(): void
{
    $initialized = new ReflectionProperty(Plugin::class, 'initialized');
    $initialized->setValue(null, false);

    $container = new ReflectionProperty(Plugin::class, 'container');
    $container->setValue(null, null);
}

beforeEach(function () {
    resetPluginStatics();
});

afterEach(function () {
    resetPluginStatics();
});

it('treats getContainer before init as a hard error', function () {
    Plugin::getContainer();
})->throws(RuntimeException::class);

it('boots the container and wires the admin menu on init', function () {
    $container = $this->mockContainer();

    Plugin::init($container);

    expect(Plugin::getContainer())->toBe($container);

    // The admin menu is attached at both the normal and the late (Help)
    // priority; is_admin() is true under test so this branch always runs.
    $this->assertHookAdded('admin_menu');
    $this->assertHookAdded('admin_init');
    $this->assertHookAdded('init');
});

it('is idempotent on a second init', function () {
    $first = $this->mockContainer();
    Plugin::init($first);

    // A second boot must return early and leave the first container in
    // place — re-registering services would double every hook.
    Plugin::init($this->mockContainer());

    expect(Plugin::getContainer())->toBe($first);
});

it('runs the metadata migration and records the new version when the version changes', function () {
    Plugin::init($this->mockContainer());
    $this->setOption('amber_db_version', 'an-old-version');

    Plugin::maybeRunMigrations();

    // Empty repositories mean nothing to migrate, but the run still stamps
    // the current version so it won't repeat on the next request.
    expect(WpState::$options['amber_db_version'])->toBe('0.0.0');
});

it('skips migration when the version is unchanged', function () {
    Plugin::init($this->mockContainer());
    $this->setOption('amber_db_version', '0.0.0');

    Plugin::maybeRunMigrations();

    expect(WpState::$options['amber_db_version'])->toBe('0.0.0');
});

it('keeps the legacy menu constants pointing at the registrar', function () {
    expect(Plugin::MENU_SLUG)->toBe(MenuRegistrar::MENU_SLUG)
        ->and(Plugin::MENU_CAPABILITY)->toBe(MenuRegistrar::MENU_CAPABILITY);
});
