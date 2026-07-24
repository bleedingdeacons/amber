<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\IntergroupManager;
use Amber\Managers\PostTitleSyncer;
use Amber\Tests\AmberTestCase;
use Amber\Tests\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/**
 * Tests for the position meta / title-sync manager.
 *
 * IntergroupManager does two jobs. On save it keeps each post's title in step
 * with the ACF field that really names it, delegating to PostTitleSyncer — so
 * the test asserts the right field name reaches the syncer for each post type.
 * On the public position page it recomputes two pieces of derived meta: a
 * highlight flag (is this post something an officer should notice — vacant, or
 * rotating soon, or missing its date) and a pre-built "email the officer" link.
 * Those drive the visible warning styling, so the branch that decides "yes,
 * highlight" is the one that matters.
 *
 * @covers \Amber\Managers\IntergroupManager
 */
class IntergroupManagerTest extends AmberTestCase
{
    private const POSITION_TYPE = 'intergroup-position';

    /** @var PositionViewFactory&\PHPUnit\Framework\MockObject\MockObject */
    private $viewFactory;

    private IntergroupManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
            Member::class            => ['FIELD_ANONYMOUS_NAME' => 'anon-name'],
            Position::class          => ['POST_TYPE' => self::POSITION_TYPE, 'SHORT_DESCRIPTION' => 'short-desc'],
            IntergroupMeeting::class => ['FIELD_MEETING_TITLE' => 'meeting-title'],
            default                  => [],
        });

        $this->viewFactory = $this->createMock(PositionViewFactory::class);

        // PostTitleSyncer is a final class, so it cannot be doubled; the real
        // one is used and its effect observed through WpState::$updatedPosts.
        // It has its own tests in SupportClassesTest.
        $this->manager = new IntergroupManager($config, $this->viewFactory, new PostTitleSyncer());
    }

    /** Point WordPress's "current post" at a position of the given id. */
    private function viewingPosition(int $id, ?PositionView $view): void
    {
        WpState::$postTypes[0]                    = self::POSITION_TYPE;
        WpState::$options['__current_post_id']    = $id;
        $this->viewFactory->method('createFrom')->with($id)->willReturn($view);
    }

    private function view(array $overrides = []): PositionView
    {
        $defaults = [
            'isVacant'               => false,
            'isArchivist'            => false,
            'getRotationDate'        => new DateTime('2030-01-01'),
            'getMonthsUntilRotation' => 24,
            'getPositionEmail'       => 'officer@example.test',
        ];

        $view = $this->createMock(PositionView::class);
        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $view->method($method)->willReturn($value);
        }

        return $view;
    }

    // ── registration ─────────────────────────────────────────────────

    /** @test */
    public function it_registers_its_save_and_render_hooks(): void
    {
        $this->assertNotEmpty($this->hooksFor('template_redirect'));
        $this->assertNotEmpty($this->hooksFor('unity/member_before_save'));
        $this->assertNotEmpty($this->hooksFor('unity/position_before_save'));
        $this->assertNotEmpty($this->hooksFor('unity/intergroup_meeting_before_save'));
    }

    // ── title sync delegation ────────────────────────────────────────

    /** @test */
    public function saving_a_member_syncs_the_title_from_the_anonymous_name_field(): void
    {
        $this->makePost(42, self::POSITION_TYPE, ['post_title' => 'Old']);
        $this->setField(42, 'anon-name', 'New Member Name');

        $this->manager->onMemberBeforeSave(42, null);

        // The title only moves if the syncer was handed the right field name.
        $this->assertSame([['ID' => 42, 'post_title' => 'New Member Name']], WpState::$updatedPosts);
    }

    /** @test */
    public function saving_a_position_syncs_the_title_from_the_short_description_field(): void
    {
        $this->makePost(7, self::POSITION_TYPE, ['post_title' => 'Old']);
        $this->setField(7, 'short-desc', 'Treasurer');

        $this->manager->onPositionBeforeSave(7, null);

        $this->assertSame([['ID' => 7, 'post_title' => 'Treasurer']], WpState::$updatedPosts);
    }

    /** @test */
    public function saving_an_intergroup_meeting_syncs_the_title_from_the_meeting_title_field(): void
    {
        $this->makePost(9, self::POSITION_TYPE, ['post_title' => 'Old']);
        $this->setField(9, 'meeting-title', 'March Intergroup');

        $this->manager->onIntergroupMeetingBeforeSave(9, null);

        $this->assertSame([['ID' => 9, 'post_title' => 'March Intergroup']], WpState::$updatedPosts);
    }

    // ── updatePositionMeta ───────────────────────────────────────────

    /** @test */
    public function meta_is_not_touched_off_a_position_page(): void
    {
        WpState::$postTypes[0] = 'page';

        $this->manager->updatePositionMeta();

        $this->assertSame([], WpState::$postMeta);
    }

    /** @test */
    public function meta_is_not_touched_without_a_current_post(): void
    {
        WpState::$postTypes[0]                 = self::POSITION_TYPE;
        WpState::$options['__current_post_id'] = 0;

        $this->manager->updatePositionMeta();

        $this->assertSame([], WpState::$postMeta);
    }

    /** @test */
    public function a_vacant_position_is_highlighted_and_its_officer_link_removed(): void
    {
        // A pre-existing link must be cleared so a vacant post never advertises
        // a mailbox nobody is reading.
        WpState::$postMeta[7]['_email_officer_link'] = 'mailto:old@example.test';
        $this->viewingPosition(7, $this->view(['isVacant' => true, 'isArchivist' => false]));

        $this->manager->updatePositionMeta();

        $this->assertSame('yes', WpState::$postMeta[7]['_show_highlight']);
        $this->assertArrayNotHasKey('_email_officer_link', WpState::$postMeta[7]);
    }

    /** @test */
    public function a_position_rotating_soon_is_highlighted_and_gets_an_officer_link(): void
    {
        $this->viewingPosition(7, $this->view(['getMonthsUntilRotation' => 3]));

        $this->manager->updatePositionMeta();

        $this->assertSame('yes', WpState::$postMeta[7]['_show_highlight']);
        $this->assertStringContainsString('mailto:officer@example.test', WpState::$postMeta[7]['_email_officer_link']);
    }

    /** @test */
    public function a_position_rotating_far_off_is_not_highlighted(): void
    {
        $this->viewingPosition(7, $this->view(['getMonthsUntilRotation' => 24]));

        $this->manager->updatePositionMeta();

        $this->assertSame('no', WpState::$postMeta[7]['_show_highlight']);
    }

    /** @test */
    public function a_position_with_no_rotation_date_is_highlighted(): void
    {
        // No date means nobody has set a rotation — worth an officer's eye.
        $this->viewingPosition(7, $this->view(['getRotationDate' => null]));

        $this->manager->updatePositionMeta();

        $this->assertSame('yes', WpState::$postMeta[7]['_show_highlight']);
    }

    /** @test */
    public function a_filled_position_without_an_email_records_no_officer_link(): void
    {
        $this->viewingPosition(7, $this->view(['getPositionEmail' => '']));

        $this->manager->updatePositionMeta();

        $this->assertArrayNotHasKey('_email_officer_link', WpState::$postMeta[7] ?? []);
        $this->assertSame('no', WpState::$postMeta[7]['_show_highlight']);
    }

    /** @test */
    public function an_error_while_updating_meta_is_swallowed(): void
    {
        // The method runs on template_redirect for every position page view,
        // so a repository blow-up must never surface to the visitor.
        WpState::$postTypes[0]                 = self::POSITION_TYPE;
        WpState::$options['__current_post_id'] = 7;
        $this->viewFactory->method('createFrom')->willThrowException(new \RuntimeException('boom'));

        $this->manager->updatePositionMeta();

        // No fatal, and nothing written for the post.
        $this->assertArrayNotHasKey(7, WpState::$postMeta);
    }
}
