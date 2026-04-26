<?php

namespace Amber\Utils;

use Confur\Config\Constants;

/**
 * HTML helper utilities
 */
if (!class_exists('HtmlHelper')) {
    class HtmlHelper
    {
        /**
         * Generate PDF link
         *
         * @param string $url PDF URL
         * @param string $name PDF filename
         * @param string $content Link content
         * @return string Rendered HTML
         */
        public static function generatePdfLink(string $url, string $name, string $content): string
        {
            return sprintf(
                '<a href="%s" download="%s" type="application/pdf" target="_blank" rel="noreferrer noopener">%s</a>',
                esc_attr($url),
                esc_attr($name),
                $content
            );
        }

        /**
         * Create generic link
         *
         * @param string $href Link href
         * @param string $class CSS class
         * @param string|null $content Link content
         * @return string Rendered HTML
         */
        public static function createLink(string $href, string $class = '', ?string $content = null): string
        {
            return sprintf(
                '<a target="_blank" rel="noreferrer noopener" class="%s" href="%s">%s</a>',
                $class,
                $href,
                $content
            );
        }

        /**
         * Create email mailto address
         *
         * @param string $address Email address
         * @param string|null $subject Email subject
         * @return string Email mailto URL
         */
        public static function createEmailToAddress(string $address, ?string $subject = null): string
        {
            if (!empty($subject)) {
                $address = $address . '?subject=' . $subject;
            }

            return 'mailto:' . $address;
        }

        /**
         * Create email anchor
         *
         * @param string $address Email address
         * @param string|null $subject Email subject
         * @param string|null $content Link content
         * @return string Rendered HTML
         */
        public static function createEmailAnchor(string $address, ?string $subject = null, ?string $content = null): string
        {
            $target = self::createEmailToAddress($address, $subject);

            return sprintf(
                '<a href="%s">%s</a>',
                esc_attr($target),
                $content
            );
        }

        /**
         * Create phone tel address
         *
         * @param string $number Phone number
         * @return string Phone tel URL
         */
        public static function createPhoneToAddress(string $number): string
        {
            return 'tel:' . $number;
        }

        /**
         * Create meeting link
         *
         * @param string $slug Meeting slug
         * @return string Meeting URL
         */
        public static function createMeetingLink(string $slug): string
        {
            return '/meetings/?meeting=' . $slug;
        }
    }
}