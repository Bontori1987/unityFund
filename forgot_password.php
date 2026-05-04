<?php
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';
require_once 'includes/mail.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/@gmail\.com$/i', $email)) {
        $error = 'Enter the Gmail address used for your UnityFund account.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT UserID, Username, Email FROM Users WHERE Email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $token = createPasswordResetToken((int)$user['UserID'], (string)$user['Email']);
                if ($token) {
                    $resetUrl = MAIL_BASE_URL . '/reset_password.php?token=' . urlencode($token['selector'] . '.' . $token['token']);
                    sendPasswordResetEmail((string)$user['Email'], (string)$user['Username'], $resetUrl);
                }
            }
            $message = 'If that Gmail address exists in UnityFund, a reset link has been sent.';
        } catch (PDOException $e) {
            $error = 'Could not process the reset request right now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - UnityFund</title>
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
            <p class="text-muted small mt-2">Reset your password by Gmail</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
        <div class="alert alert-success py-2 small mb-3">
            <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold small">Gmail address</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="you@gmail.com" required>
            </div>
            <button type="submit" class="btn btn-success w-100 fw-semibold py-2">Send reset link</button>
        </form>

        <p class="text-center text-muted small mt-3 mb-0">
            <a href="login.php" class="text-success fw-semibold text-decoration-none">Back to sign in</a>
        </p>
    </div>
</div>
</body>
</html>
