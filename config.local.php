<?php
putenv('IPQS_API_KEY=Vr8nUtJ7fqFpJPUhgaoBcbNCk8EouDhc');

// Cloudflare Turnstile — free bot-check widget on register.php.
// Get both from https://dash.cloudflare.com/ → Turnstile → Add site.
putenv('TURNSTILE_SITE_KEY=0x4AAAAAAEBkrFRNLCnflngG');
putenv('TURNSTILE_SECRET_KEY=0x4AAAAAAEBkrDaj-Umr03FBOAn7ZmnkVcA');
// Optional last-resort domain allow-list (includes/email_domain_policy.php).
// Leave at 0 unless you specifically need it.
putenv('ENFORCE_EMAIL_DOMAIN_ALLOWLIST=0');