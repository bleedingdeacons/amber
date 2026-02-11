<?php

declare(strict_types=1);

namespace Amber\Managers;

use Amber\Common\Functions;
use TsmlForUnity\Members\TsmlMemberFields;
use TsmlForUnity\Positions\TsmlPositionFields;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\PositionViewFactory;
use Exception;
use function add_action;
use function add_post_meta;
use function add_shortcode;
use function delete_post_meta;
use function esc_html;
use function esc_url;
use function get_field;
use function get_post;
use function get_post_type;
use function get_the_ID;
use function update_post_meta;
use function wp_kses_post;
use function wp_update_post;

/**
 * Class IntergroupManager
 *
 * Class for managing intergroup.
 */
class IntergroupManager
{
    private PositionViewFactory $positionViewFactory;

    public function __construct(PositionViewFactory $positionViewFactory)
    {
        $this->positionViewFactory = $positionViewFactory;

        add_action('template_redirect', [$this, 'updatePositionMeta']);
        add_action('amber/member_before_save', [$this, 'onMemberBeforeSave'], 10, 2);

        add_shortcode('position_state', [$this, 'getPositionState']);
        add_shortcode('position_highlight', [$this, 'generatePositionState']);
        add_shortcode('position_header', [$this, 'generatePositionHeader']);
        add_shortcode('directory_list', [$this, 'renderDirectoryTable']);
        add_shortcode('position_summary', [$this, 'renderPositionSummary']);
    }

