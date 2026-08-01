<?php
// AbstractAPI email validation — Layer 5 in check_email_risk.php.
// Get a key at https://www.abstractapi.com/api/email-verification-validation-api
putenv('ABSTRACTAPI_EMAIL_KEY=e2f5bc8e218a464584497011dd332b5f');

// AbstractAPI IP Intelligence — VPN/proxy/Tor check in submit_order.php.
// Separate product, separate key from the one above.
// Get it at https://app.abstractapi.com/api/ip-intelligence/tester
putenv('ABSTRACTAPI_IP_KEY=b3d739e396814a4fb1f3e9df0bb9bad3');

// Cloudflare Turnstile — free bot-check widget on register.php.
// Get both from https://dash.cloudflare.com/ → Turnstile → Add site.
putenv('TURNSTILE_SITE_KEY=0x4AAAAAAEBkrFRNLCnflngG');
putenv('TURNSTILE_SECRET_KEY=0x4AAAAAAEBkrDaj-Umr03FBOAn7ZmnkVcA');
// Optional last-resort domain allow-list (includes/email_domain_policy.php).
// Leave at 0 unless you specifically need it.
putenv('ENFORCE_EMAIL_DOMAIN_ALLOWLIST=0');