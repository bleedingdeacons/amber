<?php

declare(strict_types=1);

namespace Amber\Managers;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Common\AmberConfiguration;
use Amber\Common\Functions;
use Unity\Core\Interfaces\Configuration;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionViewFactory;
use Exception;
use function add_shortcode;
use function esc_html;
use function esc_url;
use function get_field;
use function get_the_ID;
use function wp_kses_post;

/**
 * Registers and renders all position-related shortcodes.
 *
 * Shortcodes handled:
 *  - [position_state]   / [position_highlight] — rotation status badge
 *  - [position_header]  — title, sobriety, term, email link
 *  - [directory_list]   — full directory table
 *  - [position_summary] — rich-text summary field
 */
class PositionShortcodeRenderer
{
    private PositionViewFactory $positionViewFactory;
    private readonly array $position_config;

    public function __construct(
        Configuration $configuration,
        PositionViewFactory $positionViewFactory
    ) {
        $this->position_config    = $configuration->getConfig(Position::class);
        $this->positionViewFactory = $positionViewFactory;

        add_shortcode('position_state', [$this, 'renderPositionState']);
        add_shortcode('position_highlight', [$this, 'renderPositionState']);
        add_shortcode('position_header', [$this, 'renderPositionHeader']);
        add_shortcode('directory_list', [$this, 'renderDirectoryTable']);
        add_shortcode('position_summary', [$this, 'renderPositionSummary']);
    }

    // -----------------------------------------------------------------------
    //  Shortcode callbacks
    // -----------------------------------------------------------------------

    /**
     * [position_state] / [position_highlight]
     *
     * Shows the rotation status and an "Email Service Officer" pseudo-link
     * for the current position post.
     */
    public function renderPositionState(array $atts = [], ?string $content = null): string
    {
        try {
            $positionId = get_the_ID();
            if (!$positionId) {
                throw new Exception('Invalid position ID in renderPositionState.');
            }

            $view   = $this->positionViewFactory->createFrom($positionId);
            $output = '';

            if ($this->isArchivist($view)) {
                $output .= '<h1></h1>';
            } elseif ($view->isVacant()) {
                $output .= '<h1>Vacant!</h1>';
            } else {
                $rotationDate = $view->getRotationDate();
                if (!empty($rotationDate)) {
                    $months  = $view->getMonthsUntilRotation();
                    $output .= '<h1>' . esc_html($this->describeRotationStatus($months)) . '</h1>';
                } else {
                    $output .= '<h1>No Rotation Date!</h1>';
                }
            }

            $output .= '<p style="text-align: right;"><span class="pseudo_link">Email Service Officer</span></p>';

            return $output;
        } catch (Exception $ex) {
            \Amber\Plugin::logError('Error in renderPositionState: ' . $ex->getMessage(), ['exception' => $ex->getMessage(), 'trace' => $ex->getTraceAsString()]);
            return '<p>Error building position state.</p>';
        }
    }

    /**
     * [position_header]
     *
     * Renders the position title, sobriety requirement, term length,
     * and (for filled positions) an email link.
     */
    public function renderPositionHeader(array $atts = [], ?string $content = null): string
    {
        try {
            $positionId = get_the_ID();
            $view       = $this->positionViewFactory->createFrom($positionId);

            $positionTitle  = $view->getTitle();
            $sobrietyMonths = $view->getPosition()->getMinimumSobriety();
            $termYears      = $view->getPosition()->getTermYears();

            $output = '<h1>' . esc_html($positionTitle) . '</h1>';

            if ($sobrietyMonths % 12 > 0) {
                $output .= 'Sobriety ' . esc_html($sobrietyMonths) . ' Months';
            } else {
                $sobrietyYears = $sobrietyMonths / 12;
                $label  = $sobrietyYears == 1 ? 'Year' : 'Years';
                $output .= 'Sobriety ' . esc_html($sobrietyYears) . ' ' . esc_html($label);
            }

            $termLabel = (int) $termYears == 1 ? 'Year' : 'Years';
            if ($this->isArchivist($view)) {
                $output .= '<br>Term Tenure';
            } else {
                $output .= '<br>Term ' . esc_html($termYears) . ' ' . esc_html($termLabel);
            }

            if (!$view->isVacant()) {
                $emailLabel = str_contains($positionTitle, 'Officer') ? 'Officer' : $positionTitle;
                $output    .= '<p style="text-align: right;"><span class="pseudo_link">Email '
                    . esc_html($emailLabel) . '</span></p>';
            }

            return $output;
        } catch (Exception $ex) {
            \Amber\Plugin::logError('Error in renderPositionHeader: ' . $ex->getMessage(), ['exception' => $ex->getMessage(), 'trace' => $ex->getTraceAsString()]);
            return '<p>Error building position header.</p>';
        }
    }