    /**
     * Handle amber/member_before_save hook to always sync post title with anonymous name
     *
     * @param int $postId The post ID being saved
     * @param Member|null $originalMember The original member before changes (may be null for new posts)
     * @return void
     */
    public function onMemberBeforeSave(int $postId, ?Member $originalMember): void
    {
        try {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('amber/member_before_save hook fired for post ID: ' . $postId);
            }

            $post = get_post($postId);

            if (!$post) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('onMemberBeforeSave: Could not get post for ID: ' . $postId);
                }
                return;
            }

            $postTitle = $post->post_title;
            $anonymousName = get_field(TsmlMemberFields::FIELD_ANONYMOUS_NAME, $postId);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('onMemberBeforeSave: Post title is "' . $postTitle . '", Anonymous name is "' . $anonymousName . '"');
            }

            // Always sync the post title with the anonymous name if they differ
            if (!empty($anonymousName) && $postTitle !== $anonymousName) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('onMemberBeforeSave: Updating post title to "' . $anonymousName . '"');
                }

                $result = wp_update_post([
                    'ID' => $postId,
                    'post_title' => $anonymousName
                ]);

                if (defined('WP_DEBUG') && WP_DEBUG) {
                    if (is_wp_error($result)) {
                        error_log('onMemberBeforeSave: wp_update_post failed: ' . $result->get_error_message());
                    } else {
                        error_log('onMemberBeforeSave: wp_update_post succeeded, returned: ' . $result);
                    }
                }
            } elseif (empty($anonymousName)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('onMemberBeforeSave: Anonymous name is empty, not updating');
                }
            } else {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('onMemberBeforeSave: Post title already matches anonymous name, skipping update');
                }
            }
        } catch (Exception $e) {
            error_log('Error in onMemberBeforeSave: ' . $e->getMessage());
        }
    }

    /**
     * Calculate position meta data.
     */
    public function updatePositionMeta(): void
    {
        try {
            if (get_post_type() === TsmlPositionFields::POST_TYPE) {
                $positionId = get_the_ID();

                if (!$positionId) {
                    throw new Exception("Invalid position ID {$positionId} in calculate_position_meta.");
                }

                $view = $this->positionViewFactory->createFrom($positionId);

                $showHighlight = "no";

                if ($view->isVacant()) {
                    $showHighlight = 'yes';
                    $this->removePostMeta($positionId, '_email_officer_link');
                } else {
                    $rotationDate = $view->getRotationDate();
                    if (!empty($rotationDate)) {
                        $months = $view->getMonthsUntilRotation();
                        if ($months <= UnityConfiguration::SERVICE_EXPIRE_MONTHS_WARNING) {
                            $showHighlight = 'yes';
                        }
                    } else {
                        $showHighlight = 'yes';
                    }
                    $genericEmailAddress = $view->getPositionEmail();
                    if (!$genericEmailAddress) {
                        throw new Exception("Generic email address not found for position ID: " . $positionId);
                    }
                    $officerEmailAddress = Functions::emailTo($genericEmailAddress, 'I have a Question');
                    $this->setPostMeta($positionId, '_email_officer_link', $officerEmailAddress);
                }

                $this->setPostMeta($positionId, '_show_highlight', $showHighlight);
            }
        } catch (Exception $ex) {
            error_log('Error in updatePositionMeta: ' . $ex->getMessage());
        }
    }

    private function setPostMeta(int $postId, string $metaName, mixed $value): void
    {
        if (!add_post_meta($postId, $metaName, $value, true)) {
            if (update_post_meta($postId, $metaName, $value) === false) {
                error_log("Failed to update post meta '$metaName' for post ID $postId");
            }
        }
    }

    private function removePostMeta(int $postId, string $metaName): void
    {
        if (!delete_post_meta($postId, $metaName)) {
            error_log("Failed to delete post meta '$metaName' for post ID $postId");
        }
    }

    public function renderPositionSummary(): string
    {
        try {
            $positionId = get_the_ID();
            if (!$positionId) {
                throw new Exception("Invalid position ID in insert_position_summary.");
            }
            $positionSummary = get_field(UnityFields::POSITION_SUMMARY, $positionId, true);
            return '<div>' . wp_kses_post($positionSummary) . '</div>';
        } catch (Exception $ex) {
            error_log('Error in insert_position_summary: ' . $ex->getMessage());
            return '<div>Error loading position summary.</div>';
        }
    }

    public function renderDirectoryTable(array $atts = [], ?string $content = null): string
    {
        try {
            $views = $this->positionViewFactory->createAll();
            $output = '<table class="directory" id="service_positions"><thead></thead><tbody>';

            foreach ($views as $view) {
                $email = $view->getPositionEmail();
                $emailLink = Functions::createEmailAnchor($email, '', '', $email);
                $description = esc_html($view->getDescription());
                $positionLink = '<a class="more" href="' . esc_url($view->getPosition()->getLink()) . '">About</a>';
                $status = '';
                $anonymousName = '';

                if ($view->isVacant()) {
                    $status = '<strong>Position Vacant</strong>';
                } else {
                    $anonymousName = $view->getPublicDisplayName();
                    $rotationDate = $view->getRotationDate();

                    if (!empty($rotationDate)) {
                        $months = $view->getMonthsUntilRotation();
                        $status = esc_html($this->generatePositionStatus($months));
                    } else {
                        $status = '<strong>No Rotation Date!</strong>';
                    }
                }

                $output .= '<tr><td>' . $description . '</td><td>' . esc_html($anonymousName) . '</td><td>' . $emailLink . '</td><td>' . $status . '</td><td>' . $positionLink . '</td></tr>';
            }
            $output .= '</tbody></table>';

            return $output;
        } catch (Exception $ex) {
            error_log('Error in generate_directory_list: ' . $ex->getMessage());
            return '<p>Error generating directory list.</p>';
        }
    }

    private function generatePositionStatus(?int $months): string
    {
        try {
            if ($months === null) {
                return 'Status Unknown';
            }
            if ($months < 0) {
                return 'Rotation Overdue!';
            } elseif ($months === 0) {
                return 'Rotation Due Now';
            } elseif ($months === 1) {
                return 'Rotation Next Month';
            } elseif ($months <= (int) UnityConfiguration::SERVICE_EXPIRE_MONTHS_WARNING) {
                return 'Rotates in ' . $months . ($months == 1 ? ' Month' : ' Months');
            } else {
                return '';
            }
        } catch (Exception $ex) {
            error_log('Error in generatePositionStatus: ' . $ex->getMessage());
            return 'Status Unknown';
        }
    }

    public function generatePositionState(): string
    {
        try {
            $positionId = get_the_ID();
            if (!$positionId) {
                throw new Exception("Invalid position ID in build_position_state.");
            }

            $view = $this->positionViewFactory->createFrom($positionId);
            $output = '';

            if (!$view->isVacant()) {
                $output .= '<h1>Vacant!</h1>';
            } else {
                $rotationDate = $view->getRotationDate();
                if (!empty($rotationDate)) {
                    $months = $view->getMonthsUntilRotation();
                    $output .= '<h1>' . esc_html($this->generatePositionStatus($months)) . '</h1>';
                } else {
                    $output .= '<h1>No Rotation Date!</h1>';
                }
            }
            $output .= '<p style="text-align: right;"><span class="pseudo_link">Email Service Officer</span></p>';

            return $output;
        } catch (Exception $ex) {
            error_log('Error in build_position_state: ' . $ex->getMessage());
            return '<p>Error building position state.</p>';
        }
    }

    public function generatePositionHeader(): string
    {
        try {
            $output = '';
            $positionId = get_the_ID();
            $view = $this->positionViewFactory->createFrom($positionId);

            $positionTitle = $view->getTitle();
            $sobrietyMonths = $view->getPosition()->getMinimumSobriety();
            $termYears = $view->getPosition()->getTermYears();

            $output .= '<h1>' . esc_html($positionTitle) . '</h1>';

            if ($sobrietyMonths % 12 > 0) {
                $output .= 'Sobriety ' . esc_html($sobrietyMonths) . ' Months';
            } else {
                $sobrietyYears = $sobrietyMonths / 12;
                $output .= 'Sobriety ' . esc_html($sobrietyYears) . ' ' . esc_html(($sobrietyYears == 1 ? 'Year' : 'Years'));
            }
            $output .= '<br>Term ' . esc_html($termYears) . ' ' . esc_html(((int)$termYears == 1 ? 'Year' : 'Years'));

            if (!$view->isVacant()) {
                if (str_contains($positionTitle, 'Officer')) {
                    $positionTitle = 'Officer';
                }
                $output .= '<p style="text-align: right;"><span class="pseudo_link">Email ' . esc_html($positionTitle) . '</span></p>';
            }
            return $output;
        } catch (Exception $ex) {
            error_log('Error in build_position_header: ' . $ex->getMessage());
            return '<p>Error building position header.</p>';
        }
    }

    public function getPositionState(array $atts = [], ?string $content = null): string
    {
        return $this->generatePositionState();
    }
}