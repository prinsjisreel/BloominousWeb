<?php
/**
 * BLOOMINOUS - Optional Email Domain Allow-list
 *
 * Priority #5 (lowest) from the fraud-prevention roadmap — OFF by
 * default. It does NOT stop the harder abuse pattern (bulk-created real
 * Gmail/Outlook/Yahoo accounts — that's what IPQS + rate limiting +
 * Turnstile target), and it locks out anyone using a workplace/school/
 * custom domain email, which is a real cost. Only turn this on if you
 * specifically see abuse from small/obscure providers IPQS isn't
 * flagging as disposable.
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

    static $allowed = null;
    if ($allowed === null) {
        $allowed = array_flip([
            'gmail.com', 'googlemail.com',
            'yahoo.com', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com',
            'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
            'icloud.com', 'me.com', 'mac.com',
        ]);
    }

    return isset($allowed[$domain]);
}