<?php
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

if (isLoggedIn()) {
    header('Location: donate.php');
    exit;
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username       = trim($_POST['username'] ?? '');
    $email          = trim($_POST['email']    ?? '');
    $password       = $_POST['password']      ?? '';
    $confirm        = $_POST['confirm']       ?? '';
    $wantsOrganizer = (($_POST['role'] ?? 'donor') === 'organizer');
    $dbRole         = $wantsOrganizer ? 'pending_organizer' : 'donor';

    if (!$username || !$email || !$password) {
        $error = 'All fields are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $chk = $conn->prepare("SELECT UserID FROM Users WHERE Email = ?");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'An account with that email already exists.';
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $conn->prepare(
                    "INSERT INTO Users (Username, Email, Password, Role) VALUES (?, ?, ?, ?)"
                )->execute([$username, $email, $hash, $dbRole]);

                // Get new user ID and seed MongoDB profile
                $newId = (int)$conn->query("SELECT SCOPE_IDENTITY() AS id")->fetchColumn();
                if ($newId > 0) seedProfile($newId);

                $success = $wantsOrganizer
                    ? 'Application submitted! An admin will review your organizer request. You can log in and donate while waiting.'
                    : 'Account created! You can now sign in.';
            }
        } catch (PDOException $e) {
            $error = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — UnityFund</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
</head>
<body>
<div class="auth-page">
    <div class="auth-card">

        <div class="auth-logo">
            <img src="assets/logo.jpg" alt="UnityFund"
                 style="height:56px;width:auto;border-radius:8px;object-fit:contain;">
            <p class="text-muted small mt-2">Create your account</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success py-2 mb-3">
            <i class="bi bi-check-circle me-1"></i><strong><?= htmlspecialchars($success) ?></strong><br>
            <a href="login.php" class="fw-semibold">Sign in now &rarr;</a>
        </div>
        <?php endif; ?>

        <?php if (!$success): ?>
        <form method="POST">

            <!-- Role picker -->
            <div class="mb-3">
                <label class="form-label fw-semibold small">Account type</label>
                <div class="d-flex gap-2">
                    <div class="role-option flex-fill">
                        <input type="radio" id="role_donor" name="role" value="donor"
                               <?= ($_POST['role'] ?? 'donor') !== 'organizer' ? 'checked' : '' ?>>
                        <label for="role_donor">
                            <span><i class="bi bi-heart" style="font-size:1.5rem;"></i></span>
                            Donor
                        </label>
                    </div>
                    <div class="role-option flex-fill">
                        <input type="radio" id="role_org" name="role" value="organizer"
                               <?= ($_POST['role'] ?? '') === 'organizer' ? 'checked' : '' ?>>
                        <label for="role_org">
                            <span><i class="bi bi-flag" style="font-size:1.5rem;"></i></span>
                            Organizer
                        </label>
                    </div>
                </div>
                <div id="org-notice" class="mt-2 p-2 rounded small text-muted"
                     style="background:#f0faf5;border:1px solid #c3e6cb;display:none;">
                    <i class="bi bi-info-circle me-1 text-success"></i>
                    Organizer accounts need admin approval before creating campaigns.
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Display name</label>
                <input type="text" name="username" class="form-control"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       placeholder="Your name" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="you@example.com" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold small">
                    Password <span class="text-muted fw-normal">(min 6 chars)</span>
                </label>
                <input type="password" name="password" class="form-control"
                       placeholder="Choose a password" required>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Confirm password</label>
                <input type="password" name="confirm" class="form-control"
                       placeholder="Repeat password" required>
            </div>

            <button type="submit" class="btn btn-success w-100 fw-semibold py-2">
                Create account
            </button>
        </form>
        <?php endif; ?>

        <p class="text-center text-muted small mt-3 mb-0">
            Already have an account?
            <a href="login.php" class="text-success fw-semibold text-decoration-none">Sign in</a>
        </p>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('input[name=role]').forEach(r => {
    r.addEventListener('change', () => {
        document.getElementById('org-notice').style.display =
            document.querySelector('input[name=role]:checked').value === 'organizer' ? 'block' : 'none';
    });
});
// Show on load if organizer pre-selected
if (document.querySelector('input[name=role]:checked')?.value === 'organizer') {
    document.getElementById('org-notice').style.display = 'block';
}
</script>
</body>
</html>
