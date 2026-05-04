<?php
require_once __DIR__ . '/time.php';

if (session_status() === PHP_SESSION_NONE) session_start();

function syncSessionUserFromDb(): void {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    if (!isset($_SESSION['user_id'])) return;

    try {
        require_once __DIR__ . '/../db.php';
        if (!isset($conn)) return;

        $stmt = $conn->prepare("SELECT Username, Role FROM Users WHERE UserID = ?");
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;

        $_SESSION['username'] = (string)($row['Username'] ?? ($_SESSION['username'] ?? 'Guest'));
        $_SESSION['role'] = (string)($row['Role'] ?? ($_SESSION['role'] ?? 'guest'));
    } catch (Throwable $e) {
        // Keep the existing session values if the database cannot be reached.
    }
}

function isLoggedIn(): bool {
    return isset($_SESSION['user_id']);
}

function currentRole(): string {
    syncSessionUserFromDb();
    return $_SESSION['role'] ?? 'guest';
}

function isAdmin(): bool {
    return currentRole() === 'admin';
}

function isOrganizer(): bool {
    return in_array(currentRole(), ['organizer', 'admin']);
}

function isPendingOrganizer(): bool {
    return currentRole() === 'pending_organizer';
}

function canDonate(): bool {
    // pending_organizer can donate while waiting for approval
    return in_array(currentRole(), ['donor', 'pending_organizer', 'organizer', 'admin']);
}

function requireLogin(string $back = ''): void {
    if (!isLoggedIn()) {
        $qs = $back ? '?redirect=' . urlencode($back) : '';
        header("Location: login.php$qs");
        exit;
    }
}

function requireRole(array $roles): void {
    requireLogin($_SERVER['REQUEST_URI'] ?? '');
    if (!in_array(currentRole(), $roles)) {
        header('Location: index.php?error=access_denied');
        exit;
    }
}

function currentUser(): array {
    syncSessionUserFromDb();
    return [
        'id'       => $_SESSION['user_id']  ?? null,
        'username' => $_SESSION['username'] ?? 'Guest',
        'role'     => currentRole(),
    ];
}
