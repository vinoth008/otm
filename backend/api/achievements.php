<?php
declare(strict_types=1);
// Achievements API — module=achievements
require_once __DIR__ . '/../services/AchievementService.php';

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        listAchievements();
        break;
    case 'check':
        checkAndUnlock();
        break;
    case 'catalog':
        achievementCatalog();
        break;
    default:
        errorResponse('Invalid action', 404);
}

/** Return the user's unlocked achievements. */
function listAchievements() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) {
        errorResponse('Invalid user', 400);
    }
    $data = AchievementService::getAllWithStatus($userId);
    successResponse($data, 'Achievements retrieved');
}

/** Evaluate + unlock any new achievements, and return what was earned. */
function checkAndUnlock() {
    requireActiveSession();
    $userId = getCurrentUserId();
    if (!isValidObjectId($userId)) {
        errorResponse('Invalid user', 400);
    }
    $newOnes = AchievementService::checkAndUnlock($userId);
    successResponse([
        'unlocked_now' => $newOnes,
        'summary' => AchievementService::getAllWithStatus($userId),
    ], $newOnes ? 'New achievement(s) unlocked!' : 'No new achievements');
}

/** Return the full achievement catalog. */
function achievementCatalog() {
    successResponse(AchievementService::catalog(), 'Achievement catalog');
}
