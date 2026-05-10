<?php

namespace Amber\Shortcodes;

use Amber\Utils\HtmlHelper;
use DateTime;
use DateTimeZone;
use Throwable;

/**
 * General purpose shortcodes
 *
 * Both Amber and Confur ship this class. The guard below ensures
 * we don't re-declare the Amber\Shortcodes\GeneralShortcodes class
 * if (somehow) this file is included twice. The two plugins' copies
 * live in different namespaces so they never collide with each other.
 */
if (!class_exists(__NAMESPACE__ . '\\GeneralShortcodes')) {
    class GeneralShortcodes
    {
        /**
         * Open link in new tab
         *
         * @param array $atts Shortcode attributes
         * @param string|null $content Shortcode content
         * @return string Rendered HTML
         */
        public function openBlank(array $atts = [], ?string $content = null): string
        {
            try {
                $atts = shortcode_atts([
                    'href' => '#',
                    'class' => ''
                ], $atts);

                return HtmlHelper::createLink(
                    esc_attr($atts['href']),
                    esc_attr($atts['class']),
                    $content
                );
            } catch (Throwable $e) {
                return sprintf(
                    '[openBlank error: %s | href="%s", class="%s"]',
                    $e->getMessage(),
                    $atts['href'] ?? '',
                    $atts['class'] ?? ''
                );
            }
        }

        /**
         * Create email link
         *
         * @param array $atts Shortcode attributes
         * @param string|null $content Shortcode content
         * @return string Rendered HTML
         */
        public function linkEmail(array $atts = [], ?string $content = null): string
        {
            try {
                $atts = shortcode_atts([
                    'address' => '',
                    'subject' => null
                ], $atts);

                if (empty($atts['address'])) {
                    return $content ?? '';
                }

                return HtmlHelper::createEmailAnchor(
                    $atts['address'],
                    $atts['subject'],
                    $content
                );
            } catch (Throwable $e) {
                return sprintf(
                    '[linkEmail error: %s | address="%s", subject="%s"]',
                    $e->getMessage(),
                    $atts['address'] ?? '',
                    $atts['subject'] ?? ''
                );
            }
        }

        /**
         * Generate PDF link
         *
         * @param array $atts Shortcode attributes
         * @param string|null $content Shortcode content
         * @return string Rendered HTML
         */
        public function generatePdfLink(array $atts = [], ?string $content = null): string
        {
            try {
                $atts = shortcode_atts([
                    'url' => '',
                    'name' => ''
                ], $atts);

                if (empty($atts['url']) || empty($atts['name'])) {
                    return '[generatePdfLink error: Missing required parameters | url="' . ($atts['url'] ?? '') . '", name="' . ($atts['name'] ?? '') . '"]';
                }

                return '<div>' . HtmlHelper::generatePdfLink(
                        $atts['url'],
                        $atts['name'],
                        $content
                    ) . '</div>';
            } catch (Throwable $e) {
                return sprintf(
                    '[generatePdfLink error: %s | url="%s", name="%s"]',
                    $e->getMessage(),
                    $atts['url'] ?? '',
                    $atts['name'] ?? ''
                );
            }
        }

        /**
         * Generate days remaining
         *
         * @param array $atts Shortcode attributes
         * @return string Rendered HTML
         */
        public function generateDaysRemaining(array $atts = []): string
        {
            try {
                $atts = shortcode_atts([
                    'end_date' => '',
                    'extend_by' => 0
                ], $atts);

                if (empty($atts['end_date'])) {
                    return 'Please provide an end date.';
                }

                $timezone = $this->getTimezone();
                $endDateString = trim($atts['end_date']);
                $endDateObj = $this->parseDate($endDateString, $timezone);

                if ($endDateObj === null) {
                    return 'Invalid date format. Use "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" (24-hour format).';
                }

                $extendBy = max(0, (int)$atts['extend_by']);
                if ($extendBy > 0) {
                    $endDateObj->modify("+{$extendBy} days");
                }

                $formattedDate = $this->formatDate($endDateObj);
                $currentTime = new DateTime('now', $timezone);
                $diffSeconds = $endDateObj->getTimestamp() - $currentTime->getTimestamp();

                if ($diffSeconds < 0) {
                    return 'The date has already passed.';
                }

                $timeRemainingText = $this->formatTimeRemaining($diffSeconds);
                $extensionText = $this->formatExtensionText($extendBy);

                return "<strong>Deadline:</strong> $formattedDate$extensionText - $timeRemainingText";
            } catch (Throwable $e) {
                return sprintf(
                    '[generateDaysRemaining error: %s | end_date="%s", extend_by="%s"]',
                    $e->getMessage(),
                    $atts['end_date'] ?? '',
                    $atts['extend_by'] ?? ''
                );
            }
        }

        /**
         * Get the WordPress timezone or fallback to UTC
         *
         * @return DateTimeZone
         */
        private function getTimezone(): DateTimeZone
        {
            try {
                if (function_exists('wp_timezone')) {
                    return wp_timezone();
                }
            } catch (Throwable) {
                // Fall through to UTC default
            }

            return new DateTimeZone('UTC');
        }

        /**
         * Parse a date string into a DateTime object
         *
         * @param string $dateString The date string to parse
         * @param DateTimeZone $timezone The timezone to use
         * @return DateTime|null The parsed DateTime or null on failure
         */
        private function parseDate(string $dateString, DateTimeZone $timezone): ?DateTime
        {
            $formats = strpos($dateString, ':') !== false
                ? ['Y-m-d H:i', 'Y-m-d H:i:s']
                : ['Y-m-d'];

            foreach ($formats as $format) {
                $dateObj = DateTime::createFromFormat($format, $dateString, $timezone);
                if ($dateObj !== false) {
                    return $dateObj;
                }
            }

            // Fallback: try generic parsing
            try {
                return new DateTime($dateString, $timezone);
            } catch (Throwable) {
                return null;
            }
        }

        /**
         * Format a DateTime object for display
         *
         * @param DateTime $date The date to format
         * @return string The formatted date string
         */
        private function formatDate(DateTime $date): string
        {
            if ($date->format('H:i:s') === '00:00:00') {
                return $date->format('d/m/Y');
            }

            return $date->format('d/m/Y H:i');
        }

        /**
         * Format the time remaining text
         *
         * @param int $diffSeconds Seconds remaining
         * @return string Formatted time remaining
         */
        private function formatTimeRemaining(int $diffSeconds): string
        {
            if ($diffSeconds < 24 * 60 * 60) {
                $hoursRemaining = max(1, (int)ceil($diffSeconds / 3600));
                $unit = $hoursRemaining === 1 ? 'hour' : 'hours';
                return "<strong>$hoursRemaining</strong> $unit remaining.";
            }

            $daysRemaining = (int)ceil($diffSeconds / (60 * 60 * 24));
            $unit = $daysRemaining === 1 ? 'day' : 'days';
            return "<strong>$daysRemaining</strong> $unit remaining.";
        }

        /**
         * Format the extension text
         *
         * @param int $extendBy Number of days extended
         * @return string Extension text or empty string
         */
        private function formatExtensionText(int $extendBy): string
        {
            if ($extendBy <= 0) {
                return '';
            }

            $unit = $extendBy === 1 ? 'day' : 'days';
            return " (extended by $extendBy $unit)";
        }

    }
}