<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h3>Bloominous Mail Test</h3>";

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    die("<b style='color:red;'>FATAL ERROR:</b> The <b>vendor</b> folder is missing. You must upload the vendor folder to Hostinger for PHPMailer and Firebase to work.");
}

require_once __DIR__ . '/includes/mailer_config.php';

try {
    // REPLACE THIS with your personal email address to receive the test
    bloom_send_mail('luckyboyph18@gmail.com', 'Hostinger Mail Test', 'If you see this, PHPMailer is working!');
    echo "<b style='color:green;'>SUCCESS:</b> Email sent perfectly! Your Gmail App Password and SMTP config are correct.";
} catch (Throwable $e) {
    echo "<b style='color:red;'>FAILED:</b> " . $e->getMessage();
}
?>