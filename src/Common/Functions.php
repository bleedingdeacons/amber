<?php

declare(strict_types=1);

namespace Amber\Common;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use function esc_attr;
use function esc_html;

/**
 * Class Functions
 * 
 * Utility functions for common operations
 */
class Functions
{
    /**
     * Create a mailto link
     *
     * @param string $address Email address
     * @param string $subject Optional email subject
     * @return string Mailto URL
     */
    public static function emailTo(string $address, string $subject = ''): string
    {
        if (!empty($subject)) {
            $address = $address . '?subject=' . urlencode($subject);
        }

        return 'mailto:' . $address;
    }

    /**
     * Create a tel link
     *
     * @param string $number Phone number
     * @return string Tel URL
     */
    public static function phoneTo(string $number): string
    {
        return 'tel:' . $number;
    }

    /**
     * Create an anchor link
     *
     * @param string $href Link URL
     * @param string $class CSS class
     * @param string $text Link text
     * @return string HTML anchor element
     */
    public static function linkTo(string $href, string $class, string $text = ''): string
    {
        return '<a target="_blank" rel="noreferrer noopener" class="' . esc_attr($class) . '" href="' . esc_attr($href) . '">' . esc_html($text) . '</a>';
    }

    /**
     * Create an email anchor link
     *
     * @param string $address Email address
     * @param string $subject Email subject
     * @param string $class CSS class
     * @param string $text Link text
     * @return string HTML anchor element
     */
    public static function createEmailAnchor(string $address, string $subject, string $class, string $text): string
    {
        $address = self::emailTo($address, $subject);

        return self::linkTo($address, $class, $text);
    }
}
