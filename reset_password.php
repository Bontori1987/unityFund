<?php
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$tokenString = trim($_GET['token'] ?? $_POST['token'] ?? '');
$parts = explode('.', $tokenString, 2);
$selector = $parts[0] ?? '';
$token = $parts[1] ?? '';
$tokenData = validatePasswordResetToken($selector, $token);

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm'] ?? '';

    if (!$tokenData) {
        $error = 'This reset link is invalid or expired.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $consumed = consumePasswordResetToken($selector, $token);
        if (!$consumed) {
            $error = 'This reset link is no longer valid.';
        } else {
            try {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $conn->prepare("UPDATE Users SET Password = ? WHERE UserID = ?")->execute([$hash, (int)$consumed['user_id']]);
                $success = 'Password updated. You can now sign in.';
            } catch (PDOException $e) {
                $error = 'Could not update the password.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - UnityFund</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">
        <div class="auth-logo">
            <img src="assets/logo.jpg" alt="UnityFund" style="height:56px;width:auto;border-radius:8px;object-fit:contain;">
            <p class="text-muted small mt-2">Choose a new password</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success py-2 small mb-3">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?><br>
            <a href="login.php" class="fw-semibold">Sign in now</a>
        </div>
        <?php elseif ($tokenData): ?>
        <form method="POST">
            <input type="hidden" name="token" value="<?= htmlspecialchars($tokenString) ?>">
            <div class="mb-3">
                <label class="form-label fw-semibold small">New password</label>
                <input type="password" name="password" class="form-control" placeholder="Minimum 6 characters" required>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Confirm password</label>
                <input type="password" name="confirm" class="form-control" placeholder="Repeat password" required>
            </div>
            <button type="submit" class="btn btn-success w-100 fw-semibold py-2">Update password</button>
        </form>
        <?php else: ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="bi bi-exclamation-triangle me-1"></i>This reset link is invalid or expired.
        </div>
        <a href="forgot_password.php" class="btn btn-outline-success w-100 fw-semibold">Request a new link</a>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
