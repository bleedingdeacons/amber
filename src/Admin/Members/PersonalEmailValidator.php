<?php

declare(strict_types=1);

namespace Amber\Admin\Members;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

use Unity\Core\Interfaces\Configuration;
use Unity\Members\Interfaces\Member;

use function add_filter;
use function apply_filters;
use function esc_html;
use function sanitize_text_field;

/**
 * Keeps intergroup addresses out of a member's Personal Email field.
 *
 * Personal Email is meant to be the member's own address — it is what the
 * rota, the 12th-step finder and general correspondence reach them on. An
 * `@aa-bristol.org` address is the opposite of that: it is a role alias
 * that forwards to whoever currently holds the position, so storing one
 * here mails the intergroup rather than the member, and stops reaching
 * them at all the moment they rotate out. Worse, a member holding the
 * position their own record forwards to turns any automated mail into a
 * loop back to itself.
 *
 * Enforced server-side on the ACF field so a save that skips the browser
 * is checked too. ACF validates before submitting, so the message appears
 * against the field without losing the rest of the edit.
 */
class PersonalEmailValidator
{
    /**
     * The intergroup's own mail domain. Every address under it is a service
     * alias rather than a person.
     */
    public const INTERGROUP_DOMAIN = 'aa-bristol.org';

    /** ACF field key of the member's Personal Email field. */
    private readonly string $emailFieldKey;

    public function __construct(Configuration $configuration)
    {
        $memberConfig = $configuration->getConfig(Member::class) ?? [];

        $this->emailFieldKey = (string) ($memberConfig['KEY_PERSONAL_EMAIL'] ?? '');

        // Key-based rather than name-based: personal-email is a sub-field of
        // an ACF group, and ACF resolves sub-field names inconsistently during
        // a group save (see Scrutiny's MemberFieldsObscurer for the same
        // reasoning). The key variant fires exactly once with the right value.
        if ($this->emailFieldKey !== '') {
            add_filter(
                'acf/validate_value/key=' . $this->emailFieldKey,
                [$this, 'validateOnSave'],
                10,
                4
            );
        }
    }

    /**
     * ACF server-side validation — refuses an intergroup address.
     *
     * @param bool|string          $valid Current validity.
     * @param mixed                $value Submitted field value.
     * @param array<string, mixed> $field ACF field array.
     * @param string               $input Input name attribute.
     *
     * @return bool|string True to pass, or the message to show against the field.
     */
    public function validateOnSave($valid, $value, $field, $input)
    {
        if ($valid !== true) {
            return $valid;
        }

        if (!is_string($value)) {
            return $valid;
        }

        $email = strtolower(sanitize_text_field($value));

        // Emptiness is ACF's required-field problem, not ours. It also covers
        // the two cases Scrutiny produces: a user without the view capability
        // leaving the obscured field untouched, and the Clear button, whose
        // sentinel carries no domain either way.
        if ($email === '') {
            return $valid;
        }

        foreach ($this->blockedDomains() as $domain) {
            // Substring rather than an exact domain match: the address is
            // rejected wherever the domain appears, which catches subdomains
            // (chair@mail.aa-bristol.org), plus-addressing and the trailing
            // typos (…@aa-bristol.org.uk) an exact match would let through.
            // No genuine personal address contains the intergroup's domain.
            if (str_contains($email, $domain)) {
                return sprintf(
                    'A personal email cannot be an %1$s address — those forward to whoever '
                    . 'currently holds the position, not to this member. Enter the member\'s own '
                    . 'email address, or leave the field empty.',
                    esc_html($domain)
                );
            }
        }

        return $valid;
    }

    /**
     * Domains that may not appear in a personal email address.
     *
     * Resolved per validation rather than in the constructor: admin services
     * are built on `unity/loaded`, well before a theme's functions.php has
     * had a chance to add the filter.
     *
     * @return list<string> Lower-cased domains, never empty of the default.
     */
    private function blockedDomains(): array
    {
        $filtered = apply_filters('amber_intergroup_email_domains', [self::INTERGROUP_DOMAIN]);

        if (!is_array($filtered)) {
            return [self::INTERGROUP_DOMAIN];
        }

        $domains = [];

        foreach ($filtered as $domain) {
            if (!is_string($domain)) {
                continue;
            }

            $domain = strtolower(trim($domain));

            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return $domains;
    }
}
