<?php

declare(strict_types=1);

namespace Amber\Core;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Help Page
 *
 * Renders the Amber admin help documentation and handles the
 * redirect-to-external-HTML-tab behaviour.
 *
 * The inline help page is a self-contained HTML document rendered
 * inside a WordPress admin page wrapper. All styles are scoped
 * under .amber-help-page to avoid interfering with the admin UI.
 */
class HelpPage
{
    /**
     * Render the full Amber help documentation as a WordPress admin page.
     *
     * This is the callback for the 'amber-help' submenu page.
     */
    public static function render(): void
    {
        $templatePath = AMBER_PLUGIN_DIR . 'templates/help-page.php';

        if (file_exists($templatePath)) {
            include $templatePath;
            return;
        }

        // Fallback: minimal notice if the template is missing
        echo '<div class="wrap"><h1>Amber Help</h1>';
        echo '<p>The help documentation template could not be loaded. ';
        echo 'Check that <code>templates/help-page.php</code> exists in the Amber plugin directory.</p>';
        echo '</div>';
    }

    /**
     * Intercept the Help submenu click, open amber.html in a named tab, and
     * focus it. window.open() is safe here because it is called inside a direct
     * user click event, which browsers do not treat as a popup.
     */
    public static function enqueueHelpTabScript(): void
    {
        $adminUrl = admin_url('admin.php?page=amber-help');
        $helpUrl  = plugins_url('assets/docs/amber.html', dirname(__DIR__, 2) . '/amber.php');
        ?>
        <script>
            (function () {
                var link = document.querySelector('a[href="<?php echo esc_js($adminUrl); ?>"]');
                if (!link) {
                    link = document.querySelector('a[href*="page=amber-help"]');
                }
                if (!link) return;
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.name = 'amber-admin';
                    var helpUrl = '<?php echo esc_js($helpUrl); ?>' + '?back=' + encodeURIComponent(window.location.href);
                    var existing = window.open('', 'amber-help');
                    try {
                        if (existing && existing.location && existing.location.href && existing.location.href !== 'about:blank') {
                            existing.focus();
                            return;
                        }
                    } catch (ex) {}
                    existing.location.href = helpUrl;
                });
            })();
        </script>
        <?php
    }
}
