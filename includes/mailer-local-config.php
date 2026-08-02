<?php
/**
 * BLOOMINOUS - Local SMTP Credentials (NOT committed to git)
 *
 * This file exists so you don't have to touch your Apache/PHP-FPM vhost
 * config to set BLOOM_SMTP_USER / BLOOM_SMTP_PASS. mailer_config.php
 * requires this file automatically (if it exists) before falling back to
 * getenv(), so on shared/production hosting you can still just set real
 * environment variables instead and this file can be deleted or left empty.
 *
 * Same Gmail address + App Password already used in send_recovery_email.php
 * - centralized here so bloom_send_mail() (used by send_verification_email.php
 * and anything else going forward) can use the same credentials.
 */

putenv('BLOOM_SMTP_USER=luckyboyph18@gmail.com');
putenv('BLOOM_SMTP_PASS=ykxrllxjhwibkgwu');