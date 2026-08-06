<?php
declare(strict_types=1);

// Legacy Mailer wrapper - delegates to EmailService via config/mail.php
require_once __DIR__ . '/../config/mail.php';
require_once __DIR__ . '/../services/EmailService.php';

function send_email_verification(string $toEmail, string $toName, string $link): bool {
    $service = new EmailService();
    if (!$service->enabled) return false;
    $html = "<p>Hello " . htmlspecialchars($toName) . ",</p><p>Verify your email:</p><p><a href='" . $link . "'>Verify Email</a></p>";
    return $service->send($toEmail, $toName, 'Verify your email address', $html);
}