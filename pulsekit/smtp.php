<?php

require_once __DIR__ . '/vendor/PHPMailer/src/Exception.php';
require_once __DIR__ . '/vendor/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/vendor/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'vertechs.ec@gmail.com');
define('SMTP_PASS', 'hndh fipy fhhw ftme');
define('SMTP_PORT', 587);

define('SMTP_FROM_EMAIL', 'vertechs.ec@gmail.com');
define('SMTP_FROM_NAME', 'PulseKit');

function configureSMTP(PHPMailer $mail)
{
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
}
