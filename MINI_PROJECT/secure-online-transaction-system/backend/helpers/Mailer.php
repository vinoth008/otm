<?php
declare(strict_types=1);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../../vendor/autoload.php';

function send_email_verification(string $toEmail, string $toName, string $link): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'your_email@gmail.com';
        $mail->Password = 'your_app_password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('your_email@gmail.com', 'Secure Online Transaction System');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Verify your email address';
        $mail->Body = "<p>Hello {$toName},</p><p>Click this link to verify your email:</p><p><a href='{$link}'>Verify Email</a></p>";
        $mail->AltBody = "Verify your email: {$link}";

        return $mail->send();
    } catch (Exception $e) {
        log_event('mail.log', 'Mailer error: ' . $mail->ErrorInfo);
        return false;
    }
}