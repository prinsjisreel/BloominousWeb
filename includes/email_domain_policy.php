<?php
/**
 * BLOOMINOUS - Email Domain Policy: Major Providers + MX Validity Check
 *
 * Two-tier check, OFF by default (see toggle below):
 *   1. Major free providers (gmail/outlook/yahoo/icloud/etc.) - always
 *      allowed instantly, no network call.
 *   2. Any OTHER domain - allowed only if it has a real, resolvable MX
 *      record (i.e. it's a genuine domain that can actually receive mail).
 *
 * IMPORTANT - what this does and does NOT do:
 *   - DOES catch: typos (gmial.com), and completely fake/non-existent
 *     domains that were never registered or have no mail server at all.
 *   - Does NOT catch: disposable/temp-mail domains. Those domains have
 *     real, working MX records by design. That job belongs to
 *     disposable_domains.php + AbstractAPI, which run as separate layers
 *     regardless of this setting.
 *
 * bloom_domain_has_mail_server() is ALSO reused directly by
 * submit_order.php's checkout-time mail-server existence check — that
 * usage is independent of the ENFORCE_EMAIL_DOMAIN_ALLOWLIST toggle
 * below, which only gates bloom_is_domain_allowed()'s registration-time
 * behavior.
 *
 * Toggle: set ENFORCE_EMAIL_DOMAIN_ALLOWLIST=1 in config.local.php.
 */

function bloom_is_domain_allowed(string $email): bool
{
    if (getenv('ENFORCE_EMAIL_DOMAIN_ALLOWLIST') !== '1') {
        return true; // feature disabled — every domain passes
    }

    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return false;
    }
    $domain = strtolower(trim(substr($email, $atPos + 1)));

    static $majorProviders = null;
    if ($majorProviders === null) {
        $majorProviders = array_flip([
            'gmail.com', 'googlemail.com',
            'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
            'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
            'icloud.com', 'me.com', 'mac.com',
        ]);
    }

    if (isset($majorProviders[$domain])) {
        return true;
    }

    return bloom_domain_has_mail_server($domain);
}

/**
 * Checks whether a domain has a resolvable mail server: a proper MX
 * record, or (per RFC 5321 fallback behavior) an A/AAAA record if no MX
 * exists.
 *
 * CAVEAT: checkdnsrr() returning false is ambiguous - could mean "no
 * mail server" OR "DNS resolution is broken right now." Self-tests
 * against gmail.com first - if even that fails, DNS itself is the
 * problem, so this fails OPEN for everyone until DNS recovers.
 */
function bloom_domain_has_mail_server(string $domain): bool
{
    if (!checkdnsrr('gmail.com', 'MX')) {
        error_log('DNS resolution appears broken (gmail.com MX check failed) - failing open on domain validation.');
        return true;
    }

    if (checkdnsrr($domain, 'MX')) {
        return true;
    }

    return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'AAAA');
}