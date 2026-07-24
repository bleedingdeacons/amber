<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Core;

use Amber\Admin\DeveloperDashboard;
use Amber\Admin\IntergroupMeetings\IntergroupAttendanceAdmin;
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
use Amber\Tests\AmberTestCase;

/**
 * Tests for the container wiring.
 *
 * AmberServiceProvider is the one place that knows how every Amber service is
 * built, so a missing registration or a mis-wired constructor argument here
 * surfaces at runtime as a container "not found" or a TypeError deep inside
 * boot. The test registers the provider against a recording container and then
 * runs each stored factory, proving both that the service is registered and
 * that its factory constructs the concrete type it promises.
 *
 * @covers \Amber\Core\AmberServiceProvider
 */
class AmberServiceProviderTest extends AmberTestCase
{
    /**
     * Every id the provider is expected to register, mapped to the concrete
     * class its factory must return. IntergroupAttendanceAdmin is the one id
     * whose implementation class differs from its key.
     */
    private const SERVICES = [
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

    /** @test */
    public function it_registers_every_amber_service(): void
    {
        $container = $this->mockContainer();

        (new AmberServiceProvider())->register($container);

        foreach (array_keys(self::SERVICES) as $id) {
            $this->assertTrue($container->has($id), "$id was not registered");
        }

        // The reconciler is registered too, but only built when Concordance is
        // present, so it is exercised separately below.
        $this->assertTrue($container->has(MeetingReconciler::class));
    }

    /** @test */
    public function every_factory_builds_the_concrete_service_it_promises(): void
    {
        $container = $this->mockContainer();

        (new AmberServiceProvider())->register($container);

        foreach (self::SERVICES as $id => $concrete) {
            $service = $container->build($id);

            $this->assertInstanceOf($concrete, $service, "$id built the wrong type");
        }
    }

    /** @test */
    public function the_meeting_dashboard_is_built_without_a_reconciler_when_concordance_is_absent(): void
    {
        // function_exists('concordance') is false under test, so the dashboard
        // factory must skip the reconciler rather than fatal trying to reach it.
        $container = $this->mockContainer();

        (new AmberServiceProvider())->register($container);

        $this->assertInstanceOf(MeetingDashboard::class, $container->build(MeetingDashboard::class));
    }
}
