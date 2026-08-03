<?php
declare(strict_types=1);

/**
 * EmailService - PHPMailer with Gmail SMTP
 * Handles OTP verification, forgot password OTP, budget alerts, bill reminders,
 * and monthly report emails.
 */

// Ensure PHPMailer is available via Composer
require_once __DIR__ . '/../config/constants.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {

    /** @var array SMTP configuration from environment or defaults */
    private $config;

    /** @var bool Whether email is enabled. When false, OTPs are returned to the client for demo mode. */
    public $enabled;

    public function __construct() {
        $this->config = [
            'host' => getenv('SMTP_HOST') ?: 'smtp.gmail.com',
            'port' => (int)(getenv('SMTP_PORT') ?: 587),
            'username' => getenv('SMTP_USERNAME') ?: '',
            'password' => getenv('SMTP_PASSWORD') ?: '',
            'from_email' => getenv('SMTP_FROM_EMAIL') ?: (getenv('SMTP_USERNAME') ?: 'noreply@expensetracker.local'),
            'from_name' => getenv('SMTP_FROM_NAME') ?: 'Smart Expense Tracker',
            'secure' => getenv('SMTP_SECURE') ?: 'tls'
        ];
        // Email is enabled only when SMTP credentials are configured
        $this->enabled = !empty($this->config['username']) && !empty($this->config['password']);
    }

    /**
     * Get a configured PHPMailer instance.
     * @return PHPMailer|null
     */
    private function getMailer() {
        if (!$this->enabled) {
            return null;
        }
        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $this->config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['username'];
            $mail->Password = $this->config['password'];
            $mail->SMTPSecure = $this->config['secure'];
            $mail->Port = $this->config['port'];
            $mail->setFrom($this->config['from_email'], $this->config['from_name']);
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            return $mail;
        } catch (Exception $e) {
            error_log("PHPMailer init failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Send an email.
     * @param string $to Recipient email
     * @param string $toName Recipient name
     * @param string $subject Subject line
     * @param string $htmlBody HTML body
     * @param string $textBody Plain text fallback
     * @return bool True on success, false on failure
     */
    public function send($to, $toName, $subject, $htmlBody, $textBody = '') {
        $mail = $this->getMailer();
        if (!$mail) {
            error_log("Email skipped for {$to} ({$subject}) - SMTP not configured");
            return false;
        }
        try {
            $mail->addAddress($to, $toName);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $textBody ?: strip_tags($htmlBody);
            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log("Email send failed to {$to}: " . $mail->ErrorInfo);
            return false;
        }
    }

    /**
     * Generate a numeric OTP.
     * @param int $length OTP length
     * @return string
     */
    public static function generateOTP($length = 6) {
        $digits = '0123456789';
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= $digits[random_int(0, strlen($digits) - 1)];
        }
        return $otp;
    }

    /**
     * Send an OTP email for email verification or password reset.
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $otp The OTP code
     * @param string $purpose 'verify_email' or 'forgot_password'
     * @return bool
     */
    public function sendOTPEmail($to, $name, $otp, $purpose = 'verify_email') {
        $isVerify = $purpose === 'verify_email';
        $subject = $isVerify
            ? 'Verify Your Email - ' . APP_NAME
            : 'Reset Your Password - ' . APP_NAME;
        $heading = $isVerify ? 'Email Verification' : 'Password Reset';
        $message = $isVerify
            ? 'Thank you for registering. Use the OTP below to verify your email address.'
            : 'We received a request to reset your password. Use the OTP below to proceed.';
        $expiry = $isVerify ? '10 minutes' : '15 minutes';
        $html = $this->buildEmailTemplate($heading, $message, $otp, $expiry, APP_NAME);
        return $this->send($to, $name, $subject, $html);
    }

    /**
     * Send a budget alert email.
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $category Budget category
     * @param float $limit Budget limit
     * @param float $spent Amount spent
     * @param float $percentage Percentage used
     * @return bool
     */
    public function sendBudgetAlertEmail($to, $name, $category, $limit, $spent, $percentage) {
        $subject = "Budget Alert: {$category} - {$percentage}% Used - " . APP_NAME;
        $message = "Your budget for <strong>{$category}</strong> has reached <strong>{$percentage}%</strong> of the limit.";
        $details = "Limit: ₹" . number_format($limit, 2) . "<br>Spent: ₹" . number_format($spent, 2);
        $html = $this->buildEmailTemplate('Budget Alert', $message, null, null, APP_NAME, $details);
        return $this->send($to, $name, $subject, $html);
    }

    /**
     * Send a bill reminder email.
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param string $billTitle Bill/reminder title
     * @param float $amount Bill amount
     * @param string $dueDate Due date
     * @return bool
     */
    public function sendReminderEmail($to, $name, $billTitle, $amount, $dueDate) {
        $subject = "Reminder: {$billTitle} due on {$dueDate} - " . APP_NAME;
        $message = "This is a reminder that <strong>{$billTitle}</strong> is due on <strong>{$dueDate}</strong>.";
        $details = "Amount: ₹" . number_format($amount, 2);
        $html = $this->buildEmailTemplate('Bill Reminder', $message, null, null, APP_NAME, $details);
        return $this->send($to, $name, $subject, $html);
    }

    /**
     * Send a monthly report summary email.
     * @param string $to Recipient email
     * @param string $name Recipient name
     * @param array $summary Summary data (income, expense, balance, category breakdown)
     * @return bool
     */
    public function sendMonthlyReportEmail($to, $name, array $summary) {
        $month = date('F Y', strtotime(($summary['year'] ?? date('Y')) . '-' . str_pad((string)($summary['month'] ?? date('m')), 2, '0', STR_PAD_LEFT) . '-01'));
        $subject = "Your {$month} Expense Report - " . APP_NAME;
        $income = $summary['total_income'] ?? 0;
        $expense = $summary['total_expense'] ?? 0;
        $net = $income - $expense;
        $message = "Here is your spending summary for <strong>{$month}</strong>.";
        $details = "
            Income: ₹" . number_format($income, 2) . "<br>
            Expenses: ₹" . number_format($expense, 2) . "<br>
            Net Savings: ₹" . number_format($net, 2) . "
        ";
        $html = $this->buildEmailTemplate('Monthly Report', $message, null, null, APP_NAME, $details);
        return $this->send($to, $name, $subject, $html);
    }

    /**
     * Build a branded HTML email template.
     */
    private function buildEmailTemplate($heading, $message, $otp = null, $expiry = null, $appName = '', $extraDetails = '') {
        $otpHtml = '';
        if ($otp !== null) {
            $otpHtml = '<div style="background:#f0f4ff;border:2px dashed #4a6cf7;border-radius:12px;padding:20px;margin:20px 0;text-align:center;">
                            <div style="font-size:14px;color:#555;margin-bottom:8px;">Your OTP Code</div>
                            <div style="font-size:32px;font-weight:700;color:#4a6cf7;letter-spacing:8px;font-family:monospace;">' . htmlspecialchars($otp) . '</div>' .
                        ($expiry ? '<div style="font-size:12px;color:#888;margin-top:8px;">Valid for ' . htmlspecialchars($expiry) . '</div>' : '') .
                    '</div>';
        }
        return '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
        <body style="margin:0;padding:0;background:#f4f6fb;font-family:Segoe UI, Arial, sans-serif;">
            <div style="max-width:600px;margin:0 auto;padding:30px 20px;">
                <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:16px;padding:40px 36px;color:#fff;">
                    <div style="font-size:28px;font-weight:700;margin-bottom:6px;">' . htmlspecialchars($heading) . '</div>
                    <div style="font-size:14px;opacity:0.9;">' . htmlspecialchars($appName ?: 'Smart Expense Tracker') . '</div>
                </div>
                <div style="background:#ffffff;border-radius:16px;padding:36px 32px;margin-top:-16px;box-shadow:0 8px 24px rgba(0,0,0,0.08);">
                    <p style="font-size:15px;line-height:1.6;color:#333;">' . $message . '</p>
                    ' . $otpHtml . '
                    ' . ($extraDetails ? '<div style="background:#f8f9fc;border-radius:10px;padding:16px;font-size:14px;color:#444;line-height:1.8;">' . $extraDetails . '</div>' : '') . '
                    <p style="font-size:13px;color:#888;margin-top:24px;line-height:1.5;">
                        If you did not request this email, you can safely ignore it.<br>
                        &copy; ' . date('Y') . ' ' . htmlspecialchars($appName ?: 'Smart Expense Tracker') . '. All rights reserved.
                    </p>
                </div>
            </div>
        </body>
        </html>';
    }
}