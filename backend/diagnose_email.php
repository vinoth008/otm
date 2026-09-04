<?php
declare(strict_types=1);
// Diagnostic tool — check email/SMTP config on the deployed host.
// WARNING: Remove this file before production launch.
header('Content-Type: text/plain');

require_once __DIR__ . '/../config/mail.php';

echo "=== EMAIL DIAGNOSTIC ===\n";
echo 'PHP version: ' . PHP_VERSION . "\n";
echo "SMTP_HOST:     " . ((SMTP_HOST !== '') ? SMTP_HOST : '(empty)') . "\n";
echo "SMTP_PORT:     " . SMTP_PORT . "\n";
echo "SMTP_USERNAME: " . ((SMTP_USERNAME !== '') ? SMTP_USERNAME : '(empty)') . "\n";
echo "SMTP_PASSWORD: " . ((SMTP_PASSWORD !== '') ? '(set, ' . strlen(SMTP_PASSWORD) . ' chars)' : '(EMPTY - this is why email fails)') . "\n";
echo "SMTP_SECURE:   " . SMTP_SECURE . "\n";
echo "is_email_configured(): " . var_export(is_email_configured(), true) . "\n\n";

// Test outbound connectivity to Gmail SMTP
echo "=== CONNECTIVITY TEST (from this server) ===\n";
$timeout = 8;
foreach ([SMTP_PORT, 465, 587] as $port) {
    $t = microtime(true);
    $sock = @fsockopen(SMTP_HOST, $port, $errno, $errstr, $timeout);
    $elapsed = round(microtime(true) - $t, 2);
    echo "port {$port}: " . ($sock ? "CONNECTED in {$elapsed}s" : "FAILED({$errno}) {$errstr} in {$elapsed}s") . "\n";
    if ($sock) { fclose($sock); }
}

echo "\n=== DNS ===\n";
echo 'gethostbyname: ' . gethostbyname(SMTP_HOST) . "\n";
$ips = @gethostbynamel(SMTP_HOST);
echo 'gethostbynamel: ' . (is_array($ips) ? implode(', ', $ips) : '(failed)') . "\n";

echo "\nDone.\n";
