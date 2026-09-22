<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin;

use Amber\Admin\Members\AnonymousNameValidator;
use Amber\Admin\Positions\PositionNameValidator;
use BleedingDeacons\WpMocks\Exceptions\JsonResponseException;
use BleedingDeacons\WpMocks\WpState;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;

/*
 * Tests for the two uniqueness validators.
 *
 * Members are identified by an anonymous name and positions by a name, and
 * both must be unique — a duplicate makes two records indistinguishable on
 * every screen that lists them. Each validator therefore guards the same
 * rule twice: live over AJAX while typing, and again server-side on save,
 * because the AJAX check is advisory and a determined save bypasses it.
 *
 * The two classes are near-identical in shape, so they are exercised
 * together and the parallel is asserted rather than left implicit.
 */

covers(AnonymousNameValidator::class, PositionNameValidator::class);

const NAME_MEMBER_TYPE = 'intergroup-member';
const NAME_POSITION_TYPE = 'intergroup-position';

/** Make the next uniqueness query report an existing post. */
function existingPost(int $id): void
{
    WpState::$queryPosts = [$id];
}

beforeEach(function () {
    $config = $this->createMock(Configuration::class);
    $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
        Member::class => [
            'POST_TYPE' => NAME_MEMBER_TYPE,
            'FIELD_ANONYMOUS_NAME' => 'about-layout-group_anonymous-name',
        ],
        Position::class => [
            'POST_TYPE' => NAME_POSITION_TYPE,
            'FIELD_POSITION_LONG_NAME' => 'position-long-name',
            'FIELD_POSITION_SHORT_NAME' => 'position-short-name',
        ],
        default => [],
    });

    $this->memberValidator = new AnonymousNameValidator($config);
    $this->positionValidator = new PositionNameValidator($config);
});

// ── registration ─────────────────────────────────────────────────
it('registers an ajax endpoint and a save filter for both validators', function () {
    $this->assertHookAdded('wp_ajax_amber_validate_anonymous_name');
    $this->assertHookAdded('wp_ajax_amber_validate_position_name');
    // Server-side validation is keyed to the ACF field, so a save that
    // skips the browser is still checked.
    $this->assertHookAdded('acf/validate_value/key=field_66461796ab271');
    $this->assertHookAdded('acf/validate_value/key=field_66720958da8b5');
    $this->assertHookAdded('acf/input/admin_enqueue_scripts');
});

// ── script enqueuing ─────────────────────────────────────────────
describe('script enqueuing', function () {
    it('loads the member validator script only on the member screen', function () {
        $this->setScreen('post', 'post', NAME_MEMBER_TYPE);

        $this->memberValidator->enqueueScripts();

        expect(WpState::$enqueued)->not->toBeEmpty()
            ->and(WpState::$localized)->toHaveKey('amberMemberAnonymousName')
            ->and(WpState::$localized['amberMemberAnonymousName'])->toHaveKey('nonce');
    });

    it('skips the member validator script elsewhere', function () {
        $this->setScreen('post', 'post', 'page');

        $this->memberValidator->enqueueScripts();

        expect(WpState::$enqueued)->toBe([]);
    });

    it('skips the member validator script without a screen', function () {
        WpState::$screen = null;

        $this->memberValidator->enqueueScripts();

        expect(WpState::$enqueued)->toBe([]);
    });

    it('loads the position validator script only on the position screen', function () {
        $this->setScreen('post', 'post', NAME_POSITION_TYPE);

        $this->positionValidator->enqueueScripts();

        expect(WpState::$enqueued)->not->toBeEmpty();
    });

    it('skips the position validator script elsewhere', function () {
        $this->setScreen('post', 'post', 'page');

        $this->positionValidator->enqueueScripts();

        expect(WpState::$enqueued)->toBe([]);
    });
});

