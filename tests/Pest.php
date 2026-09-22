<?php

declare(strict_types=1);

// Pest configuration.
//
// Almost every test here reaches WordPress-registering code — admin screens,
// meta boxes, shortcodes, the boot sequence — and so runs on AmberTestCase,
// which wraps wp-mocks' TestCase: Brain Monkey's lifecycle, Mockery
// integration, the reset of the shared stub state, and Amber's own seeding
// helpers ($this->setOption(), $this->makePost(), $this->mockContainer(),
// $this->capture(), $this->assertHookAdded() and the rest).
//
// Three files need none of it and stay on Pest's default, plain PHPUnit: the
// CSV-cell escaping in ReportsAdminCsvTest is pure string work, and the two
// MeetingReconciler files drive the reconciler through Mockery doubles alone
// (they pull in MockeryPHPUnitIntegration themselves, so their expectations
// are still verified).
//
// So this list is load-bearing. A test that reaches Brain Monkey from a file
// not named here finds none of its functions defined; a new WordPress-coupled
// test file belongs in one of these directories, or has to be named here.

use Amber\Tests\AmberTestCase;

pest()->extend(AmberTestCase::class)->in(
    'Unit/Admin/Committees',
    'Unit/Admin/Meetings',
    'Unit/Admin/Members',
    'Unit/Admin/Positions',
    'Unit/Admin/DeveloperDashboardTest.php',
    'Unit/Admin/NameValidatorTest.php',
    'Unit/Admin/PersonalEmailValidatorTest.php',
    'Unit/Admin/IntergroupMeetings/IntergroupAttendanceAdminTest.php',
    'Unit/Admin/IntergroupMeetings/IntergroupMeetingAdminTest.php',
    'Unit/Admin/IntergroupMeetings/IntergroupMeetingAttendanceDashboardTest.php',
    'Unit/Admin/IntergroupMeetings/IntergroupMeetingDashboardTest.php',
    'Unit/Admin/IntergroupMeetings/ReportsAdminTest.php',
    'Unit/Core',
    'Unit/Shortcodes',
    'Unit/Managers/IntergroupManagerTest.php',
    'Unit/Managers/PositionShortcodeRendererTest.php',
    'Unit/PluginTest.php',
    'Unit/SupportClassesTest.php',
    'Unit/SupportClassesExtraTest.php',
);
