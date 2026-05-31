=== Amber ===
Contributors: thebleedingdeacons
Tags: admin, intergroup, management, unity, gdpr
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.23.9
Build date: 2026/05/31
Requires PHP: 8.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Admin components for the Unity intergroup management plugin. Requires Scrutiny for GDPR compliance.

== Description ==

**Admin components for the Unity intergroup management plugin. Requires Scrutiny for GDPR compliance.**

Amber provides the WordPress admin interface layer for the [Unity](https://github.com/bleeding-deacons/unity) intergroup management framework. It adds admin menus, dashboard widgets, column customisations, shortcodes, and data-validation hooks for managing members, positions, meetings, and intergroup meetings — all backed by Unity's service container and Scrutiny's audit logging.

**Dependencies:** Unity, Scrutiny

**Key features:**

* **Intergroup admin menu** — top-level *Intergroup* menu grouping Positions, Members, Groups / Meetings, and Intergroup Meetings into a single navigation tree.
* **Dashboard widgets** — three dashboard panels: Position Directory (current holders and rotation status), Meeting Listings (sorted by day/time with reconciliation badges), and Intergroup Meeting Attendance (group and officer attendance records).
* **Position shortcodes** — `[position_state]`, `[position_header]`, `[directory_list]`, and `[position_summary]` for embedding position data in posts and pages.
* **Admin column enhancements** — extra columns and sort options on the Members, Meetings, and Positions admin list tables (GSR status, group name, service position, rotation info).
* **Field validation** — real-time AJAX uniqueness checks for anonymous names and position long names, with server-side ACF validation as a safety net.
* **Post title syncing** — automatically keeps post titles in sync with designated ACF field values for members and positions, with re-entrancy guards to prevent infinite loops.
* **Meeting reconciliation** — when the Concordance plugin is active, Amber cross-references local Unity meetings against the national AAGBDB group listing and displays match/mismatch badges on the meeting dashboard.
* **Intergroup meeting management** — full admin interface for creating intergroup meetings and recording group attendance (GSR/proxy) and officer attendance (position-linked).
* **Scrutiny integration** — requires the Scrutiny plugin, ensuring all personal-data access is audit-logged and GDPR-compliant.

== Installation ==

= From a .zip archive =

1. Ensure the **Unity** and **Scrutiny** plugins are installed and activated.
2. Download or build the `amber.zip` archive.
3. In WordPress, go to **Plugins → Add New → Upload Plugin**.
4. Upload the `.zip` file and click **Install Now**.
5. Activate the plugin.

= Manual installation =

1. Clone or copy the `amber` directory into `wp-content/plugins/`.
2. Activate the plugin from the WordPress admin.

Amber will refuse to activate if Unity or Scrutiny is not available, and will auto-deactivate if Scrutiny is deactivated while Amber is active.

== Frequently Asked Questions ==

= Where can I get support? =

Contact The Bleeding Deacons at thebleedingdeacons@gmail.com.

== Screenshots ==

1. Plugin admin settings page.

== Changelog ==

= 1.9.9 =
* Current stable release.

== Upgrade Notice ==

= 1.9.9 =
Latest stable release of Amber.

== Architecture ==

Amber follows a service-oriented architecture, registering all services into Unity's existing PSR-11 container.

```
amber/
├── Amber.php                                    # Plugin bootstrap & dependency checks
├── composer.json                                # Dependencies & PSR-4 autoloading
├── build.php                                    # Cross-platform build/packaging script
├── assets/
│   ├── docs/amber.html                          # Bundled HTML documentation
│   └── js/
│       ├── anonymous-name-validator.js          # AJAX uniqueness check (members)
│       └── position-name-validator.js           # AJAX uniqueness check (positions)
└── src/
    ├── Plugin.php                               # Service registration & initialization
    ├── Common/
    │   ├── AmberConfiguration.php               # Field constants
    │   └── Functions.php                        # Utility helpers (email links, etc.)
    ├── Managers/
    │   ├── IntergroupManager.php                # Position meta updates & hooks
    │   ├── MeetingReconciler.php                # AAGBDB reconciliation engine
    │   ├── PositionShortcodeRenderer.php        # Shortcode registration & rendering
    │   └── PostTitleSyncer.php                  # Title ↔ ACF field sync
    ├── Models/
    │   └── ReconciliationResult.php             # Immutable reconciliation value object
    └── Admin/
        ├── Members/
        │   ├── MemberAdmin.php                  # Member list table customisation
        │   └── AnonymousNameValidator.php       # AJAX + ACF name uniqueness
        ├── Positions/
        │   ├── PositionAdmin.php                # Position list table & meta
        │   ├── PositionDashboard.php            # Dashboard widget
        │   └── PositionNameValidator.php        # AJAX + ACF name uniqueness
        ├── Meetings/
        │   ├── MeetingAdmin.php                 # Meeting list table (group column)
        │   └── MeetingDashboard.php             # Dashboard widget + reconciliation
        └── IntergroupMeetings/
            ├── IntergroupMeetingAdmin.php                    # Full CRUD admin
            ├── IntergroupMeetingDashboard.php                # Dashboard widget
            ├── IntergroupMeetingAttendanceDashboard.php      # Attendance detail page
            └── IntergroupMeetingGroupAttendanceDashboard.php # Group attendance view
```

**Service dependency graph:**

* `PostTitleSyncer` — standalone
* `IntergroupManager` → Configuration, PositionViewFactory, PostTitleSyncer
* `PositionShortcodeRenderer` → Configuration, PositionViewFactory
* `MeetingReconciler` → MeetingRepository, Concordance ApiCache (optional)
* All admin classes → various Unity repositories and factories

All services are registered as lazy singletons in Unity's container and only instantiated when first requested.

== Requirements ==

* **WordPress** 6.0+
* **PHP** 8.1+
* **Unity** plugin — installed and activated
* **Scrutiny** plugin — installed and activated (GDPR compliance)
* **Advanced Custom Fields (ACF)** — used by Unity for data storage
* **Concordance** (optional) — enables meeting reconciliation against the AAGBDB national listing

== Usage ==

= Admin Menus =

Once activated, an **Intergroup** menu appears in the WordPress admin sidebar with sub-pages for:

* **Positions** — manage intergroup service positions
* **Members** — manage intergroup members with anonymous names and GSR status
* **Groups / Meetings** — view and manage meetings linked to groups
* **Intergroup Meetings** — create meetings and record group/officer attendance

= Dashboard Widgets =

Three widgets appear on the WordPress admin dashboard:

* **Position Directory** — lists all positions with current holders, sobriety dates, term dates, and rotation status.
* **Meeting Listings** — meetings sorted by day and start time. When Concordance is active, each card shows a reconciliation badge indicating whether the meeting was found in the national listing.
* **Intergroup Meeting Attendance** — select a meeting from a dropdown to see group attendance (with GSR and proxy info) and officer attendance tables.

= Shortcodes =

Amber registers four shortcodes for embedding position data in posts and pages:

* `[position_state]` / `[position_highlight]` — displays a rotation-status badge
* `[position_header]` — renders the position title, sobriety requirement, term, and email link
* `[directory_list]` — outputs a full directory table of all positions and holders
* `[position_summary]` — renders the rich-text summary field for a position

= PHP (Service Container) =

Amber registers its services in Unity's container. Use the global `amber()` helper to resolve them:

```php
// Access Amber's container (which is Unity's container)
$container = amber();

// Hook into Amber's initialization
add_action('amber/loaded', function (\Psr\Container\ContainerInterface $container) {
    // Amber is ready
});
```