    /**
     * [directory_list]
     *
     * Generates an HTML table of all positions with holder name,
     * email, rotation status, and a link to the position detail page.
     */
    public function renderDirectoryTable(array $atts = [], ?string $content = null): string
    {
        try {
            $views  = $this->positionViewFactory->createAll();
            $output = '<table class="directory" id="service_positions"><thead></thead><tbody>';

            foreach ($views as $view) {
                $email         = $view->getPositionEmail();
                $emailLink     = Functions::createEmailAnchor($email, '', '', $email);
                $description   = esc_html($view->getDescription());
                $positionLink  = '<a class="more" href="' . esc_url($view->getPosition()->getLink()) . '">About</a>';
                $status        = '';
                $anonymousName = '';

                if ($this->isArchivist($view)) {
                    $anonymousName = $view->getPublicDisplayName();
                    $status = 'Filled';
                } elseif ($view->isVacant()) {
                    $status = '<strong>Position Vacant</strong>';
                } else {
                    $anonymousName = $view->getPublicDisplayName();
                    $rotationDate  = $view->getRotationDate();

                    if (!empty($rotationDate)) {
                        $months = $view->getMonthsUntilRotation();
                        $status = esc_html($this->describeRotationStatus($months));
                    } else {
                        $status = '<strong>No Rotation Date!</strong>';
                    }
                }

                $output .= '<tr>'
                    . '<td>' . $description . '</td>'
                    . '<td>' . esc_html($anonymousName) . '</td>'
                    . '<td>' . $emailLink . '</td>'
                    . '<td>' . $status . '</td>'
                    . '<td>' . $positionLink . '</td>'
                    . '</tr>';
            }

            $output .= '</tbody></table>';

            return $output;
        } catch (Exception $ex) {
            \Amber\Plugin::logError('Error in renderDirectoryTable: ' . $ex->getMessage(), ['exception' => $ex->getMessage(), 'trace' => $ex->getTraceAsString()]);
            return '<p>Error generating directory list.</p>';
        }
    }

    /**
     * [position_summary]
     *
     * Outputs the rich-text summary field for the current position.
     */
    public function renderPositionSummary(array $atts = [], ?string $content = null): string
    {
        try {
            $positionId = get_the_ID();
            if (!$positionId) {
                throw new Exception('Invalid position ID in renderPositionSummary.');
            }

            $positionSummary = get_field($this->position_config['SUMMARY'], $positionId, true);

            return '<div>' . wp_kses_post($positionSummary) . '</div>';
        } catch (Exception $ex) {
            \Amber\Plugin::logError('Error in renderPositionSummary: ' . $ex->getMessage(), ['exception' => $ex->getMessage(), 'trace' => $ex->getTraceAsString()]);
            return '<div>Error loading position summary.</div>';
        }
    }

    // -----------------------------------------------------------------------
    //  Helpers
    // -----------------------------------------------------------------------

    /**
     * Check if a position is the Archivist role (permanent tenure, no rotation)
     *
     * @param \Unity\Positions\Interfaces\PositionView $view
     * @return bool
     */
    private function isArchivist(\Unity\Positions\Interfaces\PositionView $view): bool
    {
        $description = $view->getDescription() ?? '';
        return strcasecmp(trim($description), 'Archivist') === 0;
    }

    /**
     * Produce a human-readable rotation status string for the given months-until-rotation.
     */
    private function describeRotationStatus(?int $months): string
    {
        if ($months === null) {
            return 'Status Unknown';
        }
        if ($months < 0) {
            return 'Rotation Overdue!';
        }
        if ($months === 0) {
            return 'Rotation Due Now';
        }
        if ($months === 1) {
            return 'Rotation Next Month';
        }
        if ($months <= AmberConfiguration::SERVICE_EXPIRE_MONTHS_WARNING) {
            return 'Rotates in ' . $months . ' Months';
        }

        return '';
    }
}
