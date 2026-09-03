<?php
declare(strict_types=1);

/**
 * CORS Configuration
 * Sets appropriate CORS headers based on environment.
 */

$allowedOrigins = [
    'http://localhost',
    'http://localhost:80',
    'http://127.0.0.1',
    'http://localhost/MPWT',
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins, true) || empty($origin)) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: 'http://localhost'));
} else {
    header('Access-Control-Allow-Origin: http://localhost');
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Requested-With, Accept, Origin');
header('Access-Control-Max-Age: 86400');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}
