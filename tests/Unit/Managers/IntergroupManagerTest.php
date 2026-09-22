<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Managers;

use Amber\Managers\IntergroupManager;
use Amber\Managers\PostTitleSyncer;
use BleedingDeacons\WpMocks\WpState;
use DateTime;
use Unity\Core\Interfaces\Configuration;
use Unity\IntergroupMeetings\Interfaces\IntergroupMeeting;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;
use Unity\Positions\Interfaces\PositionView;
use Unity\Positions\Interfaces\PositionViewFactory;

/*
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
 */

covers(IntergroupManager::class);

const MANAGER_POSITION_TYPE = 'intergroup-position';

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
        Member::class            => ['FIELD_ANONYMOUS_NAME' => 'anon-name'],
        Position::class          => ['POST_TYPE' => MANAGER_POSITION_TYPE, 'SHORT_DESCRIPTION' => 'short-desc'],
        IntergroupMeeting::class => ['FIELD_MEETING_TITLE' => 'meeting-title'],
        default                  => [],
    });

    $this->viewFactory = $this->createMock(PositionViewFactory::class);

    // PostTitleSyncer is a final class, so it cannot be doubled; the real
    // one is used and its effect observed through WpState::$updatedPosts.
    // It has its own tests in SupportClassesTest.
    $this->manager = new IntergroupManager($config, $this->viewFactory, new PostTitleSyncer());

    /** Point WordPress's "current post" at a position of the given id. */
    $this->viewingPosition = function (int $id, ?PositionView $view): void {
        WpState::$postTypes[0]                    = MANAGER_POSITION_TYPE;
        WpState::$options['__current_post_id']    = $id;
        $this->viewFactory->method('createFrom')->with($id)->willReturn($view);
    };

    $this->view = function (array $overrides = []): PositionView {
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
    };
});

// ── registration ─────────────────────────────────────────────────
it('registers its save and render hooks', function () {
    $this->assertHookAdded('template_redirect');
    $this->assertHookAdded('unity/member_before_save');
    $this->assertHookAdded('unity/position_before_save');
    $this->assertHookAdded('unity/intergroup_meeting_before_save');
});

// ── title sync delegation ────────────────────────────────────────
it('syncs the title from the anonymous name field when saving a member', function () {
    $this->makePost(42, MANAGER_POSITION_TYPE, ['post_title' => 'Old']);
    $this->setField(42, 'anon-name', 'New Member Name');

    $this->manager->onMemberBeforeSave(42, null);

    // The title only moves if the syncer was handed the right field name.
    expect(WpState::$updatedPosts)->toBe([['ID' => 42, 'post_title' => 'New Member Name']]);
});

it('syncs the title from the short description field when saving a position', function () {
    $this->makePost(7, MANAGER_POSITION_TYPE, ['post_title' => 'Old']);
    $this->setField(7, 'short-desc', 'Treasurer');

    $this->manager->onPositionBeforeSave(7, null);

    expect(WpState::$updatedPosts)->toBe([['ID' => 7, 'post_title' => 'Treasurer']]);
});

it('syncs the title from the meeting title field when saving an intergroup meeting', function () {
    $this->makePost(9, MANAGER_POSITION_TYPE, ['post_title' => 'Old']);
    $this->setField(9, 'meeting-title', 'March Intergroup');

    $this->manager->onIntergroupMeetingBeforeSave(9, null);

    expect(WpState::$updatedPosts)->toBe([['ID' => 9, 'post_title' => 'March Intergroup']]);
});

// ── updatePositionMeta ───────────────────────────────────────────
describe('updatePositionMeta', function () {
    it('does not touch meta off a position page', function () {
        WpState::$postTypes[0] = 'page';

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta)->toBe([]);
    });

    it('does not touch meta without a current post', function () {
        WpState::$postTypes[0]                 = MANAGER_POSITION_TYPE;
        WpState::$options['__current_post_id'] = 0;

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta)->toBe([]);
    });

    it('highlights a vacant position and removes its officer link', function () {
        // A pre-existing link must be cleared so a vacant post never advertises
        // a mailbox nobody is reading.
        WpState::$postMeta[7]['_email_officer_link'] = 'mailto:old@example.test';
        ($this->viewingPosition)(7, ($this->view)(['isVacant' => true, 'isArchivist' => false]));

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta[7]['_show_highlight'])->toBe('yes')
            ->and(WpState::$postMeta[7])->not->toHaveKey('_email_officer_link');
    });

    it('highlights a position rotating soon and gives it an officer link', function () {
        ($this->viewingPosition)(7, ($this->view)(['getMonthsUntilRotation' => 3]));

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta[7]['_show_highlight'])->toBe('yes')
            ->and(WpState::$postMeta[7]['_email_officer_link'])->toContain('mailto:officer@example.test');
    });

    it('does not highlight a position rotating far off', function () {
        ($this->viewingPosition)(7, ($this->view)(['getMonthsUntilRotation' => 24]));

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta[7]['_show_highlight'])->toBe('no');
    });

    it('highlights a position with no rotation date', function () {
        // No date means nobody has set a rotation — worth an officer's eye.
        ($this->viewingPosition)(7, ($this->view)(['getRotationDate' => null]));

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta[7]['_show_highlight'])->toBe('yes');
    });

    it('records no officer link for a filled position without an email', function () {
        ($this->viewingPosition)(7, ($this->view)(['getPositionEmail' => '']));

        $this->manager->updatePositionMeta();

        expect(WpState::$postMeta[7] ?? [])->not->toHaveKey('_email_officer_link')
            ->and(WpState::$postMeta[7]['_show_highlight'])->toBe('no');
    });

    it('swallows an error while updating meta', function () {
        // The method runs on template_redirect for every position page view,
        // so a repository blow-up must never surface to the visitor.
        WpState::$postTypes[0]                 = MANAGER_POSITION_TYPE;
        WpState::$options['__current_post_id'] = 7;
        $this->viewFactory->method('createFrom')->willThrowException(new \RuntimeException('boom'));

        $this->manager->updatePositionMeta();

        // No fatal, and nothing written for the post.
        expect(WpState::$postMeta)->not->toHaveKey(7);
    });
});
