<?php
/**
 * BLOOMINOUS - Local Disposable Email Domain Blocklist
 *
 * Free, offline first line of defense against temp-mail signups.
 * Runs BEFORE the IPQS network call in check_email_risk.php, so:
 *   - it works even if IPQS is down, rate-limited, or times out
 *   - it costs nothing and needs no Firebase Blaze / Cloud Functions
 *   - it catches the most common temp-mail providers instantly
 *
 * This is intentionally a static, hand-maintained list (not exhaustive).
 * IPQS remains the secondary, broader check for domains not listed here.
 * Add new abused domains as you spot them in fraud_analytics.php.
 */

function bloom_is_disposable_domain(string $email): bool
{
    $atPos = strrpos($email, '@');
    if ($atPos === false) {
        return false;
    }

    $domain = strtolower(trim(substr($email, $atPos + 1)));
    // Strip a trailing dot if present (some MTAs allow "domain.com.")
    $domain = rtrim($domain, '.');

    static $blocked = null;
    if ($blocked === null) {
        $blocked = array_flip([
            '10minutemail.com', '10minutemail.net', '10minemail.com',
            '20minutemail.com', 'temp-mail.org', 'tempmail.com',
            'tempmail.net', 'tempmailo.com', 'guerrillamail.com',
            'guerrillamail.info', 'guerrillamail.biz', 'guerrillamail.de',
            'sharklasers.com', 'mailinator.com', 'mailinator.net',
            'maildrop.cc', 'mintemail.com', 'throwawaymail.com',
            'yopmail.com', 'yopmail.fr', 'yopmail.net',
            'getnada.com', 'trashmail.com', 'trashmail.net',
            'dispostable.com', 'fakeinbox.com', 'moakt.cc',
            'moakt.com', 'emailondeck.com', 'discard.email',
            'mailnesia.com', 'spamgourmet.com', 'mytemp.email',
            'mohmal.com', 'tempinbox.com', 'temp-mail.io',
            'crazymailing.com', 'burnermail.io', 'inboxkitten.com',
            'nada.email', 'tempr.email', 'mail-temp.com',
            'tempmailaddress.com', 'emltmp.com', 'luxusmail.org',
            '33mail.com', 'anonbox.net', 'spambog.com',
            // Additional mirrors / alternates commonly used as
            // "10 minute mail" style throwaway addresses.
            '1secmail.com', '1secmail.net', '1secmail.org',
            'esiix.com', 'wwjmp.com', 'vjuum.com', 'laoeq.com',
            'cetpage.com', 'dropmail.me', 'mail.tm', 'mailtm.com',
            'correotemporal.org', 'inboxbear.com', 'tempmailaddress.com',
            'emltmp.com', 'luxusmail.org', 'nada.email',
            'mail-temp.com', 'tempsky.com', 'tmail.ws',
            'guerrillamail.info', 'guerrillamail.biz', 'guerrillamail.de',
            'grr.la', 'pokemail.net', 'spamherelots.com',
        ]);
    }

    if (isset($blocked[$domain])) {
        return true;
    }

    // Pattern fallback: catches rotating/mirror domains that use these
    // words but aren't individually listed above (e.g. new mirrors of
    // 10minutemail / tempmail / guerrillamail spun up after this list
    // was last updated). Keeps working with zero external calls.
    static $patterns = [
        'minutemail', 'tempmail', 'temp-mail', 'guerrillamail',
        'throwaway', 'trashmail', 'fakemail', 'fakeinbox',
        'disposable', 'burnermail', 'spambox', 'mailinator',
    ];
    foreach ($patterns as $pattern) {
        if (str_contains($domain, $pattern)) {
            return true;
        }
    }

    return false;
}