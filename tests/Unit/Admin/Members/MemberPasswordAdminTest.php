<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Scrutiny\Audit\Interfaces\AuditLogger;
use Amber\Admin\Members\MemberPasswordAdmin;
use Brain\Monkey\Functions;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Auth\PasswordCredential;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;

/*
 * The member password screen.
 *
 * <p>Everything worth asserting here is a refusal or a side effect. The
 * screen is the only place in the suite that can take somebody's ability
 * to sign in away, and it is reachable by URL — so what matters is that
 * it will not act without the capability, that clearing does not delete,
 * and that no path anywhere sets a password.</p>
 */

covers(MemberPasswordAdmin::class);

const PASSWORD_MEMBER = 'member@example.org';

/**
 * Records what it was told, so the tests can read it back.
 */
final class FakeAuditLogger implements AuditLogger
{
    /** @var array<int, array{action: string, entityId: int, field: string, detail: string}> */
    public array $entries = [];

    public function log(
        string $action,
        string $entityType,
        int $entityId,
        string $fieldName,
        string $detail = ''
    ): void {
        $this->entries[] = [
            'action'   => $action,
            'entityId' => $entityId,
            'field'    => $fieldName,
            'detail'   => $detail,
        ];
    }

    /**
     * @param array<string> $fieldNames
     */
    public function logBatch(
        string $action,
        string $entityType,
        int $entityId,
        array $fieldNames,
        string $detail = ''
    ): void {
        $this->log($action, $entityType, $entityId, implode(',', $fieldNames), $detail);
    }
}

beforeEach(function () {
    $this->credentials = new InMemoryPasswordCredentialRepository([
        new PasswordCredential(PASSWORD_MEMBER, 'hashed', '', 0, 4, 9_999_999_999, 1_788_000_000),
    ]);

    $this->audit = new FakeAuditLogger();

    $_GET = [];

    Functions\when('check_admin_referer')->justReturn(true);
    Functions\when('wp_nonce_url')->returnArg();
    Functions\when('admin_url')->justReturn('https://example.org/wp-admin/admin.php');
    Functions\when('add_query_arg')->justReturn('https://example.org/wp-admin/admin.php');
    Functions\when('wp_date')->justReturn('2026-09-06 10:00');
    Functions\when('get_current_user_id')->justReturn(3);
    Functions\when('sanitize_key')->returnArg();
    Functions\when('sanitize_email')->returnArg();
    // WordPress's is_email answers the address or false, never a
    // bool true - and the stub layer keeps the real signature, so
    // returning true here is a TypeError rather than a passing test.
    Functions\when('is_email')->returnArg();

    $this->admin = function (bool $canEdit = true): MemberPasswordAdmin {
        Functions\when('current_user_can')->justReturn($canEdit);

        $member = $this->createMock(Member::class);
        $member->method('getId')->willReturn(7);
        $member->method('getAnonymousName')->willReturn('Dave P');

        $members = $this->createMock(MemberRepository::class);
        $members->method('findByEmail')->willReturn($member);

        // The real policy, which is final and asks current_user_can() —
        // stubbed above. Doubling it is not an option and would not be
        // the better test anyway: what this screen depends on is the
        // capability, and this way the capability is what is being set.
        return new MemberPasswordAdmin($this->credentials, $members, new PersonalDataPolicy(), $this->audit);
    };
});

afterEach(function () {
    $_GET = [];
});

// The one that matters most. This runs on admin_init for any request
// carrying the parameters, so the menu is not what stopped it
// arriving — the check here is the only thing between a URL and
// somebody's sign-in.
it('does nothing with an action without the capability', function () {
    $admin = ($this->admin)(canEdit: false);

    $_GET = ['amber_password_action' => 'clear', 'email' => PASSWORD_MEMBER];
    $admin->handleActionFromRequest();

    expect($this->credentials->rows[PASSWORD_MEMBER]->hasPassword())->toBeTrue()
        ->and($this->audit->entries)->toBe([]);
});

// Clearing leaves the row and empties the hash. Deleting it would
// mean a member who has just had a password taken away cannot be
// found by the reset flow that gives them a new one.
it('empties the hash without removing the row when clearing', function () {
    $admin = ($this->admin)();

    $_GET = ['amber_password_action' => 'clear', 'email' => PASSWORD_MEMBER];
    $admin->handleActionFromRequest();

    expect($this->credentials->rows)->toHaveKey(PASSWORD_MEMBER)
        ->and($this->credentials->rows[PASSWORD_MEMBER]->hasPassword())->toBeFalse()
        ->and($this->credentials->deleted)->toBe([]);
});

