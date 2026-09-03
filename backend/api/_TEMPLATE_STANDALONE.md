<?php
/**
 * STANDARD BOOTSTRAP TEMPLATE for standalone API endpoint files
 * located at backend/api/<group>/<file>.php
 *
 * Copy the "BOOTSTRAP HEADER" block below (lines between the markers) to the
 * very top of every endpoint file, right after the opening `<?php` doc comment.
 * Then implement the endpoint logic using the helper functions documented below.
 *
 * ==== BOOTSTRAP HEADER ====
 * require_once __DIR__ . '/../../../config.php';         // root config.php -> defines APP_NAME, MONGODB_URI, DB_NAME, getCollection()
 * require_once __DIR__ . '/../../php/security.php';       // defines sanitizeInput, validateEmail, validatePasswordStrength, validatePhone, generateCSRFToken, verifyCSRFToken, hashPassword, verifyPassword, isValidObjectId, phpDateToMongo, mongoDateToPHP, jsonResponse, errorResponse, successResponse, logActivity, checkRateLimit, getUserIP
 * require_once __DIR__ . '/../../php/session_manager.php'; // defines requireRole, requireActiveSession, getCurrentUserId, getCurrentUserRole, getCurrentUserName, getSessionData, createUserSession, getRoleDashboardUrl, isLoggedIn
 * ========================
 *
 * AVAILABLE HELPER FUNCTIONS (from above files):
 *   getCollection(string $name): MongoDB\Collection|null   // users, wallets, transactions, categories, budgets, goals, notifications, complaints, activity_logs, login_history, notes, appointments, expenses, beneficiaries, receipts, password_resets, otp_verifications
 *   sanitizeInput(mixed): mixed
 *   validateEmail(string): bool
 *   validatePasswordStrength(string): array['valid'=>bool,'errors'=>array]
 *   validatePhone(string): bool
 *   validateDate(string, 'Y-m-d'): bool
 *   validateAmount(mixed): bool
 *   verifyCSRFToken(string): bool
 *   generateCSRFToken(): string
 *   isValidObjectId(string): bool
 *   hashPassword(string): string
 *   verifyPassword(string,string): bool
 *   phpDateToMongo($date=null): MongoDB\BSON\UTCDateTime
 *   mongoDateToPHP($mongoDate): DateTime
 *   jsonResponse(array,int): void (exits)
 *   errorResponse(string,int=400): void (exits)
 *   successResponse($data=null,string='Success'): void (exits)
 *   logActivity(string $action, mixed $userId=null, array $details=[]): void
 *   checkRateLimit(string,int,int): bool
 *   getUserIP(): string
 *   requireActiveSession(): void (exits 401 if not logged in)
 *   requireRole(array $roles): void (exits 403 if wrong role)
 *   getCurrentUserId(): string|null
 *   getCurrentUserRole(): string|null
 *   getCurrentUserName(): string
 *   createUserSession(array $user): void
 *   getSessionData(): array
 *   getRoleDashboardUrl(): string
 *
 * RESPONSE CONVENTIONS:
 *   - success: successResponse($data, 'Message')
 *   - error:   errorResponse('Message', 4xx)
 *   The frontend reads { success: bool, message: string, data: mixed }.
 *
 * USER DOCUMENT FIELDS (users collection):
 *   _id, email, password_hash, first_name, last_name, phone, role
 *   (admin|staff|receptionist|customer), status (active|suspended),
 *   account_number, account_type, balance, created_at, updated_at,
 *   deleted_at, reset_token, reset_token_expires, login_attempts, locked_until
 *
 * ROUTING PATTERN for a typical endpoint:
 *   if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
 *       errorResponse('Method not allowed', 405);
 *   }
 *   $data = getRequestData(); // JSON body or $_POST
 *   ... implement ...
 *   successResponse($payload, 'Done');
 */
