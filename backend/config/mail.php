<?php
declare(strict_types=1);

/**
 * Gmail SMTP Configuration for Secure Online Transaction System
 * Set these in your .env or server environment:
 *   SMTP_USERNAME=yourapp@gmail.com
 *   SMTP_PASSWORD=your_16_char_app_password
 */

define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
define('SMTP_PORT', (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USERNAME', getenv('SMTP_USERNAME') ?: '');
define('SMTP_PASSWORD', getenv('SMTP_PASSWORD') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Secure Online Transaction System');
define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');

function is_email_configured(): bool {
    return SMTP_USERNAME !== '' && SMTP_PASSWORD !== '';
}
