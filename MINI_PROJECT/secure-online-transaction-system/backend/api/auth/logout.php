<?php
declare(strict_types=1);

require_once __DIR__ . '/../../helpers/response.php';
require_once __DIR__ . '/../../services/auth_service.php';

auth_destroy_session();
json_response(true, 'Logged out successfully');