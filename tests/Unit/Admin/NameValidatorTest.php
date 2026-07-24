<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin;

use Amber\Admin\Members\AnonymousNameValidator;
use Amber\Admin\Positions\PositionNameValidator;
use Amber\Tests\AmberTestCase;
use Amber\Tests\JsonResponseException;
use Amber\Tests\WpState;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;
use Unity\Positions\Interfaces\Position;

/**
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
 *
 * @covers \Amber\Admin\Members\AnonymousNameValidator
 * @covers \Amber\Admin\Positions\PositionNameValidator
 */
class NameValidatorTest extends AmberTestCase
{
    private const MEMBER_TYPE = 'intergroup-member';
    private const POSITION_TYPE = 'intergroup-position';

    private AnonymousNameValidator $memberValidator;
    private PositionNameValidator $positionValidator;

    protected function setUp(): void
    {
        parent::setUp();

        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(static fn (string $key): array => match ($key) {
            Member::class => [
                'POST_TYPE' => self::MEMBER_TYPE,
                'FIELD_ANONYMOUS_NAME' => 'about-layout-group_anonymous-name',
            ],
            Position::class => [
                'POST_TYPE' => self::POSITION_TYPE,
                'FIELD_POSITION_LONG_NAME' => 'position-long-name',
                'FIELD_POSITION_SHORT_NAME' => 'position-short-name',
            ],
            default => [],
        });

        $this->memberValidator = new AnonymousNameValidator($config);
        $this->positionValidator = new PositionNameValidator($config);
    }

    /** Make the next uniqueness query report an existing post. */
    private function existingPost(int $id): void
    {
        WpState::$queryPosts = [$id];
    }

    // ── registration ─────────────────────────────────────────────────

    /** @test */
    public function both_validators_register_an_ajax_endpoint_and_a_save_filter(): void
    {
        $this->assertNotEmpty($this->hooksFor('wp_ajax_amber_validate_anonymous_name'));
        $this->assertNotEmpty($this->hooksFor('wp_ajax_amber_validate_position_name'));
        // Server-side validation is keyed to the ACF field, so a save that
        // skips the browser is still checked.
        $this->assertNotEmpty($this->hooksFor('acf/validate_value/key=field_66461796ab271'));
        $this->assertNotEmpty($this->hooksFor('acf/validate_value/key=field_66720958da8b5'));
        $this->assertNotEmpty($this->hooksFor('acf/input/admin_enqueue_scripts'));
    }

    // ── script enqueuing ─────────────────────────────────────────────

    /** @test */
    public function the_member_validator_script_loads_only_on_the_member_screen(): void
    {
        $this->setScreen('post', 'post', self::MEMBER_TYPE);

        $this->memberValidator->enqueueScripts();

        $this->assertNotEmpty(WpState::$enqueued);
        $this->assertArrayHasKey('amberMemberAnonymousName', WpState::$localized);
        $this->assertArrayHasKey('nonce', WpState::$localized['amberMemberAnonymousName']);
    }

    /** @test */
    public function the_member_validator_script_is_skipped_elsewhere(): void
    {
        $this->setScreen('post', 'post', 'page');

        $this->memberValidator->enqueueScripts();

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_member_validator_script_is_skipped_without_a_screen(): void
    {
        WpState::$screen = null;

        $this->memberValidator->enqueueScripts();

        $this->assertSame([], WpState::$enqueued);
    }

    /** @test */
    public function the_position_validator_script_loads_only_on_the_position_screen(): void
    {
        $this->setScreen('post', 'post', self::POSITION_TYPE);

        $this->positionValidator->enqueueScripts();

        $this->assertNotEmpty(WpState::$enqueued);
    }

    /** @test */
    public function the_position_validator_script_is_skipped_elsewhere(): void
    {
        $this->setScreen('post', 'post', 'page');

        $this->positionValidator->enqueueScripts();

        $this->assertSame([], WpState::$enqueued);
    }

    // ── AJAX: member ─────────────────────────────────────────────────

    /** @test */
    public function an_unused_anonymous_name_is_reported_valid(): void
    {
        $_POST = ['value' => 'Anonymous Alex', 'post_id' => '42'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertTrue($e->data['valid']);
        }
    }

    /** @test */
    public function a_duplicate_anonymous_name_is_reported_invalid_with_the_clashing_post(): void
    {
        $this->existingPost(99);
        $_POST = ['value' => 'Anonymous Alex', 'post_id' => '42'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->data['valid']);
            // Naming the clashing post is what makes the message actionable.
            $this->assertStringContainsString('99', $e->data['message']);
        }
    }

