<?php

declare(strict_types=1);

namespace Amber\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Admin\DeveloperDashboard;
use Amber\Admin\IntergroupMeetings\IntergroupAttendanceAdmin;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingAdmin;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingAttendanceDashboard;
use Amber\Admin\IntergroupMeetings\IntergroupMeetingDashboard;
use Amber\Admin\IntergroupMeetings\ReportsAdmin;
use Amber\Admin\Meetings\MeetingAdmin;
use Amber\Admin\Meetings\MeetingDashboard;
use Amber\Admin\Members\DirectoryDashboard;
use Amber\Admin\Members\MemberAdmin;
use Amber\Admin\Members\AnonymousNameValidator;
use Amber\Admin\Positions\PositionAdmin;
use Amber\Admin\Positions\PositionDashboard;
use Amber\Admin\Positions\PositionNameValidator;
use Amber\Managers\FrontPageManager;
use Amber\Managers\IntergroupManager;
use Amber\Managers\MeetingReconciler;
use Amber\Managers\PositionShortcodeRenderer;
use Amber\Managers\PostTitleSyncer;
use Amber\Services\ShortcodeService;
use Amber\Shortcodes\TodaysMeetingsShortcode;
use Psr\Container\ContainerInterface;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Core\Interfaces\Configuration;
use Unity\Core\Interfaces\Container;
use Unity\Groups\Interfaces\GroupFactory;
use Unity\Groups\Interfaces\GroupRepository;
use Unity\Groups\Interfaces\GroupViewFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingGroupAttendanceRepository;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceFactory;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeetingOfficerAttendanceRepository;
use Unity\Meetings\Interfaces\MeetingRepository;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberRevisor;
use Unity\Positions\Interfaces\PositionFactory;
use Unity\Positions\Interfaces\PositionRepository;
use Unity\Positions\Interfaces\PositionViewFactory;

/**
 * Amber Service Provider
 *
 * Registers all Amber services in Unity's dependency container.
 * Extracted from Plugin::registerServices() for clarity and to
 * follow the same pattern as Unity\Core\UnityServiceProvider.
 */
class AmberServiceProvider
{
    /**
     * Register all Amber services in the container.
     *
     * @param Container $container The Unity dependency container
     * @return void
     */
    public function register(Container $container): void
    {
        $this->registerManagers($container);
        $this->registerAdminServices($container);
        $this->registerDashboards($container);
        $this->registerReconciler($container);
    }

    /**
     * Register non-admin managers (always loaded).
     */
    private function registerManagers(Container $container): void
    {
        $container->register(PostTitleSyncer::class, function (ContainerInterface $c) {
            return new PostTitleSyncer();
        });

        $container->register(IntergroupManager::class, function (ContainerInterface $c) {
            return new IntergroupManager(
                $c->get(Configuration::class),
                $c->get(PositionViewFactory::class),
                $c->get(PostTitleSyncer::class)
            );
        });

        $container->register(PositionShortcodeRenderer::class, function (ContainerInterface $c) {
            return new PositionShortcodeRenderer(
                $c->get(Configuration::class),
                $c->get(PositionViewFactory::class)
            );
        });

        $container->register(ShortcodeService::class, function (ContainerInterface $c) {
            return new ShortcodeService();
        });

        $container->register(FrontPageManager::class, function (ContainerInterface $c) {
            return new FrontPageManager(
                $c->get(MeetingRepository::class)
            );
        });
    }

