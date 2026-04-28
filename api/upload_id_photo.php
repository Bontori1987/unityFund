<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/auth.php';

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

if (!extension_loaded('gd') || !function_exists('imagescale')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PHP GD extension is required for ID photo resizing.']);
    exit;
}

function idUploadError(array $file): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) return null;
    $errors = [
        UPLOAD_ERR_INI_SIZE  => 'File too large (server limit).',
        UPLOAD_ERR_FORM_SIZE => 'File too large.',
        UPLOAD_ERR_PARTIAL   => 'Upload incomplete.',
        UPLOAD_ERR_NO_FILE   => 'No file selected.',
    ];
    return $errors[$file['error'] ?? UPLOAD_ERR_NO_FILE] ?? 'Upload failed.';
}

function sourceImageFromMime(string $path, string $mime) {
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png'  => imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default      => false,
    };
}

function saveIdPhoto(array $file, int $userId, string $side): array {
    $error = idUploadError($file);
    if ($error) return ['success' => false, 'error' => $error];

    $maxBytes = 5 * 1024 * 1024;
    if (($file['size'] ?? 0) > $maxBytes) {
        return ['success' => false, 'error' => 'ID photo must be under 5 MB.'];
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) {
        return ['success' => false, 'error' => 'Only JPG, PNG, or WebP ID photos are allowed.'];
    }

    $src = sourceImageFromMime($file['tmp_name'], $mime);
    if (!$src) {
        return ['success' => false, 'error' => 'Could not read image.'];
    }

    $width = imagesx($src);
    $height = imagesy($src);
    if ($width <= 0 || $height <= 0) {
        imagedestroy($src);
        return ['success' => false, 'error' => 'Invalid image dimensions.'];
    }

    $targetWidth = min(1500, $width);
    $targetHeight = (int)round($height * ($targetWidth / $width));
    $scaled = imagescale($src, $targetWidth, $targetHeight);
    imagedestroy($src);
    if (!$scaled) {
        return ['success' => false, 'error' => 'Could not resize image.'];
    }

    $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);
    imagecopy($canvas, $scaled, 0, 0, 0, 0, $targetWidth, $targetHeight);
    imagedestroy($scaled);

    $destDir = dirname(__DIR__) . '/assets/uploads/ids/';
    if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
        imagedestroy($canvas);
        return ['success' => false, 'error' => 'Could not prepare upload folder.'];
    }

    $filename = 'id_' . $userId . '_' . $side . '_' . time() . '.jpg';
    $destPath = $destDir . $filename;
    if (!imagejpeg($canvas, $destPath, 85)) {
        imagedestroy($canvas);
        return ['success' => false, 'error' => 'Could not save image.'];
    }
    imagedestroy($canvas);

    foreach (glob($destDir . 'id_' . $userId . '_' . $side . '_*.jpg') as $old) {
        if ($old !== $destPath) @unlink($old);
    }

    return [
        'success' => true,
        'path'    => 'assets/uploads/ids/' . $filename,
    ];
}

$userId = (int)currentUser()['id'];
$paths = [];

foreach (['front', 'back'] as $side) {
    if (empty($_FILES[$side])) continue;
    $result = saveIdPhoto($_FILES[$side], $userId, $side);
    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }
    $key = 'id_image_' . $side;
    $paths[$key] = $result['path'];
    $_SESSION['organizer_' . $key] = $result['path'];
}

if (empty($paths)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No ID photo files were uploaded.']);
    exit;
}

echo json_encode(array_merge(['success' => true], $paths));