// ── AJAX: member ─────────────────────────────────────────────────
describe('ajax', function () {
    it('reports an unused anonymous name valid', function () {
        $_POST = ['value' => 'Anonymous Alex', 'post_id' => '42'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue()
                ->and($e->data['valid'])->toBeTrue();
        }
    });

    it('reports a duplicate anonymous name invalid with the clashing post', function () {
        existingPost(99);
        $_POST = ['value' => 'Anonymous Alex', 'post_id' => '42'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->data['valid'])->toBeFalse()
                // Naming the clashing post is what makes the message actionable.
                ->and($e->data['message'])->toContain('99');
        }
    });

    it('does not treat an empty anonymous name as a clash', function () {
        // Emptiness is ACF's required-field problem, not a uniqueness one.
        existingPost(99);
        $_POST = ['value' => '', 'post_id' => '42'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->data['valid'])->toBeTrue();
        }
    });

    it('refuses the ajax check without edit permission', function () {
        $this->denyCapability();
        $_POST = ['value' => 'Anonymous Alex'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeFalse();
        }
    });

    it('answers the position ajax check the same way', function () {
        $_POST = ['value' => 'Treasurer', 'post_id' => '7'];

        try {
            $this->positionValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeTrue()
                ->and($e->data['valid'])->toBeTrue();
        }
    });

    it('reports a duplicate position name invalid', function () {
        existingPost(88);
        $_POST = ['value' => 'Treasurer', 'post_id' => '7'];

        try {
            $this->positionValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->data['valid'])->toBeFalse();
        }
    });

    it('refuses the position ajax check without permission', function () {
        $this->denyCapability();
        $_POST = ['value' => 'Treasurer'];

        try {
            $this->positionValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            expect($e->success)->toBeFalse();
        }
    });
});

// ── save-time validation ─────────────────────────────────────────
describe('save-time validation', function () {
    it('passes a unique anonymous name', function () {
        $_POST = ['_acf_post_id' => '42'];

        expect($this->memberValidator->validateOnSave(true, 'Anonymous Alex', [], 'acf[field]'))->toBeTrue();
    });

    it('returns an error message for a duplicate anonymous name', function () {
        existingPost(99);
        $_POST = ['_acf_post_id' => '42'];

        $result = $this->memberValidator->validateOnSave(true, 'Anonymous Alex', [], 'acf[field]');

        expect($result)->toBeString()
            ->toContain('already in use');
    });

    it('leaves an existing validation failure untouched', function () {
        // Another validator already rejected it; ours must not overwrite
        // that message with a pass.
        existingPost(99);

        expect($this->memberValidator->validateOnSave('Already invalid', 'Anonymous Alex', [], 'acf[field]'))
            ->toBe('Already invalid');
    });

    it('passes an empty value', function () {
        existingPost(99);
        $_POST = ['_acf_post_id' => '42'];

        expect($this->memberValidator->validateOnSave(true, '', [], 'acf[field]'))->toBeTrue();
    });

    // ACF puts the post id in _acf_post_id during server-side validation,
    // not the post_id the AJAX handler uses, so both are read with
    // WordPress's own post_ID as a final fallback. Excluding the wrong post
    // would make a record clash with itself and block every save.
    it('excludes the post being edited however its id arrives', function (string $key) {
        // The only match is the post being edited, so it must not count.
        existingPost(0);
        $_POST = [$key => '42'];

        expect($this->memberValidator->validateOnSave(true, 'Anonymous Alex', [], 'acf[field]'))->toBeTrue();
    })->with([
        'acf server-side field' => ['_acf_post_id'],
        'ajax field'            => ['post_id'],
        'wordpress field'       => ['post_ID'],
    ]);

    it('passes a unique position name', function () {
        $_POST = ['_acf_post_id' => '7'];

        expect($this->positionValidator->validateOnSave(true, 'Treasurer', [], 'acf[field]'))->toBeTrue();
    });

    it('returns an error message for a duplicate position name', function () {
        existingPost(88);
        $_POST = ['_acf_post_id' => '7'];

        $result = $this->positionValidator->validateOnSave(true, 'Treasurer', [], 'acf[field]');

        expect($result)->toBeString();
    });

    it('passes an empty position name', function () {
        existingPost(88);
        $_POST = ['_acf_post_id' => '7'];

        expect($this->positionValidator->validateOnSave(true, '', [], 'acf[field]'))->toBeTrue();
    });

    it('leaves an existing position validation failure untouched', function () {
        existingPost(88);

        expect($this->positionValidator->validateOnSave('Already invalid', 'Treasurer', [], 'acf[field]'))
            ->toBe('Already invalid');
    });
});
