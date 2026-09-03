<?php
declare(strict_types=1);

/**
 * CORS Configuration
 * In production (frontend + backend on same domain) CORS is not needed.
 * In development or split deployments, this allows any origin.
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$isDev = (isset($_SERVER['HTTP_HOST']) && preg_match('/localhost|127\.0\.0\.1/i', $_SERVER['HTTP_HOST']));

if (!empty($origin)) {
    // In production on a real domain, allow any origin (same-origin + cross-origin).
    // For stricter security, replace with a whitelist read from .env.
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Accept, Origin');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
