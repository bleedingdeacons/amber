<?php

declare(strict_types=1);

namespace Amber\Tests\Unit\Admin\Members;

use Amber\Admin\Members\MemberPasswordAdmin;
use Amber\Tests\AmberTestCase;
use Brain\Monkey\Functions;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Auth\PasswordCredential;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Testing\Doubles\InMemoryPasswordCredentialRepository;

/**
 * The member password screen.
 *
 * <p>Everything worth asserting here is a refusal or a side effect. The
 * screen is the only place in the suite that can take somebody's ability
 * to sign in away, and it is reachable by URL — so what matters is that
 * it will not act without the capability, that clearing does not delete,
 * and that no path anywhere sets a password.</p>
 *
 * @covers \Amber\Admin\Members\MemberPasswordAdmin
 */
class MemberPasswordAdminTest extends AmberTestCase
{
    private const MEMBER = 'member@example.org';

    private InMemoryPasswordCredentialRepository $credentials;

    private FakeAuditLogger $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentials = new InMemoryPasswordCredentialRepository([
            new PasswordCredential(self::MEMBER, 'hashed', '', 0, 4, 9_999_999_999, 1_788_000_000),
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
    }

    protected function tearDown(): void
    {
        $_GET = [];

        parent::tearDown();
    }

    private function admin(bool $canEdit = true): MemberPasswordAdmin
    {
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
    }

    /**
     * The one that matters most. This runs on admin_init for any request
     * carrying the parameters, so the menu is not what stopped it
     * arriving — the check here is the only thing between a URL and
     * somebody's sign-in.
     */
    public function testAnActionWithoutTheCapabilityDoesNothing(): void
    {
        $admin = $this->admin(canEdit: false);

        $_GET = ['amber_password_action' => 'clear', 'email' => self::MEMBER];
        $admin->handleActionFromRequest();

        self::assertTrue($this->credentials->rows[self::MEMBER]->hasPassword());
        self::assertSame([], $this->audit->entries);
    }

    /**
     * Clearing leaves the row and empties the hash. Deleting it would
     * mean a member who has just had a password taken away cannot be
     * found by the reset flow that gives them a new one.
     */
    public function testClearingEmptiesTheHashWithoutRemovingTheRow(): void
    {
        $admin = $this->admin();

        $_GET = ['amber_password_action' => 'clear', 'email' => self::MEMBER];
        $admin->handleActionFromRequest();

        self::assertArrayHasKey(self::MEMBER, $this->credentials->rows);
        self::assertFalse($this->credentials->rows[self::MEMBER]->hasPassword());
        self::assertSame([], $this->credentials->deleted);
    }

    /**
     * Clearing goes through the same upsert the apps use, which also
     * lifts the lockout. A member whose password was cleared while locked
     * out must not stay locked out of a password they no longer have.
     */
    public function testClearingAlsoLiftsTheLockout(): void
    {
        $admin = $this->admin();

        $_GET = ['amber_password_action' => 'clear', 'email' => self::MEMBER];
        $admin->handleActionFromRequest();

        self::assertFalse($this->credentials->rows[self::MEMBER]->isLocked(time()));
    }

    public function testUnlockingClearsTheCountersAndKeepsThePassword(): void
    {
        $admin = $this->admin();

        $_GET = ['amber_password_action' => 'unlock', 'email' => self::MEMBER];
        $admin->handleActionFromRequest();

        $row = $this->credentials->rows[self::MEMBER];

        self::assertFalse($row->isLocked(time()));
        self::assertSame(0, $row->failedAttempts);
        self::assertTrue($row->hasPassword());
    }

    public function testRemovingDeletesTheCredential(): void
    {
        $admin = $this->admin();

        $_GET = ['amber_password_action' => 'remove', 'email' => self::MEMBER];
        $admin->handleActionFromRequest();

        self::assertArrayNotHasKey(self::MEMBER, $this->credentials->rows);
        self::assertSame([self::MEMBER], $this->credentials->deleted);
    }

    /**
     * Every action is audited against the member, and the entry never
     * carries the address — the audit log is read by people reviewing
     * access to personal data, and an entry reproducing the data being
     * protected is working against itself.
     */
    public function testEveryActionIsAuditedWithoutTheAddress(): void
    {
        foreach (['clear', 'unlock', 'remove'] as $action) {
            $this->audit->entries = [];
            $admin = $this->admin();

            $_GET = ['amber_password_action' => $action, 'email' => self::MEMBER];
            $admin->handleActionFromRequest();

            self::assertCount(1, $this->audit->entries, $action . ' was not audited.');
            self::assertSame(7, $this->audit->entries[0]['entityId']);
            self::assertStringNotContainsString(self::MEMBER, $this->audit->entries[0]['detail']);
            self::assertStringContainsString('by:admin:3', $this->audit->entries[0]['detail']);
        }
    }

    public function testAnUnknownActionIsIgnored(): void
    {
        $admin = $this->admin();

        $_GET = ['amber_password_action' => 'set', 'email' => self::MEMBER];
        $admin->handleActionFromRequest();

        self::assertTrue($this->credentials->rows[self::MEMBER]->hasPassword());
        self::assertSame([], $this->audit->entries);
    }

    public function testARequestCarryingNoActionIsIgnored(): void
    {
        $admin = $this->admin();

        $_GET = [];
        $admin->handleActionFromRequest();

        self::assertSame([], $this->audit->entries);
    }

    public function testAnActionWithNoEmailIsIgnored(): void
    {
        $admin = $this->admin();

        $_GET = ['amber_password_action' => 'clear'];
        $admin->handleActionFromRequest();

        self::assertTrue($this->credentials->rows[self::MEMBER]->hasPassword());
    }

    public function testTheScreenRefusesToRenderWithoutTheCapability(): void
    {
        $admin = $this->admin(canEdit: false);

        ob_start();
        $admin->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('do not have permission', $html);
        self::assertStringNotContainsString(self::MEMBER, $html);
    }

    public function testTheScreenListsTheStore(): void
    {
        $admin = $this->admin();

        ob_start();
        $admin->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('Dave P', $html);
        self::assertStringContainsString(self::MEMBER, $html);
        self::assertStringContainsString('Clear password', $html);
        self::assertStringContainsString('Unlock', $html);
        self::assertStringContainsString('Remove', $html);
    }

    /**
     * No path here sets a password. An administrator who could choose one
     * could sign in as that member, which is the thing a hash-only store
     * exists to prevent.
     */
    public function testTheScreenOffersNoWayToSetAPassword(): void
    {
        $admin = $this->admin();

        ob_start();
        $admin->render();
        $html = (string) ob_get_clean();

        self::assertStringNotContainsString('type="password"', $html);
        self::assertStringNotContainsString('Set password', $html);
    }

    public function testAnEmptyStoreSaysSo(): void
    {
        $this->credentials = new InMemoryPasswordCredentialRepository();
        $admin = $this->admin();

        ob_start();
        $admin->render();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('No member has set a password', $html);
    }
}

/**
 * Records what it was told, so the tests can read it back.
 */
final class FakeAuditLogger implements \Scrutiny\Audit\Interfaces\AuditLogger
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
