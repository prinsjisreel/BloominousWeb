<?php
/**
 * BLOOMINOUS - Mailer (shared)
 *
 * bloom_send_mail() sends HTML email from the shop's own Gmail account.
 * Used by: request_password_reset.php, send_device_otp.php.
 *
 * SECRETS ARE NOT STORED IN THIS FILE. The SMTP login is looked up in:
 *   1. Environment variables: BLOOM_SMTP_USER, BLOOM_SMTP_PASS,
 *      and optionally BLOOM_SMTP_FROM_NAME
 *   2. A private PHP file OUTSIDE the website folder:
 *        <folder above the project>/bloom_secrets.php
 *      On Hostinger that is next to public_html (not inside it), so it can
 *      never be downloaded from the website and never ends up in Git.
 *
 * bloom_secrets.php looks like:
 *   <?php
 *   return [
 *       'smtp_user'      => 'yourshop@gmail.com',
 *       'smtp_pass'      => 'your-16-character-app-password',
 *       'smtp_from_name' => 'BLOOM',
 *   ];
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

/** Reads the SMTP login once per request. */
function bloom_mail_credentials(): array
{
    static $credentials = null;
    if ($credentials !== null) {
        return $credentials;
    }

    $user = getenv('BLOOM_SMTP_USER') ?: null;
    $pass = getenv('BLOOM_SMTP_PASS') ?: null;
    $fromName = getenv('BLOOM_SMTP_FROM_NAME') ?: null;

    if (!$user || !$pass) {
        // includes/ → project folder → the folder ABOVE the project
        $secretsPath = dirname(__DIR__, 2) . '/bloom_secrets.php';
        if (is_readable($secretsPath)) {
            $secrets = require $secretsPath;
            if (is_array($secrets)) {
                $user = $user ?: ($secrets['smtp_user'] ?? null);
                $pass = $pass ?: ($secrets['smtp_pass'] ?? null);
                $fromName = $fromName ?: ($secrets['smtp_from_name'] ?? null);
            }
        }
    }

    $credentials = [
        'user' => $user,
        'pass' => $pass,
        'fromName' => $fromName ?: 'BLOOM',
    ];
    return $credentials;
}

function bloom_send_mail(string $toEmail, string $subject, string $htmlBody): void
{
    $credentials = bloom_mail_credentials();
    if (!$credentials['user'] || !$credentials['pass']) {
        throw new \RuntimeException('SMTP credentials are missing. Add them to bloom_secrets.php (see includes/mailer_config.php).');
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $credentials['user'];
    $mail->Password = $credentials['pass'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';

    $mail->setFrom($credentials['user'], $credentials['fromName']);
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    // Plain-text version for mail apps that don't show HTML (also helps
    // keep the email out of spam folders).
    $mail->AltBody = trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)), ENT_QUOTES, 'UTF-8'));

    $mail->send();
}