<?php

declare(strict_types=1);

namespace Amber\Admin\GroupListings;

use Concordance\Api\ApiCache;
use Concordance\Models\GroupListing;

use function add_action;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_current_screen;
use function is_wp_error;
use function wp_add_dashboard_widget;

/**
 * Group Listing Dashboard Widget
 *
 * Adds a dashboard panel listing all groups from the AAGBDB API
 * via the Concordance plugin's ApiCache and GroupListing model.
 *
 * Renders from the raw API data (via GroupListing::getRaw()) so
 * every field the API returns is displayed regardless of how the
 * model maps it.
 */
class GroupListingDashboard
{
    private ApiCache $apiCache;

    /**
     * Fields to skip when rendering the detail grid.
     * These are either shown in the card header or are internal.
     */
    private const HEADER_FIELDS = [
        'id', 'groupName', 'intergroupId', 'intergroupName',
    ];

    /**
     * Fields that look like URLs and should be rendered as links.
     */
    private const URL_FIELDS = [
        'website', 'web', 'url', 'link', 'online_link', 'zoom',
    ];

    /**
     * Constructor
     *
     * @param ApiCache $apiCache Concordance API cache service
     */
    public function __construct(ApiCache $apiCache)
    {
        $this->apiCache = $apiCache;

        add_action('wp_dashboard_setup', [$this, 'registerDashboardWidget']);
        add_action('admin_head', [$this, 'addDashboardStyles']);
    }

    /**
     * Register the dashboard widget
     */
    public function registerDashboardWidget(): void
    {
        wp_add_dashboard_widget(
            'concordance_group_listings_dashboard',
            'AAGBDB Group Listings',
            [$this, 'renderDashboardWidget'],
            null,
            null,
            'normal',
            'high'
        );
    }

    /**
     * Render the dashboard widget content
     */
    public function renderDashboardWidget(): void
    {
        $response = $this->apiCache->getGroups();

        if (is_wp_error($response)) {
            echo '<div class="gl-error">';
            echo '<span class="dashicons dashicons-warning"></span> ';
            echo '<span>' . esc_html($response->get_error_message()) . '</span>';
            echo '</div>';
            return;
        }

        $groups = GroupListing::collectionFromResponse($response);

        // Filter to intergroup ID 1
        $groups = array_values(array_filter(
            $groups,
            static fn(GroupListing $g) => $g->getIntergroupId() === 1
        ));

        if (empty($groups)) {
            echo '<p class="gl-empty">No groups found from the AAGBDB API.</p>';
            return;
        }

        // Sort by day, then time, then name
        GroupListing::sort($groups, 'day,time,name');

        echo '<div class="gl-dashboard-widget">';

        // Summary
        echo '<div class="gl-summary">';
        echo '<div class="gl-summary-item"><span class="gl-summary-count">' . count($groups) . '</span> Groups</div>';
        echo '</div>';

        foreach ($groups as $group) {
            $this->renderGroupCard($group);
        }

        echo '</div>';
    }

    /**
     * Render a single group card from the raw API data.
     *
     * The card header shows the group name (resolved from common key
     * names). The body renders every other field the API returned as
     * a label/value pair.
     *
     * @param GroupListing $group Group listing model
     */
    private function renderGroupCard(GroupListing $group): void
    {
        $raw = $group->getRaw();
        $name = $this->resolveName($raw);

        echo '<div class="gl-card">';

        // --- Header ---
        echo '<div class="gl-card-header">';
        echo '<div class="gl-card-title">';
        echo '<strong>' . esc_html($name ?: ((string) ($raw['groupName'] ?? 'Unknown Group'))) . '</strong>';
        echo '</div>';
        echo '</div>';

        // --- Body: render every field except those used in the header ---
        $fields = $this->getDisplayFields($raw);

        if (!empty($fields)) {
            echo '<div class="gl-card-content">';

            foreach ($fields as $label => $value) {
                $isFullWidth = strlen((string) $value) > 80;
                $fieldClass = $isFullWidth ? 'gl-card-field gl-card-field-full' : 'gl-card-field';

                echo '<div class="' . $fieldClass . '">';
                echo '<div class="gl-field-label">' . esc_html($label) . '</div>';
                echo '<div class="gl-field-value">';
                echo $this->renderFieldValue($label, $value);
                echo '</div>';
                echo '</div>';
            }

            echo '</div>';
        }

        echo '</div>'; // .gl-card
    }

    /**
     * Resolve the group name from common API key patterns.
     *
     * @param array $raw Raw API data
     * @return string
     */
    private function resolveName(array $raw): string
    {
        return (string) ($raw['groupName'] ?? $raw['name'] ?? $raw['title'] ?? '');
    }

