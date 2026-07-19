<?php
/**
 * BLOOMINOUS - Secure Server-Side SMTP Mail Router
 */

// FIX 1: Suppress on-screen error/warning HTML output so nothing but our
// json_encode() calls ever reach the response body. Errors still get
// logged server-side (check your PHP error log), just not printed.
ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// FIX 2: Build an absolute path with __DIR__ instead of a bare relative
// path. A relative path like 'vendor/autoload.php' depends on PHP's
// current working directory at request time, which is not guaranteed to
// be this script's folder - that's very likely why the require failed.
$autoloadPath = __DIR__ . '/vendor/autoload.php';

// FIX 3: Check the file exists BEFORE requiring it, so a missing
// dependency produces a clean JSON error instead of an uncatchable
// fatal "Failed opening required ..." engine error.
if (!file_exists($autoloadPath)) {
    echo json_encode([
        'success' => false,
        'message' => 'Mail library not found on server. Run "composer install" in the project root.'
    ]);
    exit();
}

require $autoloadPath;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
$code = strip_tags($_POST['code'] ?? '');

if (!$email || strlen($code) !== 6) {
    echo json_encode(['success' => false, 'message' => 'Malformed data vectors.']);
    exit();
}

$mail = new PHPMailer(true);

try {
    // Server SMTP Settings Configuration
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'luckyboyph18@gmail.com'; // Your Gmail address
    $mail->Password   = 'ykxrllxjhwibkgwu'; // Your 16-character Gmail App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // TEMP DEBUG: write the full SMTP conversation to a log file so we can
    // see Google's exact rejection reason instead of PHPMailer's generic
    // "Could not authenticate" summary. This never touches the JSON output.
    // Remove this block once the real cause is found and fixed.
    $mail->SMTPDebug   = 2; // 2 = show client -> server and server -> client messages
    $mail->Debugoutput = function($str, $level) {
        file_put_contents(__DIR__ . '/mail_debug.log', date('Y-m-d H:i:s') . " | " . $str . "\n", FILE_APPEND);
    };

    // Recipients
    $mail->setFrom('luckyboyph18@gmail.com', 'BLOOMINOUS System');
    $mail->addAddress($email);

    // Content Style Settings
    $mail->isHTML(true);
    $mail->Subject = 'BLOOMINOUS - Security Recovery Code';

    // Aesthetic email template matching your design theme
    $mail->Body    = "
        <div style='font-family: \"Poppins\", \"Inter\", sans-serif; background: #FFFDF7; padding: 40px; color: #363949; max-width: 500px; margin: 0 auto; border-radius: 30px; border: 1px solid #f0f0f0;'>
            <div style='text-align: center; font-size: 24px; font-weight: 900; letter-spacing: 4px; color: #F59E0B; margin-bottom: 20px;'>BLOOM</div>
            <h2 style='text-align: center; font-weight: 800; margin-bottom: 10px;'>Account Recovery</h2>
            <p style='text-align: center; color: #7d8da1; font-size: 13px; margin-bottom: 30px;'>Use the single-use security token below to configure your new credentials.</p>
            <div style='background: #fafafa; border: 2px dashed #F59E0B; padding: 20px; border-radius: 20px; text-align: center; font-size: 32px; font-weight: 900; letter-spacing: 8px; color: #363949;'>
                $code
            </div>
            <p style='text-align: center; color: #b2bec3; font-size: 11px; margin-top: 30px; line-height: 1.6;'>If you did not initiate this system account request, please safely ignore this broadcast terminal telemetry transmission.</p>
        </div>
    ";

    $mail->send();
    echo json_encode(['success' => true, 'message' => 'Email dispatch finalized.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => "Mail transport layer failure: {$mail->ErrorInfo}"]);
}