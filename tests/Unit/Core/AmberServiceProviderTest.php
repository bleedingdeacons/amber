<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Core;

use Amber\Admin\DeveloperDashboard;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingAdmin;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingDashboard;
use Amber\Admin\IntergroupMeetings\ReportsAdmin;
use Amber\Admin\Meetings\MeetingAdmin;
use Amber\Admin\Meetings\MeetingDashboard;
use Amber\Admin\Members\AnonymousNameValidator;
use Amber\Admin\Members\DirectoryDashboard;
use Amber\Admin\Members\MemberAdmin;
use Amber\Admin\Positions\PositionAdmin;
use Amber\Admin\Positions\PositionDashboard;
use Amber\Admin\Positions\PositionNameValidator;
use Amber\Core\AmberServiceProvider;
use Amber\Managers\FrontPageManager;
use Amber\Managers\IntergroupManager;
use Amber\Managers\MeetingReconciler;
use Amber\Managers\PositionShortcodeRenderer;
use Amber\Managers\PostTitleSyncer;
use Amber\Services\ShortcodeService;

/*
 * Tests for the container wiring.
 *
 * AmberServiceProvider is the one place that knows how every Amber service is
 * built, so a missing registration or a mis-wired constructor argument here
 * surfaces at runtime as a container "not found" or a TypeError deep inside
 * boot. The test registers the provider against a recording container and then
 * runs each stored factory, proving both that the service is registered and
 * that its factory constructs the concrete type it promises.
 */

covers(AmberServiceProvider::class);

/**
 * Every id the provider is expected to register, mapped to the concrete
 * class its factory must return. IntergroupAttendanceAdmin is the one id
 * whose implementation class differs from its key.
 */
const AMBER_SERVICES = [
    PostTitleSyncer::class            => PostTitleSyncer::class,
    IntergroupManager::class          => IntergroupManager::class,
    PositionShortcodeRenderer::class  => PositionShortcodeRenderer::class,
    ShortcodeService::class           => ShortcodeService::class,
    FrontPageManager::class           => FrontPageManager::class,
    MemberAdmin::class                => MemberAdmin::class,
    AnonymousNameValidator::class     => AnonymousNameValidator::class,
    PositionAdmin::class              => PositionAdmin::class,
    PositionNameValidator::class      => PositionNameValidator::class,
    MeetingAdmin::class               => MeetingAdmin::class,
    IntergroupMeetingAdmin::class     => IntergroupMeetingAdmin::class,
    PositionDashboard::class          => PositionDashboard::class,
    DirectoryDashboard::class         => DirectoryDashboard::class,
    MeetingDashboard::class           => MeetingDashboard::class,
    IntergroupMeetingDashboard::class => IntergroupMeetingDashboard::class,
    ReportsAdmin::class               => ReportsAdmin::class,
    DeveloperDashboard::class         => DeveloperDashboard::class,
];

it('registers every amber service', function () {
    $container = $this->mockContainer();

    (new AmberServiceProvider())->register($container);

    foreach (array_keys(AMBER_SERVICES) as $id) {
        expect($container->has($id))->toBeTrue("$id was not registered");
    }

    // The reconciler is registered too, but only built when Concordance is
    // present, so it is exercised separately below.
    expect($container->has(MeetingReconciler::class))->toBeTrue();
});

it('builds the concrete service every factory promises', function () {
    $container = $this->mockContainer();

    (new AmberServiceProvider())->register($container);

    foreach (AMBER_SERVICES as $id => $concrete) {
        $service = $container->build($id);

        expect($service)->toBeInstanceOf($concrete, "$id built the wrong type");
    }
});

it('builds the meeting dashboard without a reconciler when concordance is absent', function () {
    // function_exists('concordance') is false under test, so the dashboard
    // factory must skip the reconciler rather than fatal trying to reach it.
    $container = $this->mockContainer();

    (new AmberServiceProvider())->register($container);

    expect($container->build(MeetingDashboard::class))->toBeInstanceOf(MeetingDashboard::class);
});