    /**
     * Register admin-facing services (list tables, validators, column hooks).
     */
    private function registerAdminServices(Container $container): void
    {
        $container->register(MemberAdmin::class, function (ContainerInterface $c) {
            return new MemberAdmin(
                $c->get(Configuration::class),
                $c->get(PositionFactory::class),
                $c->get(MemberRepository::class),
                $c->get(GroupFactory::class)
            );
        });

        $container->register(AnonymousNameValidator::class, function (ContainerInterface $c) {
            return new AnonymousNameValidator(
                $c->get(Configuration::class)
            );
        });

        $container->register(PositionAdmin::class, function (ContainerInterface $c) {
            return new PositionAdmin(
                $c->get(Configuration::class),
                $c->get(PositionViewFactory::class),
                $c->get(PositionRepository::class)
            );
        });

        $container->register(PositionNameValidator::class, function (ContainerInterface $c) {
            return new PositionNameValidator(
                $c->get(Configuration::class)
            );
        });

        $container->register(MeetingAdmin::class, function (ContainerInterface $c) {
            return new MeetingAdmin(
                $c->get(Configuration::class),
                $c->get(GroupRepository::class),
                $c->get(GroupViewFactory::class),
                $c->get(MemberRepository::class)
            );
        });

        $container->register(IntergroupMeetingAdmin::class, function (ContainerInterface $c) {
            return new IntergroupMeetingAdmin(
                $c->get(Configuration::class),
                $c->get(IntergroupMeetingFactory::class),
                $c->get(IntergroupMeetingRepository::class),
                $c->get(IntergroupMeetingGroupAttendanceFactory::class),
                $c->get(IntergroupMeetingGroupAttendanceRepository::class),
                $c->get(IntergroupMeetingOfficerAttendanceFactory::class),
                $c->get(IntergroupMeetingOfficerAttendanceRepository::class),
                $c->get(GroupRepository::class),
                $c->get(MemberRepository::class),
                $c->get(PositionFactory::class),
                $c->get(PositionRepository::class),
                $c->get(PositionViewFactory::class),
                $c->get(MeetingRepository::class),
                $c->get(GroupViewFactory::class)
            );
        });
    }

    /**
     * Register dashboard widgets.
     */
    private function registerDashboards(Container $container): void
    {
        $container->register(PositionDashboard::class, function (ContainerInterface $c) {
            return new PositionDashboard(
                $c->get(Configuration::class),
                $c->get(PositionViewFactory::class),
                $c->get(PositionRepository::class)
            );
        });

        $container->register(DirectoryDashboard::class, function (ContainerInterface $c) {
            return new DirectoryDashboard(
                $c->get(Configuration::class),
                $c->get(MemberRepository::class),
                $c->get(GroupFactory::class),
                $c->get(PositionViewFactory::class),
                $c->get(PositionRepository::class),
                $c->get(PersonalDataPolicy::class)
            );
        });

        $container->register(MeetingDashboard::class, function (ContainerInterface $c) {
            $reconciler = null;
            if (function_exists('concordance')) {
                try {
                    $reconciler = $c->get(MeetingReconciler::class);
                } catch (\Throwable $e) {
                    \Amber\Plugin::logError('Amber: MeetingReconciler unavailable: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
                }
            }

            return new MeetingDashboard(
                $c->get(MeetingRepository::class),
                $c->get(GroupRepository::class),
                $reconciler
            );
        });

        $container->register(IntergroupMeetingDashboard::class, function (ContainerInterface $c) {
            return new IntergroupMeetingDashboard(
                $c->get(IntergroupMeetingRepository::class),
                $c->get(IntergroupMeetingGroupAttendanceRepository::class),
                $c->get(IntergroupMeetingOfficerAttendanceRepository::class),
                $c->get(MemberRepository::class)
            );
        });

        $container->register(IntergroupAttendanceAdmin::class, function (ContainerInterface $c) {
            return new IntergroupMeetingAttendanceDashboard(
                $c->get(IntergroupMeetingGroupAttendanceRepository::class),
                $c->get(IntergroupMeetingOfficerAttendanceRepository::class)
            );
        });

        $container->register(ReportsAdmin::class, function (ContainerInterface $c) {
            return new ReportsAdmin(
                $c->get(IntergroupMeetingGroupAttendanceRepository::class),
                $c->get(IntergroupMeetingOfficerAttendanceRepository::class),
                $c->get(PositionRepository::class),
                $c->get(PositionViewFactory::class),
                $c->get(GroupRepository::class),
                $c->get(GroupViewFactory::class)
            );
        });

        $container->register(DeveloperDashboard::class, function (ContainerInterface $c) {
            return new DeveloperDashboard(
                $c->get(MemberRepository::class),
                $c->get(MemberRevisor::class)
            );
        });
    }

    /**
     * Register the meeting reconciler (bridges Unity meetings with AAGBDB data).
     */
    private function registerReconciler(Container $container): void
    {
        $container->register(MeetingReconciler::class, function (ContainerInterface $c) {
            return new MeetingReconciler(
                $c->get(MeetingRepository::class),
                concordance()->get(\Concordance\Api\ApiCache::class)
            );
        });
    }
}