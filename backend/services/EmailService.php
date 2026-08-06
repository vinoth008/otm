<?php
declare(strict_types=1);

/**
 * EmailService - zero-dependency Gmail SMTP sender.
 * Speaks SMTP directly over a socket (STARTTLS), no PHPMailer required.
 */

require_once __DIR__ . '/../config/mail.php';

class EmailService
{
    public bool $enabled;
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $fromName;

    public function __construct()
    {
        $this->host = SMTP_HOST;
        $this->port = SMTP_PORT;
        $this->username = SMTP_USERNAME;
        $this->password = SMTP_PASSWORD;
        $this->fromName = SMTP_FROM_NAME;
        $this->enabled = is_email_configured();
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody): bool
    {
        if (!$this->enabled) {
            error_log('[EmailService] SMTP not configured - mail skipped');
            return false;
        }

        $from = $this->fromName !== '' ? "{$this->fromName} <{$this->username}>" : $this->username;
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'From: ' . $this->encodeHeader($from),
            'Subject: ' . $this->encodeHeader($subject),
            'Date: ' . date('r'),
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody));

        return $this->smtpSend($toEmail, $message);
    }

    public function sendOTPEmail(string $toEmail, string $toName, string $otp, string $purpose = 'verify_email'): bool
    {
        $purposeLabel = match ($purpose) {
            'forgot_password' => 'Reset your password',
            'verify_email' => 'Verify your email address',
            default => 'Account verification',
        };
        $name = $toName !== '' ? htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') : 'there';

        $html = '<div style="background:#f1f5f9;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
            . '<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.08);">'
            . '<div style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:28px 32px;text-align:center;">'
            . '<h1 style="margin:0;color:#fff;font-size:22px;">' . $purposeLabel . '</h1></div>'
            . '<div style="padding:32px;">'
            . '<p style="color:#1e293b;font-size:15px;line-height:1.6;">Hi ' . $name . ',</p>'
            . '<p style="color:#1e293b;font-size:15px;line-height:1.6;">Use the One-Time Password below to continue. Valid for <strong>10 minutes</strong>.</p>'
            . '<div style="background:#eef2ff;border:2px dashed #818cf8;border-radius:12px;padding:20px;text-align:center;margin:20px 0;">'
            . '<span style="font-size:36px;font-weight:800;letter-spacing:12px;color:#4f46e5;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</span></div>'
            . '<p style="color:#64748b;font-size:13px;">If you did not request this code, please ignore this email. Never share this OTP.</p>'
            . '</div></div></div>';

        return $this->send($toEmail, $toName, "Your OTP: {$otp} - Secure Online Transaction System", $html);
    }

    private function smtpSend(string $toEmail, string $message): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 30);
        if (!$socket) {
            error_log("[EmailService] Cannot connect to SMTP: {$errstr} ({$errno})");
            return false;
        }
        stream_set_timeout($socket, 30);
        $this->expect($socket, 220);

        fwrite($socket, "EHLO localhost\r\n");
        $this->expect($socket, 250);

        fwrite($socket, "STARTTLS\r\n");
        $this->expect($socket, 220);
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log('[EmailService] STARTTLS handshake failed');
            fclose($socket);
            return false;
        }

        fwrite($socket, "EHLO localhost\r\n");
        $this->expect($socket, 250);

        fwrite($socket, "AUTH LOGIN\r\n");
        $this->expect($socket, 334);
        fwrite($socket, base64_encode($this->username) . "\r\n");
        $this->expect($socket, 334);
        fwrite($socket, base64_encode($this->password) . "\r\n");
        $this->expect($socket, 235);

        fwrite($socket, "MAIL FROM:<{$this->username}>\r\n");
        $this->expect($socket, 250);
        fwrite($socket, "RCPT TO:<{$toEmail}>\r\n");
        $this->expect($socket, 250);
        fwrite($socket, "DATA\r\n");
        $this->expect($socket, 354);

        fwrite($socket, $message . "\r\n.\r\n");
        $this->expect($socket, 250);

        fwrite($socket, "QUIT\r\n");
        fclose($socket);
        return true;
    }

    private function expect($socket, int $code): void
    {
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $actual = (int)substr($response, 0, 3);
        if ($actual !== $code) {
            error_log("[EmailService] SMTP expected {$code}, got: {$response}");
            fclose($socket);
            throw new RuntimeException("SMTP error: expected {$code}, got {$actual}");
        }
    }

    private function encodeHeader(string $value): string
    {
        if (preg_match('/[^\x20-\x7E]/', $value)) {
            return '=?UTF-8?B?' . base64_encode($value) . '?=';
        }
        return $value;
    }
}