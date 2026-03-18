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
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionViewFactory;
use Exception;
use function add_action;
use function add_post_meta;
use function delete_post_meta;
use function get_post_type;
use function get_the_ID;
use function update_post_meta;

/**
 * Manages position meta-data updates and post-title syncing hooks.
 *
 * Shortcode rendering has been moved to {@see PositionShortcodeRenderer}.
 * Title-sync logic is delegated to {@see PostTitleSyncer}.
 */
class IntergroupManager
{
    private PositionViewFactory $positionViewFactory;
    private PostTitleSyncer $titleSyncer;
    private readonly array $member_config;
    private readonly array $position_config;

    public function __construct(
        Configuration $configuration,
        PositionViewFactory $positionViewFactory,
        PostTitleSyncer $titleSyncer
    ) {
        $this->member_config       = $configuration->getConfig(Member::class);
        $this->position_config     = $configuration->getConfig(Position::class);
        $this->positionViewFactory = $positionViewFactory;
        $this->titleSyncer         = $titleSyncer;

        add_action('template_redirect', [$this, 'updatePositionMeta']);
        add_action('unity/member_before_save', [$this, 'onMemberBeforeSave'], 10, 2);
        add_action('unity/position_before_save', [$this, 'onPositionBeforeSave'], 10, 2);
    }

    /**
     * Sync the member post title with the anonymous-name ACF field.
     *
     * @param int          $postId         The post ID being saved.
     * @param Member|null  $originalMember The member state before changes (null for new posts).
     */
    public function onMemberBeforeSave(int $postId, ?Member $originalMember): void
    {
        $this->titleSyncer->sync(
            $postId,
            $this->member_config['FIELD_ANONYMOUS_NAME'],
            'Member'
        );
    }

    /**
     * Sync the position post title with the short-description ACF field.
     *
     * @param int            $postId           The post ID being saved.
     * @param Position|null  $originalPosition The position state before changes (null for new posts).
     */
    public function onPositionBeforeSave(int $postId, ?Position $originalPosition): void
    {
        $this->titleSyncer->sync(
            $postId,
            $this->position_config['SHORT_DESCRIPTION'],
            'Position'
        );
    }

    /**
     * Recalculate highlight flag and email link for the current position post.
     *
     * Hooked to `template_redirect`; only runs when viewing a position post type.
     */
    public function updatePositionMeta(): void
    {
        try {
            if (get_post_type() !== $this->position_config['POST_TYPE']) {
                return;
            }

            $positionId = get_the_ID();

            if (!$positionId) {
                return;
            }

            $view          = $this->positionViewFactory->createFrom($positionId);
            $showHighlight = 'no';

            if ($view->isVacant()) {
                $showHighlight = 'yes';
                $this->removePostMeta($positionId, '_email_officer_link');
            } else {
                $rotationDate = $view->getRotationDate();

                if (!empty($rotationDate)) {
                    $months = $view->getMonthsUntilRotation();
                    if ($months <= AmberConfiguration::SERVICE_EXPIRE_MONTHS_WARNING) {
                        $showHighlight = 'yes';
                    }
                } else {
                    $showHighlight = 'yes';
                }

                $genericEmailAddress = $view->getPositionEmail();

                if (!$genericEmailAddress) {
                    error_log("Generic email address not found for position ID: $positionId");
                } else {
                    $officerEmailAddress = Functions::emailTo($genericEmailAddress, 'I have a Question');
                    $this->setPostMeta($positionId, '_email_officer_link', $officerEmailAddress);
                }
            }

            $this->setPostMeta($positionId, '_show_highlight', $showHighlight);
        } catch (Exception $ex) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log('Error in updatePositionMeta: ' . $ex->getMessage());
        }
    }

    // -----------------------------------------------------------------------
    //  Post-meta helpers
    // -----------------------------------------------------------------------

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
        delete_post_meta($postId, $metaName);
    }
}
