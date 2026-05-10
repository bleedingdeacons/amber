<?php

declare(strict_types=1);

namespace Amber\Services;

use Amber\Shortcodes\GeneralShortcodes;

/**
 * Registers Amber's general-purpose shortcodes.
 *
 * The same shortcode tags (open_new_link, open_email, pdf_link, days_remaining)
 * are also registered by the Confur plugin. Either plugin may run first; each
 * `add_shortcode` call is guarded by `shortcode_exists` so whichever plugin
 * loads first wins and the other no-ops. Both classes share an identical
 * public surface, so the resulting behaviour is the same either way.
 */
class ShortcodeService
{
    private GeneralShortcodes $generalShortcodes;

    public function __construct()
    {
        $this->generalShortcodes = new GeneralShortcodes();
    }

    /**
     * Register general-purpose shortcodes, skipping any tag that another
     * plugin (e.g. Confur) has already registered.
     */
    public function registerShortcodes(): void
    {
        $tags = [
            'open_new_link'  => 'openBlank',
            'open_email'     => 'linkEmail',
            'pdf_link'       => 'generatePdfLink',
            'days_remaining' => 'generateDaysRemaining',
        ];

        foreach ($tags as $tag => $method) {
            if (!shortcode_exists($tag)) {
                add_shortcode($tag, [$this->generalShortcodes, $method]);
            }
        }
    }
}