    /** @test */
    public function an_empty_anonymous_name_is_not_treated_as_a_clash(): void
    {
        // Emptiness is ACF's required-field problem, not a uniqueness one.
        $this->existingPost(99);
        $_POST = ['value' => '', 'post_id' => '42'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->data['valid']);
        }
    }

    /** @test */
    public function the_ajax_check_is_refused_without_edit_permission(): void
    {
        $this->denyCapability();
        $_POST = ['value' => 'Anonymous Alex'];

        try {
            $this->memberValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
        }
    }

    /** @test */
    public function the_position_ajax_check_answers_the_same_way(): void
    {
        $_POST = ['value' => 'Treasurer', 'post_id' => '7'];

        try {
            $this->positionValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertTrue($e->success);
            $this->assertTrue($e->data['valid']);
        }
    }

    /** @test */
    public function a_duplicate_position_name_is_reported_invalid(): void
    {
        $this->existingPost(88);
        $_POST = ['value' => 'Treasurer', 'post_id' => '7'];

        try {
            $this->positionValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->data['valid']);
        }
    }

    /** @test */
    public function the_position_ajax_check_is_refused_without_permission(): void
    {
        $this->denyCapability();
        $_POST = ['value' => 'Treasurer'];

        try {
            $this->positionValidator->handleAjax();
            $this->fail('Expected a JSON response.');
        } catch (JsonResponseException $e) {
            $this->assertFalse($e->success);
        }
    }

    // ── save-time validation ─────────────────────────────────────────

    /** @test */
    public function saving_a_unique_anonymous_name_passes_validation(): void
    {
        $_POST = ['_acf_post_id' => '42'];

        $this->assertTrue($this->memberValidator->validateOnSave(true, 'Anonymous Alex', [], 'acf[field]'));
    }

    /** @test */
    public function saving_a_duplicate_anonymous_name_returns_an_error_message(): void
    {
        $this->existingPost(99);
        $_POST = ['_acf_post_id' => '42'];

        $result = $this->memberValidator->validateOnSave(true, 'Anonymous Alex', [], 'acf[field]');

        $this->assertIsString($result);
        $this->assertStringContainsString('already in use', $result);
    }

    /** @test */
    public function an_existing_validation_failure_is_left_untouched(): void
    {
        // Another validator already rejected it; ours must not overwrite
        // that message with a pass.
        $this->existingPost(99);

        $this->assertSame(
            'Already invalid',
            $this->memberValidator->validateOnSave('Already invalid', 'Anonymous Alex', [], 'acf[field]')
        );
    }

    /** @test */
    public function an_empty_value_passes_save_validation(): void
    {
        $this->existingPost(99);
        $_POST = ['_acf_post_id' => '42'];

        $this->assertTrue($this->memberValidator->validateOnSave(true, '', [], 'acf[field]'));
    }

    /**
     * ACF puts the post id in _acf_post_id during server-side validation,
     * not the post_id the AJAX handler uses, so both are read with
     * WordPress's own post_ID as a final fallback. Excluding the wrong post
     * would make a record clash with itself and block every save.
     *
     * @test
     * @dataProvider postIdSourceProvider
     */
    public function the_post_being_edited_is_excluded_however_its_id_arrives(string $key): void
    {
        // The only match is the post being edited, so it must not count.
        $this->existingPost(0);
        $_POST = [$key => '42'];

        $this->assertTrue($this->memberValidator->validateOnSave(true, 'Anonymous Alex', [], 'acf[field]'));
    }

    /** @return array<string, array{0: string}> */
    public static function postIdSourceProvider(): array
    {
        return [
            'acf server-side field' => ['_acf_post_id'],
            'ajax field'            => ['post_id'],
            'wordpress field'       => ['post_ID'],
        ];
    }

    /** @test */
    public function saving_a_unique_position_name_passes_validation(): void
    {
        $_POST = ['_acf_post_id' => '7'];

        $this->assertTrue($this->positionValidator->validateOnSave(true, 'Treasurer', [], 'acf[field]'));
    }

    /** @test */
    public function saving_a_duplicate_position_name_returns_an_error_message(): void
    {
        $this->existingPost(88);
        $_POST = ['_acf_post_id' => '7'];

        $result = $this->positionValidator->validateOnSave(true, 'Treasurer', [], 'acf[field]');

        $this->assertIsString($result);
    }

    /** @test */
    public function an_empty_position_name_passes_save_validation(): void
    {
        $this->existingPost(88);
        $_POST = ['_acf_post_id' => '7'];

        $this->assertTrue($this->positionValidator->validateOnSave(true, '', [], 'acf[field]'));
    }

    /** @test */
    public function an_existing_position_validation_failure_is_left_untouched(): void
    {
        $this->existingPost(88);

        $this->assertSame(
            'Already invalid',
            $this->positionValidator->validateOnSave('Already invalid', 'Treasurer', [], 'acf[field]')
        );
    }
}