// Clearing goes through the same upsert the apps use, which also
// lifts the lockout. A member whose password was cleared while locked
// out must not stay locked out of a password they no longer have.
it('also lifts the lockout when clearing', function () {
    $admin = ($this->admin)();

    $_GET = ['amber_password_action' => 'clear', 'email' => PASSWORD_MEMBER];
    $admin->handleActionFromRequest();

    expect($this->credentials->rows[PASSWORD_MEMBER]->isLocked(time()))->toBeFalse();
});

it('clears the counters and keeps the password when unlocking', function () {
    $admin = ($this->admin)();

    $_GET = ['amber_password_action' => 'unlock', 'email' => PASSWORD_MEMBER];
    $admin->handleActionFromRequest();

    $row = $this->credentials->rows[PASSWORD_MEMBER];

    expect($row->isLocked(time()))->toBeFalse()
        ->and($row->failedAttempts)->toBe(0)
        ->and($row->hasPassword())->toBeTrue();
});

it('deletes the credential when removing', function () {
    $admin = ($this->admin)();

    $_GET = ['amber_password_action' => 'remove', 'email' => PASSWORD_MEMBER];
    $admin->handleActionFromRequest();

    expect($this->credentials->rows)->not->toHaveKey(PASSWORD_MEMBER)
        ->and($this->credentials->deleted)->toBe([PASSWORD_MEMBER]);
});

// Every action is audited against the member, and the entry never
// carries the address — the audit log is read by people reviewing
// access to personal data, and an entry reproducing the data being
// protected is working against itself.
it('audits every action without the address', function () {
    foreach (['clear', 'unlock', 'remove'] as $action) {
        $this->audit->entries = [];
        $admin = ($this->admin)();

        $_GET = ['amber_password_action' => $action, 'email' => PASSWORD_MEMBER];
        $admin->handleActionFromRequest();

        expect($this->audit->entries)->toHaveCount(1, $action . ' was not audited.')
            ->and($this->audit->entries[0]['entityId'])->toBe(7)
            ->and($this->audit->entries[0]['detail'])->not->toContain(PASSWORD_MEMBER)
            ->and($this->audit->entries[0]['detail'])->toContain('by:admin:3');
    }
});

it('ignores an unknown action', function () {
    $admin = ($this->admin)();

    $_GET = ['amber_password_action' => 'set', 'email' => PASSWORD_MEMBER];
    $admin->handleActionFromRequest();

    expect($this->credentials->rows[PASSWORD_MEMBER]->hasPassword())->toBeTrue()
        ->and($this->audit->entries)->toBe([]);
});

it('ignores a request carrying no action', function () {
    $admin = ($this->admin)();

    $_GET = [];
    $admin->handleActionFromRequest();

    expect($this->audit->entries)->toBe([]);
});

it('ignores an action with no email', function () {
    $admin = ($this->admin)();

    $_GET = ['amber_password_action' => 'clear'];
    $admin->handleActionFromRequest();

    expect($this->credentials->rows[PASSWORD_MEMBER]->hasPassword())->toBeTrue();
});

it('refuses to render the screen without the capability', function () {
    $admin = ($this->admin)(canEdit: false);

    $html = $this->capture(fn () => $admin->render());

    expect($html)->toContain('do not have permission')
        ->not->toContain(PASSWORD_MEMBER);
});

it('lists the store on the screen', function () {
    $admin = ($this->admin)();

    $html = $this->capture(fn () => $admin->render());

    expect($html)->toContain('Dave P')
        ->toContain(PASSWORD_MEMBER)
        ->toContain('Clear password')
        ->toContain('Unlock')
        ->toContain('Remove');
});

// No path here sets a password. An administrator who could choose one
// could sign in as that member, which is the thing a hash-only store
// exists to prevent.
it('offers no way to set a password on the screen', function () {
    $admin = ($this->admin)();

    $html = $this->capture(fn () => $admin->render());

    expect($html)->not->toContain('type="password"')
        ->not->toContain('Set password');
});

it('says so for an empty store', function () {
    $this->credentials = new InMemoryPasswordCredentialRepository();
    $admin = ($this->admin)();

    $html = $this->capture(fn () => $admin->render());

    expect($html)->toContain('No member has set a password');
});
