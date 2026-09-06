<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Amber\Core\MenuRegistrar;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Scrutiny\Privacy\PersonalDataPolicy;
use Unity\Auth\Interfaces\PasswordCredentialRepository;
use Unity\Auth\PasswordCredential;
use Unity\Members\Interfaces\Member;
use Unity\Members\Interfaces\MemberRepository;

use function add_action;
use function add_query_arg;
use function add_submenu_page;
use function admin_url;
use function check_admin_referer;
use function current_user_can;
use function esc_attr;
use function esc_html;
use function esc_url;
use function get_current_user_id;
use function is_email;
use function sanitize_email;
use function sanitize_key;
use function wp_nonce_url;
use function wp_safe_redirect;

/**
 * Member Passwords
 *
 * <p>The one screen for the one store. Reach and Fellowship used to keep
 * a credentials table each; they now share Unity's, and this is where an
 * administrator can see what is in it and act on it. It lives in Amber
 * rather than in either app for the same reason the store lives in Unity:
 * putting it in one of them would mean the other's members were managed
 * from a plugin that has nothing to do with them.</p>
 *
 * <p><b>Three actions, and none of them sets a password.</b> An
 * administrator can clear one, lift a lockout, or remove the credential
 * altogether. What they cannot do from here is choose somebody's
 * password, because an administrator who knows a member's password can
 * sign in as that member, and the whole point of a store that holds only
 * hashes is that nobody is in that position. A member who needs a
 * password sets one through the emailed link, and the app that owns that
 * email template — Reach or Fellowship — is where the "send them one"
 * button belongs.</p>
 *
 * <p><b>Clearing is not deleting, and both are offered.</b> Clearing
 * leaves the row and empties the hash: the member can no longer sign in
 * with a password and can ask for a new one, which is what somebody wants
 * when a password may have been seen. Deleting removes the row, which is
 * what GDPR erasure wants; Reach and Fellowship already do it
 * automatically when a member is deleted, and this is the manual door
 * onto the same thing.</p>
 *
 * <p>Guarded by Scrutiny's edit capability, not by Amber's menu one. The
 * list is member email addresses beside their sign-in state, which is
 * personal data by any reading, and every action here changes whether
 * somebody can get in.</p>
 */
class MemberPasswordAdmin
{
    public const PAGE_SLUG = 'amber-member-passwords';

    private const NONCE_ACTION = 'amber_member_password';

    /**
     * How many rows the screen will show.
     *
     * Most members never have a credential — OAuth is the default sign-in
     * path in both apps — so this is a guard against a surprise, not
     * pagination. If a site ever reaches it, the answer is a filter on
     * this screen rather than a longer list.
     */
    private const MAX_ROWS = 500;

    public function __construct(
        private readonly PasswordCredentialRepository $credentials,
        private readonly MemberRepository $members,
        private readonly PersonalDataPolicy $policy,
        private readonly AuditLogger $auditLogger,
    ) {
        add_action('admin_menu', [$this, 'registerPage'], 20);
        add_action('admin_init', [$this, 'maybeHandleAction']);
    }

    /**
     * Add the screen under the Intergroup menu.
     *
     * Registered whether or not the current user may open it: WordPress
     * checks the capability itself before rendering the item, and passing
     * the real one here is what keeps the menu honest for a user who
     * cannot.
     */
    public function registerPage(): void
    {
        add_submenu_page(
            MenuRegistrar::MENU_SLUG,
            'Member Passwords',
            'Member Passwords',
            PersonalDataPolicy::EDIT_CAPABILITY,
            self::PAGE_SLUG,
            [$this, 'render'],
        );
    }

    /**
     * Act on a clear, unlock or remove, then redirect.
     *
     * <p>Post-redirect-get, so a refresh does not repeat the action. The
     * capability is re-checked here rather than trusted from the menu:
     * this runs on admin_init for any request carrying the parameters,
     * and the menu is not what stopped it arriving.</p>
     */
    public function maybeHandleAction(): void
    {
        $notice = $this->handleActionFromRequest();

        if ($notice === '') {
            return;
        }

        // nosemgrep: php.symfony.security.audit.symfony-non-literal-redirect.symfony-non-literal-redirect
        wp_safe_redirect(add_query_arg(
            ['page' => self::PAGE_SLUG, 'amber_password_notice' => $notice],
            admin_url('admin.php'),
        ));
        exit;
    }

    /**
     * The decision, split from the redirect so it can be driven directly.
     *
     * <p>The handler above ends in wp_safe_redirect() and exit, which a
     * test cannot follow. Splitting the two is the suite's existing
     * answer to that — see Fellowship's
     * DevicesPage::sendResetCodeFromRequest() — and it is
     * behaviour-identical.</p>
     *
     * @return string The notice key, or an empty string for a request
     *                this screen has nothing to do with.
     */
    public function handleActionFromRequest(): string
    {
        $action = isset($_GET['amber_password_action'])
            ? sanitize_key((string) $_GET['amber_password_action'])
            : '';
        $rawEmail = isset($_GET['email']) ? (string) $_GET['email'] : '';
        $email    = strtolower(trim(sanitize_email($rawEmail)));

        if ($action === '' || $email === '' || !is_email($email)) {
            return '';
        }

        if (!current_user_can(PersonalDataPolicy::EDIT_CAPABILITY)) {
            return '';
        }

        check_admin_referer(self::NONCE_ACTION . ':' . $email);

        $now = time();

        // Every branch is audited, including the one that may change
        // nothing: "an administrator looked at this member's credential
        // and pressed unlock" is worth a row whether or not the member
        // was locked at the time.
        switch ($action) {
            case 'clear':
                // An empty hash rather than a deleted row. hasPassword()
                // reads false, password_verify() against '' fails for
                // every candidate, and the member keeps their place in
                // the store so a reset still finds them.
                $this->credentials->upsertPasswordHash($email, '', $now);
                $this->audit($email, 'Member password cleared');
                return 'cleared';

            case 'unlock':
                $this->credentials->resetFailedAttempts($email, $now);
                $this->audit($email, 'Member password lockout lifted');
                return 'unlocked';

            case 'remove':
                $this->credentials->delete($email);
                $this->audit($email, 'Member password credential removed');
                return 'removed';

            default:
                return '';
        }
    }

