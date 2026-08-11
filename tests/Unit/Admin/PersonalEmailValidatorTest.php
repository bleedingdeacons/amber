<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin;

use Amber\Admin\Members\PersonalEmailValidator;
use Amber\Tests\AmberTestCase;
use Brain\Monkey\Filters;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/**
 * Tests for the personal-email domain guard.
 *
 * A member's Personal Email is how the rota and the finder reach that
 * member. An @aa-bristol.org address reaches whoever currently holds a
 * position instead, so it is refused on save however it is dressed up —
 * subdomain, mixed case, or padding around the address.
 *
 * @covers \Amber\Admin\Members\PersonalEmailValidator
 */
class PersonalEmailValidatorTest extends AmberTestCase
{
    private const EMAIL_KEY = 'field_67d0eabc277cb';

    private PersonalEmailValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new PersonalEmailValidator($this->configuration(self::EMAIL_KEY));
    }

    /** A Configuration whose member config carries the given email field key. */
    private function configuration(string $emailKey): Configuration
    {
        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(
            static fn (string $key): array => $key === Member::class
                ? ['POST_TYPE' => 'intergroup-member', 'KEY_PERSONAL_EMAIL' => $emailKey]
                : []
        );

        return $config;
    }

    // ── registration ─────────────────────────────────────────────────

    /** @test */
    public function it_validates_the_personal_email_field_on_save(): void
    {
        $this->assertHookAdded('acf/validate_value/key=' . self::EMAIL_KEY);
    }

    /**
     * Without a key there is no field to hook, and 'acf/validate_value/key='
     * would validate every field ACF has.
     *
     * @test
     */
    public function it_registers_nothing_when_the_member_config_names_no_email_field(): void
    {
        new PersonalEmailValidator($this->configuration(''));

        self::assertFalse(Filters\has('acf/validate_value/key='));
    }

    // ── rejection ────────────────────────────────────────────────────

    /**
     * @test
     * @dataProvider intergroupAddressProvider
     */
    public function an_intergroup_address_is_refused(string $email): void
    {
        $result = $this->validator->validateOnSave(true, $email, [], 'acf[field]');

        $this->assertIsString($result);
        $this->assertStringContainsString('aa-bristol.org', $result);
    }

    /** @return array<string, array{0: string}> */
    public static function intergroupAddressProvider(): array
    {
        return [
            'role alias'      => ['secretary@aa-bristol.org'],
            'mixed case'      => ['Secretary@AA-Bristol.ORG'],
            'subdomain'       => ['chair@mail.aa-bristol.org'],
            'trailing typo'   => ['chair@aa-bristol.org.uk'],
            'plus addressing' => ['chair+rota@aa-bristol.org'],
            'padded'          => ['  secretary@aa-bristol.org  '],
        ];
    }

    // ── acceptance ───────────────────────────────────────────────────

    /** @test */
    public function a_genuine_personal_address_is_accepted(): void
    {
        $this->assertTrue(
            $this->validator->validateOnSave(true, 'alex@example.com', [], 'acf[field]')
        );
    }

    /**
     * An address at another AA domain is somebody's real mailbox — only the
     * intergroup's own domain forwards by role.
     *
     * @test
     */
    public function an_address_at_a_different_aa_domain_is_accepted(): void
    {
        $this->assertTrue(
            $this->validator->validateOnSave(true, 'alex@aa-bristol.example', [], 'acf[field]')
        );
    }

    /** @test */
    public function an_empty_value_passes(): void
    {
        // Emptiness is ACF's required-field problem, and it is also what a
        // user without the view capability submits for an untouched field.
        $this->assertTrue($this->validator->validateOnSave(true, '', [], 'acf[field]'));
    }

    /** @test */
    public function the_clear_sentinel_passes(): void
    {
        // Scrutiny's Clear button submits this in place of the address; it
        // must reach the update_value filter that turns it into an empty
        // field rather than being stopped here.
        $this->assertTrue($this->validator->validateOnSave(true, '__CLEAR__', [], 'acf[field]'));
    }

    /** @test */
    public function a_non_string_value_passes(): void
    {
        $this->assertTrue($this->validator->validateOnSave(true, null, [], 'acf[field]'));
    }

    /** @test */
    public function an_existing_validation_failure_is_left_untouched(): void
    {
        // Another validator already rejected it; ours must not overwrite that
        // message, nor replace it with a pass.
        $this->assertSame(
            'Already invalid',
            $this->validator->validateOnSave('Already invalid', 'secretary@aa-bristol.org', [], 'acf[field]')
        );
    }

    // ── configuration ────────────────────────────────────────────────

    /** @test */
    public function the_blocked_domains_can_be_filtered(): void
    {
        Filters\expectApplied('amber_intergroup_email_domains')
            ->andReturn(['aa-somewhere-else.org']);

        $result = $this->validator->validateOnSave(true, 'secretary@aa-somewhere-else.org', [], 'acf[field]');

        $this->assertIsString($result);
        $this->assertStringContainsString('aa-somewhere-else.org', $result);
    }

    /**
     * A filter returning something unusable falls back to the shipped domain
     * rather than quietly blocking nothing.
     *
     * @test
     */
    public function a_broken_filter_falls_back_to_the_intergroup_domain(): void
    {
        Filters\expectApplied('amber_intergroup_email_domains')->andReturn('not-an-array');

        $this->assertIsString(
            $this->validator->validateOnSave(true, 'secretary@aa-bristol.org', [], 'acf[field]')
        );
    }
}
