<?php
/**
 * User logout endpoint.
 */
require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../php/security.php';
require_once __DIR__ . '/../../php/session_manager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    errorResponse('Method not allowed', 405);
}

requireActiveSession();
logActivity('logout', getCurrentUserId());
destroySession();
successResponse(null, 'Logged out successfully');
