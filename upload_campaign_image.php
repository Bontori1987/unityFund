<?php
session_start();
header('Content-Type: application/json');

require_once '../includes/auth.php';
require_once '../includes/mongo.php';
require_once '../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isOrganizer()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Organizer or admin access required']);
    exit;
}

if (!extension_loaded('gd') || !function_exists('imagescale')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'PHP GD extension is required for campaign image resizing.']);
    exit;
}

$campId = (int)($_POST['camp_id'] ?? 0);
if ($campId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid campaign ID.']);
    exit;
}

try {
    if (isAdmin()) {
        $chk = $conn->prepare('SELECT CampID FROM Campaigns WHERE CampID = ?');
        $chk->execute([$campId]);
    } else {
        $chk = $conn->prepare('SELECT CampID FROM Campaigns WHERE CampID = ? AND HostID = ?');
        $chk->execute([$campId, (int)currentUser()['id']]);
    }
    if (!$chk->fetch()) {
        echo json_encode(['success' => false, 'error' => 'Campaign not found or access denied.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not verify campaign.']);
    exit;
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    $errors = [
        UPLOAD_ERR_INI_SIZE  => 'File too large (server limit).',
        UPLOAD_ERR_FORM_SIZE => 'File too large.',
        UPLOAD_ERR_PARTIAL   => 'Upload incomplete.',
        UPLOAD_ERR_NO_FILE   => 'No file selected.',
    ];
    $code = $_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => $errors[$code] ?? 'Upload failed.']);
    exit;
}

$file = $_FILES['image'];
if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'Campaign image must be under 2 MB.']);
    exit;
}

$allowed = ['image/jpeg', 'image/png', 'image/webp'];
$mime = mime_content_type($file['tmp_name']);
if (!in_array($mime, $allowed, true)) {
    echo json_encode(['success' => false, 'error' => 'Only JPG, PNG, or WebP images are allowed.']);
    exit;
}

function campaignSourceImage(string $path, string $mime) {
    return match ($mime) {
        'image/jpeg' => imagecreatefromjpeg($path),
        'image/png'  => imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : false,
        default      => false,
    };
}

function saveCoverJpeg($src, int $targetW, int $targetH, string $destPath): bool {
    $srcW = imagesx($src);
    $srcH = imagesy($src);
    if ($srcW <= 0 || $srcH <= 0) return false;

    $scale = max($targetW / $srcW, $targetH / $srcH);
    $resizeW = (int)ceil($srcW * $scale);
    $resizeH = (int)ceil($srcH * $scale);
    $resized = imagescale($src, $resizeW, $resizeH);
    if (!$resized) return false;

    $canvas = imagecreatetruecolor($targetW, $targetH);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefill($canvas, 0, 0, $white);

    $srcX = max(0, (int)(($resizeW - $targetW) / 2));
    $srcY = max(0, (int)(($resizeH - $targetH) / 2));
    imagecopy($canvas, $resized, 0, 0, $srcX, $srcY, $targetW, $targetH);
    imagedestroy($resized);

    $ok = imagejpeg($canvas, $destPath, 88);
    imagedestroy($canvas);
    return $ok;
}

$src = campaignSourceImage($file['tmp_name'], $mime);
if (!$src) {
    echo json_encode(['success' => false, 'error' => 'Could not read image.']);
    exit;
}

$destDir = dirname(__DIR__) . '/assets/uploads/campaigns/';
if (!is_dir($destDir) && !mkdir($destDir, 0755, true)) {
    imagedestroy($src);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not prepare upload folder.']);
    exit;
}

$ts = time();
$bannerName = 'camp_' . $campId . '_banner_' . $ts . '.jpg';
$thumbName = 'camp_' . $campId . '_thumb_' . $ts . '.jpg';
$bannerPath = $destDir . $bannerName;
$thumbPath = $destDir . $thumbName;

$bannerOk = saveCoverJpeg($src, 1200, 630, $bannerPath);
$thumbOk = saveCoverJpeg($src, 400, 300, $thumbPath);
imagedestroy($src);

if (!$bannerOk || !$thumbOk) {
    @unlink($bannerPath);
    @unlink($thumbPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not resize campaign image.']);
    exit;
}

foreach (glob($destDir . 'camp_' . $campId . '_banner_*.jpg') as $old) {
    if ($old !== $bannerPath) @unlink($old);
}
foreach (glob($destDir . 'camp_' . $campId . '_thumb_*.jpg') as $old) {
    if ($old !== $thumbPath) @unlink($old);
}

$bannerUrl = 'assets/uploads/campaigns/' . $bannerName;
$thumbUrl = 'assets/uploads/campaigns/' . $thumbName;
//ATENTION
if (!saveCampaignImages($campId, $bannerUrl, $thumbUrl)) {
    // Compensating transaction: files were written to disk but MongoDB failed —
    // delete both resized files so they do not become unreferenced orphans.
    @unlink($bannerPath);
    @unlink($thumbPath);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not save image paths. Is MongoDB running?']);
    exit;
}

echo json_encode([
    'success'   => true,
    'banner'    => $bannerUrl,
    'thumbnail' => $thumbUrl,
]);
