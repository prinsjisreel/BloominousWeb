<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function bloom_send_mail(string $toEmail, string $subject, string $htmlBody): void
{
    // Replace these two lines with your actual details
    $smtpUser = 'luckyboyph18@gmail.com'; 
    $smtpPass = 'ykxrllxjhwibkgwu'; 

    if (!$smtpUser || !$smtpPass) {
        throw new \RuntimeException('SMTP credentials are missing.');
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