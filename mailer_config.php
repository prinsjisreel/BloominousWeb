<?php
/**
 * BLOOMINOUS - Mail Helper (credentials from environment, not source)
 *
 * Set these on the server (e.g. in your Apache/PHP-FPM vhost config, or a
 * .env loaded before PHP starts — never commit them to the repo):
 *   BLOOM_SMTP_USER = the sending Gmail address
 *   BLOOM_SMTP_PASS = a Gmail App Password (Google Account > Security >
 *                     App Passwords) — NOT the account's login password
 *
 * send_recovery_email.php currently has its own hardcoded copy of these
 * credentials and should be migrated to call bloom_send_mail() too; that
 * file was NOT touched by this change to avoid altering existing recovery
 * behavior without a separate review.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * @throws \RuntimeException if BLOOM_SMTP_USER / BLOOM_SMTP_PASS are unset
 * @throws PHPMailerException on send failure
 */
function bloom_send_mail(string $toEmail, string $subject, string $htmlBody): void
{
    $smtpUser = getenv('BLOOM_SMTP_USER');
    $smtpPass = getenv('BLOOM_SMTP_PASS');

    if (!$smtpUser || !$smtpPass) {
        throw new \RuntimeException(
            'BLOOM_SMTP_USER / BLOOM_SMTP_PASS are not set in the server environment.'
        );
    }

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = $smtpUser;
    $mail->Password = $smtpPass;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;

    $mail->setFrom($smtpUser, 'BLOOMINOUS System');
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;

    $mail->send();
}