    /**
     * Record what an administrator did, against the member it was done to.
     *
     * <p>Never carries the address itself. The audit log is read by people
     * reviewing access to personal data, and an entry that reproduces the
     * data being protected is working against itself — the member id is
     * what makes the row traceable. Member id 0 when the address matches
     * no member, which is a real state: a credential outlives the member
     * record if one is deleted outside the hooks that clean up.</p>
     */
    private function audit(string $email, string $what): void
    {
        $member = $this->members->findByEmail($email);

        $this->auditLogger->log(
            AuditLogger::ACTION_UPDATE,
            AuditLogger::ENTITY_MEMBER,
            $member instanceof Member ? $member->getId() : 0,
            'authentication',
            $what . ';by:admin:' . get_current_user_id(),
        );
    }

    /**
     * The list.
     */
    public function render(): void
    {
        if (!$this->policy->currentUserCanEdit()) {
            echo '<div class="wrap"><h1>Member Passwords</h1><p>'
                . esc_html('You do not have permission to manage member passwords.')
                . '</p></div>';
            return;
        }

        $credentials = $this->credentials->all(self::MAX_ROWS);
        $now         = time();

        echo '<div class="wrap">';
        echo '<h1>Member Passwords</h1>';

        $this->renderNotice();

        echo '<p class="description">'
            . esc_html(
                'One store, shared by Reach and the Link app. Most members never set a password '
                . 'and do not appear here at all — signing in with Google, Microsoft, Apple or '
                . 'Facebook leaves no row. Passwords cannot be set from this screen; a member who '
                . 'needs one asks for a link from the app they are signing into.'
            )
            . '</p>';

        if ($credentials === []) {
            echo '<p>' . esc_html('No member has set a password.') . '</p></div>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>'
            . '<th>' . esc_html('Member') . '</th>'
            . '<th>' . esc_html('Email') . '</th>'
            . '<th>' . esc_html('Password') . '</th>'
            . '<th>' . esc_html('Locked') . '</th>'
            . '<th>' . esc_html('Reset pending') . '</th>'
            . '<th>' . esc_html('Last change') . '</th>'
            . '<th>' . esc_html('Actions') . '</th>'
            . '</tr></thead><tbody>';

        foreach ($credentials as $credential) {
            $this->renderRow($credential, $now);
        }

        echo '</tbody></table></div>';
    }

    private function renderRow(PasswordCredential $credential, int $now): void
    {
        $member = $this->members->findByEmail($credential->email);

        // A credential whose member is gone still shows, and says so. It
        // is the one row on this screen somebody actually needs to act
        // on: a live sign-in credential for somebody no longer in the
        // intergroup.
        $name = $member instanceof Member
            ? $member->getAnonymousName()
            : '(no member record)';

        echo '<tr>';
        echo '<td>' . esc_html($name) . '</td>';
        echo '<td>' . esc_html($credential->email) . '</td>';
        echo '<td>' . esc_html($credential->hasPassword() ? 'Set' : 'Not set') . '</td>';
        echo '<td>' . esc_html($credential->isLocked($now) ? 'Yes' : 'No') . '</td>';
        echo '<td>' . esc_html($credential->hasValidResetToken($now) ? 'Yes' : 'No') . '</td>';
        echo '<td>' . esc_html($this->when($credential->updatedAt)) . '</td>';
        echo '<td>';

        if ($credential->hasPassword()) {
            $this->renderAction($credential->email, 'clear', 'Clear password');
        }

        if ($credential->isLocked($now)) {
            $this->renderAction($credential->email, 'unlock', 'Unlock');
        }

        $this->renderAction($credential->email, 'remove', 'Remove');

        echo '</td></tr>';
    }

    private function renderAction(string $email, string $action, string $label): void
    {
        $url = wp_nonce_url(
            add_query_arg(
                [
                    'page'                   => self::PAGE_SLUG,
                    'amber_password_action'  => $action,
                    'email'                  => $email,
                ],
                admin_url('admin.php'),
            ),
            self::NONCE_ACTION . ':' . $email,
        );

        echo '<a class="button button-small" style="margin-right:4px" href="'
            . esc_url($url) . '" data-email="' . esc_attr($email) . '">'
            . esc_html($label) . '</a>';
    }

    /**
     * A timestamp in the site's own timezone, or a dash.
     *
     * Zero is a real value here — a row absorbed from one of the old
     * tables can carry it — and rendering it as 1970 would make a
     * migration look like a fault.
     */
    private function when(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return (string) wp_date('Y-m-d H:i', $timestamp);
    }

    private function renderNotice(): void
    {
        $notice = isset($_GET['amber_password_notice'])
            ? (string) $_GET['amber_password_notice']
            : '';

        $messages = [
            'cleared'  => 'The password was cleared. The member can ask for a new one from the app.',
            'unlocked' => 'The lockout was lifted.',
            'removed'  => 'The credential was removed.',
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html($messages[$notice]) . '</p></div>';
    }
}
