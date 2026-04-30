<?php
session_start();
header('Content-Type: application/json');
require_once '../includes/auth.php';
require_once '../includes/mongo.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File too large (server limit).',
        UPLOAD_ERR_FORM_SIZE  => 'File too large.',
        UPLOAD_ERR_PARTIAL    => 'Upload incomplete.',
        UPLOAD_ERR_NO_FILE    => 'No file selected.',
    ];
    $code = $_FILES['avatar']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => $uploadErrors[$code] ?? 'Upload failed.']);
    exit;
}

$file     = $_FILES['avatar'];
$maxBytes = 2 * 1024 * 1024; // 2 MB

// Size check
if ($file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'Image must be under 2 MB.']);
    exit;
}

// Type check via actual content, not extension
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$mime = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowedMimes)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, GIF, or WebP images allowed.']);
    exit;
}

$ext      = ['image/jpeg' => 'jpg', 'image/png' => 'png',
             'image/gif'  => 'gif', 'image/webp' => 'webp'][$mime];
$userId   = (int)currentUser()['id'];
$filename = 'avatar_' . $userId . '_' . time() . '.' . $ext;
$destDir  = dirname(__DIR__) . '/assets/uploads/avatars/';
$destPath = $destDir . $filename;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save file.']);
    exit;
}

// Delete old avatar file for this user (cleanup)
foreach (glob($destDir . 'avatar_' . $userId . '_*') as $old) {
    if ($old !== $destPath) @unlink($old);
}

$avatarUrl = 'assets/uploads/avatars/' . $filename;

//ATTENTION
// Save URL to MongoDB profile
if (!saveProfile($userId, ['avatar_url' => $avatarUrl])) {
    // Compensating transaction: file was saved to disk but MongoDB failed —
    // delete the new file so the old avatar URL in MongoDB stays valid.
    @unlink($destPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not update profile. Is MongoDB running?']);
    exit;
}

echo json_encode(['success' => true, 'avatar_url' => $avatarUrl]);
