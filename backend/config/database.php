<?php
declare(strict_types=1);

// MongoDB Atlas Connection (unified with MPWT)
require_once __DIR__ . '/constants.php';

// Composer autoloader for mongodb/mongodb library (shared with MPWT root vendor/)
$autoload = __DIR__ . '/../../../../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

function getMongoClient() {
    static $client = null;
    if ($client instanceof MongoDB\Client) {
        return $client;
    }
    try {
        $client = new MongoDB\Client(MONGODB_URI);
        return $client;
    } catch (Exception $e) {
        error_log("MongoDB Connection Error: " . $e->getMessage());
        return null;
    }
}

function getMongoDB() {
    $client = getMongoClient();
    if ($client) {
        return $client->selectDatabase(DB_NAME);
    }
    return null;
}

function getCollection($name) {
    $db = getMongoDB();
    if ($db) {
        return $db->selectCollection($name);
    }
    return null;
}

// Backward-compatible alias for existing code
function db() {
    return getMongoDB();
}