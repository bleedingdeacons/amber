<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin;

use Amber\Admin\Members\PersonalEmailValidator;
use Brain\Monkey\Filters;
use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

/*
 * Tests for the personal-email domain guard.
 *
 * A member's Personal Email is how the rota and the finder reach that
 * member. An @aa-bristol.org address reaches whoever currently holds a
 * position instead, so it is refused on save however it is dressed up —
 * subdomain, mixed case, or padding around the address.
 */

covers(PersonalEmailValidator::class);

const PERSONAL_EMAIL_KEY = 'field_67d0eabc277cb';

beforeEach(function () {
    /** A Configuration whose member config carries the given email field key. */
    $this->configuration = function (string $emailKey): Configuration {
        $config = $this->createMock(Configuration::class);
        $config->method('getConfig')->willReturnCallback(
            static fn (string $key): array => $key === Member::class
                ? ['POST_TYPE' => 'intergroup-member', 'KEY_PERSONAL_EMAIL' => $emailKey]
                : []
        );

        return $config;
    };

    $this->validator = new PersonalEmailValidator(($this->configuration)(PERSONAL_EMAIL_KEY));
});

// ── registration ─────────────────────────────────────────────────
it('validates the personal email field on save', function () {
    $this->assertHookAdded('acf/validate_value/key=' . PERSONAL_EMAIL_KEY);
});

// Without a key there is no field to hook, and 'acf/validate_value/key='
// would validate every field ACF has.
it('registers nothing when the member config names no email field', function () {
    new PersonalEmailValidator(($this->configuration)(''));

    expect(Filters\has('acf/validate_value/key='))->toBeFalse();
});

// ── rejection ────────────────────────────────────────────────────
it('refuses an intergroup address', function (string $email) {
    $result = $this->validator->validateOnSave(true, $email, [], 'acf[field]');

    expect($result)->toBeString()
        ->toContain('aa-bristol.org');
})->with([
    'role alias'      => ['secretary@aa-bristol.org'],
    'mixed case'      => ['Secretary@AA-Bristol.ORG'],
    'subdomain'       => ['chair@mail.aa-bristol.org'],
    'trailing typo'   => ['chair@aa-bristol.org.uk'],
    'plus addressing' => ['chair+rota@aa-bristol.org'],
    'padded'          => ['  secretary@aa-bristol.org  '],
]);

// ── acceptance ───────────────────────────────────────────────────
it('accepts a genuine personal address', function () {
    expect($this->validator->validateOnSave(true, 'alex@example.com', [], 'acf[field]'))->toBeTrue();
});

// An address at another AA domain is somebody's real mailbox — only the
// intergroup's own domain forwards by role.
it('accepts an address at a different aa domain', function () {
    expect($this->validator->validateOnSave(true, 'alex@aa-bristol.example', [], 'acf[field]'))->toBeTrue();
});

it('passes an empty value', function () {
    // Emptiness is ACF's required-field problem, and it is also what a
    // user without the view capability submits for an untouched field.
    expect($this->validator->validateOnSave(true, '', [], 'acf[field]'))->toBeTrue();
});

it('passes the clear sentinel', function () {
    // Scrutiny's Clear button submits this in place of the address; it
    // must reach the update_value filter that turns it into an empty
    // field rather than being stopped here.
    expect($this->validator->validateOnSave(true, '__CLEAR__', [], 'acf[field]'))->toBeTrue();
});

it('passes a non string value', function () {
    expect($this->validator->validateOnSave(true, null, [], 'acf[field]'))->toBeTrue();
});

it('leaves an existing validation failure untouched', function () {
    // Another validator already rejected it; ours must not overwrite that
    // message, nor replace it with a pass.
    expect($this->validator->validateOnSave('Already invalid', 'secretary@aa-bristol.org', [], 'acf[field]'))
        ->toBe('Already invalid');
});

// ── configuration ────────────────────────────────────────────────
it('lets the blocked domains be filtered', function () {
    Filters\expectApplied('amber_intergroup_email_domains')
        ->andReturn(['aa-somewhere-else.org']);

    $result = $this->validator->validateOnSave(true, 'secretary@aa-somewhere-else.org', [], 'acf[field]');

    expect($result)->toBeString()
        ->toContain('aa-somewhere-else.org');
});

// A filter returning something unusable falls back to the shipped domain
// rather than quietly blocking nothing.
it('falls back to the intergroup domain when the filter is broken', function () {
    Filters\expectApplied('amber_intergroup_email_domains')->andReturn('not-an-array');

    expect($this->validator->validateOnSave(true, 'secretary@aa-bristol.org', [], 'acf[field]'))->toBeString();
});
