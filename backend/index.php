<?php
// backend/index.php — prevent directory listing / route to the app landing page.
require_once __DIR__ . '/../config.php';
header('Location: ' . BASE_URL);
exit;