    /**
     * Build a filtered, human-labelled array of fields to display.
     *
     * Skips header fields, empty values, and nested arrays/objects.
     *
     * @param array $raw Raw API data
     * @return array<string, string> Label => value pairs
     */
    private function getDisplayFields(array $raw): array
    {
        $fields = [];

        foreach ($raw as $key => $value) {
            // Skip header fields
            if (in_array($key, self::HEADER_FIELDS, true)) {
                continue;
            }

            // Skip empty, null, nested arrays/objects
            if ($value === null || $value === '' || is_array($value) || is_object($value)) {
                continue;
            }

            // Skip boolean false
            if ($value === false) {
                continue;
            }

            // Convert boolean true to 'Yes'
            if ($value === true) {
                $value = 'Yes';
            }

            $label = $this->humaniseKey($key);
            $fields[$label] = (string) $value;
        }

        return $fields;
    }

    /**
     * Render a field value, detecting URLs and email addresses.
     *
     * @param string $label Human-readable label
     * @param string $value Raw value
     * @return string HTML
     */
    private function renderFieldValue(string $label, string $value): string
    {
        // Detect URL fields
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            return '<a href="' . esc_url($value) . '" target="_blank" rel="noopener">' . esc_html($value) . '</a>';
        }

        // Detect email fields
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
        }

        return esc_html($value);
    }

    /**
     * Convert a snake_case or camelCase API key to a human-readable label.
     *
     * Examples: "start_time" → "Start Time", "postCode" → "Post Code"
     *
     * @param string $key API field key
     * @return string Human-readable label
     */
    private function humaniseKey(string $key): string
    {
        // Insert space before capitals in camelCase
        $key = (string) preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);

        // Replace underscores and hyphens with spaces
        $key = str_replace(['_', '-'], ' ', $key);

        return ucwords(trim($key));
    }

    /**
     * Add custom styles for the dashboard widget
     */
    public function addDashboardStyles(): void
    {
        $screen = get_current_screen();

        if (!$screen || $screen->id !== 'dashboard') {
            return;
        }

        echo '<style>
            .gl-dashboard-widget {
                margin: -12px -12px 0 -12px;
            }

            .gl-summary {
                display: flex;
                gap: 12px;
                padding: 12px 16px;
                background: #f0f6fc;
                border-bottom: 1px solid #c3c4c7;
                margin-bottom: 12px;
            }

            .gl-summary-item {
                font-size: 12px;
                color: #50575e;
                font-weight: 500;
            }

            .gl-summary-count {
                font-size: 16px;
                font-weight: 700;
                color: #1d2327;
                margin-right: 2px;
            }

            .gl-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                margin: 0 0 8px 0;
                transition: box-shadow 0.2s;
            }

            .gl-card:hover {
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }

            .gl-card:last-child {
                margin-bottom: 0;
            }

            .gl-card-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 16px;
                background: #f9f9f9;
                border-bottom: 1px solid #e0e0e0;
                border-radius: 4px 4px 0 0;
                gap: 12px;
            }

            .gl-card-title {
                flex: 1;
                min-width: 0;
                font-size: 14px;
            }

            .gl-card-title a {
                text-decoration: none;
                color: #2271b1;
            }

            .gl-card-title a:hover {
                color: #135e96;
            }

            .gl-card-content {
                padding: 10px 16px;
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .gl-card-field {
                min-width: 0;
            }

            .gl-card-field-full {
                grid-column: 1 / -1;
            }

            .gl-field-label {
                font-size: 10px;
                text-transform: uppercase;
                color: #666;
                font-weight: 600;
                letter-spacing: 0.5px;
                margin-bottom: 3px;
            }

            .gl-field-value {
                font-size: 13px;
                line-height: 1.5;
                word-wrap: break-word;
            }

            .gl-field-value a {
                color: #2271b1;
                text-decoration: none;
            }

            .gl-field-value a:hover {
                color: #135e96;
                text-decoration: underline;
            }

            .gl-no-data {
                color: #999;
                font-style: italic;
            }

            .gl-error {
                display: flex;
                align-items: center;
                gap: 8px;
                padding: 12px 16px;
                background: #fcf0f1;
                border-left: 4px solid #d63638;
                color: #d63638;
                font-size: 13px;
            }

            .gl-empty {
                color: #666;
                font-style: italic;
                padding: 8px 0;
            }

            @media (max-width: 600px) {
                .gl-card-content {
                    grid-template-columns: 1fr;
                }
            }
        </style>';
    }
}
