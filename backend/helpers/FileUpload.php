<?php
declare(strict_types=1);

/**
 * FileUpload.php — File upload validation and storage helpers
 */

require_once __DIR__ . '/../config/constants.php';

/**
 * Validate and move an uploaded file.
 * Returns ['ok' => true, 'path' => <relative-path>] or ['ok' => false, 'message' => ...]
 */
function handle_file_upload(array $file, string $subdir = 'general'): array
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'File upload error: ' . upload_error_message($file['error'] ?? -1)];
    }

    $size = (int)$file['size'];
    if ($size === 0) {
        return ['ok' => false, 'message' => 'Empty file uploaded'];
    }
    if ($size > MAX_FILE_SIZE) {
        return ['ok' => false, 'message' => 'File too large (max 5 MB)'];
    }

    $originalName = $file['name'] ?? 'upload';
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        return ['ok' => false, 'message' => 'File type not allowed. Allowed: ' . implode(', ', ALLOWED_EXTENSIONS)];
    }

    // Validate MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['image/jpeg', 'image/png', 'application/pdf'];
    if (!in_array($mime, $allowedMimes, true)) {
        return ['ok' => false, 'message' => 'Invalid file content type'];
    }

    $destDir = UPLOAD_DIR . $subdir . '/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $ext;
    $destPath = $destDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return ['ok' => false, 'message' => 'Failed to save uploaded file'];
    }

    return ['ok' => true, 'path' => 'uploads/' . $subdir . '/' . $filename, 'filename' => $filename, 'original' => $originalName];
}

/**
 * Delete an uploaded file by its stored relative path.
 */
function delete_uploaded_file(string $relativePath): bool
{
    $fullPath = __DIR__ . '/../../' . ltrim($relativePath, '/');
    if (file_exists($fullPath)) {
        return unlink($fullPath);
    }
    return false;
}

/**
 * Human-readable upload error.
 */
function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds size limit',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload',
        default               => "Unknown error (code {$code})",
    };
}
