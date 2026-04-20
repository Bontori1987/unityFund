<?php
require_once 'includes/auth.php';
require_once 'db.php';

if (isLoggedIn()) {
    $r = currentRole();
    header('Location: ' . ($r === 'organizer' || $r === 'admin' ? 'my_campaigns.php' : 'donate.php'));
    exit;
}

$error    = '';
$redirect = htmlspecialchars($_GET['redirect'] ?? $_POST['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $error = 'Please enter both email and password.';
    } else {
        try {
            $stmt = $conn->prepare(
                "SELECT UserID, Username, Password, Role FROM Users WHERE Email = ?"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && password_verify($password, $user['Password'])) {
                $_SESSION['user_id']  = $user['UserID'];
                $_SESSION['username'] = $user['Username'];
                $_SESSION['role']     = $user['Role'];

                if ($redirect) {
                    header('Location: ' . $redirect);
                } elseif (in_array($user['Role'], ['organizer', 'admin'])) {
                    header('Location: my_campaigns.php');
                } else {
                    header('Location: donate.php');
                }
                exit;
            } elseif ($user && str_starts_with($user['Password'], '$2y$10$samplehash')) {
                $error = 'Test accounts not activated yet — run setup_test_accounts.php first.';
            } else {
                $error = 'Invalid email or password.';
            }
        } catch (PDOException $e) {
            $error = 'Login failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — UnityFund</title>
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
            <p class="text-muted small mt-2">Sign in to your account</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST">
            <?php if ($redirect): ?>
                <input type="hidden" name="redirect" value="<?= $redirect ?>">
            <?php endif; ?>

            <div class="mb-3">
                <label class="form-label fw-semibold small">Email</label>
                <input type="email" name="email" class="form-control"
                       value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                       placeholder="you@example.com" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Password</label>
                <input type="password" name="password" class="form-control"
                       placeholder="Your password" required>
            </div>
            <button type="submit" class="btn btn-success w-100 fw-semibold py-2">
                Sign In
            </button>
        </form>

        <p class="text-center text-muted small mt-3 mb-0">
            No account?
            <a href="register.php" class="text-success fw-semibold text-decoration-none">Register here</a>
        </p>

        <div class="text-center mt-3 p-2 rounded" style="background:#fffbea;border:1px solid #ffe082;font-size:.8rem;color:#6d4c00;">
            <i class="bi bi-gear me-1"></i>Testing?
            <a href="dev_login.php" class="fw-semibold" style="color:#6d4c00;">Dev Switcher</a>
            &nbsp;·&nbsp;
            <a href="setup_test_accounts.php" style="color:#6d4c00;">Setup accounts</a>
        </div>

    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
