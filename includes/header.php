<?php
// Usage: set $pageTitle and $basePath before including this file.
// $basePath = ''       for root pages  (index.php, donate.php …)
// $basePath = '../../' for partner/*/* pages
$basePath  = $basePath  ?? '';
$pageTitle = $pageTitle ?? 'UnityFund';

$_h_user  = isLoggedIn() ? currentUser() : null;
$_h_role  = $_h_user['role'] ?? 'guest';

// Load MongoDB profile for avatar + unread count (silently skip if Mongo unavailable)
$_h_avatar  = '';
$_h_unread  = 0;
if ($_h_user) {
    try {
        if (!function_exists('getProfile')) require_once __DIR__ . '/mongo.php';
        $_h_profile = getProfile((int)$_h_user['id']);
        $_h_avatar  = $_h_profile['avatar_url'] ?? '';
        $_h_unread  = countUnreadNotifications((int)$_h_user['id']);
    } catch (Exception $e) {}
}

$_roleBadge = [
    'donor'             => ['bg-primary',          'Donor'],
    'pending_organizer' => ['bg-warning text-dark', 'Pending'],
    'organizer'         => ['bg-purple',            'Organizer'],
    'admin'             => ['bg-danger',            'Admin'],
][$_h_role] ?? ['bg-secondary', ucfirst($_h_role)];

$_cur = basename($_SERVER['PHP_SELF']);
function _nav_active(string $file, string $cur): string {
    return $cur === $file ? 'active fw-semibold' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — UnityFund</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="<?= $basePath ?>assets/css/app.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container">

        <a class="navbar-brand d-flex align-items-center gap-2"
           href="<?= $basePath ?>index.php">
            <img src="<?= $basePath ?>assets/logo.jpg" alt="UnityFund"
                 style="height:108px;width:auto;border-radius:6px;object-fit:contain;">
        </a>

        <button class="navbar-toggler border-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#navMain">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navMain">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= _nav_active('index.php', $_cur) ?>"
                       href="<?= $basePath ?>index.php">Discover</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= _nav_active('top_donors.php', $_cur) ?>"
                       href="<?= $basePath ?>top_donors.php">
                        <i class="bi bi-trophy me-1"></i>Top Donors
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-2">

                <?php if ($_h_user): ?>

                    <?php if (canDonate()): ?>
                    <a href="<?= $basePath ?>donate.php"
                       class="btn btn-success btn-sm px-3 d-none d-md-inline-flex align-items-center gap-1">
                        <i class="bi bi-heart"></i> Donate
                    </a>
                    <?php endif; ?>

                    <!-- Inbox bell -->
                    <a href="<?= $basePath ?>inbox.php"
                       class="btn btn-light btn-sm border position-relative"
                       title="Inbox"
                       style="width:34px;height:34px;padding:0;display:flex;align-items:center;justify-content:center;">
                        <i class="bi bi-bell text-muted" style="font-size:1rem;"></i>
                        <?php if ($_h_unread > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                              style="font-size:.55rem;padding:2px 5px;">
                            <?= $_h_unread > 99 ? '99+' : $_h_unread ?>
                        </span>
                        <?php endif; ?>
                    </a>

                    <div class="dropdown">
                        <button class="btn btn-light btn-sm dropdown-toggle d-flex align-items-center gap-2 border"
                                data-bs-toggle="dropdown" aria-expanded="false">
                            <?php if ($_h_avatar): ?>
                                <img src="<?= htmlspecialchars($_h_avatar) ?>" alt="avatar"
                                     class="rounded-circle" style="width:22px;height:22px;object-fit:cover;">
                            <?php else: ?>
                                <i class="bi bi-person-circle text-muted"></i>
                            <?php endif; ?>
                            <span class="d-none d-sm-inline">
                                <?= htmlspecialchars($_h_user['username']) ?>
                            </span>
                            <span class="badge <?= $_roleBadge[0] ?> ms-1" style="font-size:.65rem;">
                                <?= $_roleBadge[1] ?>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow border-0">
                            <?php if (canDonate()): ?>
                            <li>
                                <a class="dropdown-item py-2" href="<?= $basePath ?>donate.php">
                                    <i class="bi bi-heart me-2 text-success"></i>Donate
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item py-2" href="<?= $basePath ?>receipts.php">
                                    <i class="bi bi-receipt me-2 text-muted"></i>
                                    <?= isAdmin() ? 'All Receipts' : 'My Receipts' ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (isOrganizer() || isAdmin()): ?>
                            <li>
                                <a class="dropdown-item py-2" href="<?= $basePath ?>my_campaigns.php">
                                    <i class="bi bi-grid me-2 text-muted"></i>
                                    <?= isAdmin() ? 'Manage Campaigns' : 'My Campaigns' ?>
                                </a>
                            </li>
                            <?php endif; ?>
                            <?php if (isPendingOrganizer()): ?>
                            <li>
                                <span class="dropdown-item py-2 text-warning">
                                    <i class="bi bi-clock me-2"></i>Pending organizer approval
                                </span>
                            </li>
                            <?php endif; ?>
                            <li>
                                <a class="dropdown-item py-2" href="<?= $basePath ?>profile.php">
                                    <i class="bi bi-person me-2 text-muted"></i>My Profile
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item py-2 d-flex align-items-center justify-content-between"
                                   href="<?= $basePath ?>inbox.php">
                                    <span>
                                        <i class="bi bi-bell me-2 text-muted"></i>Inbox
                                    </span>
                                    <?php if ($_h_unread > 0): ?>
                                    <span class="badge bg-danger rounded-pill" style="font-size:.6rem;">
                                        <?= $_h_unread > 99 ? '99+' : $_h_unread ?>
                                    </span>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li>
                                <a class="dropdown-item py-2 text-danger" href="<?= $basePath ?>logout.php">
                                    <i class="bi bi-box-arrow-right me-2"></i>Sign out
                                </a>
                            </li>
                        </ul>
                    </div>

                <?php else: ?>
                    <a href="<?= $basePath ?>login.php"
                       class="btn btn-outline-success btn-sm">Sign in</a>
                    <a href="<?= $basePath ?>register.php"
                       class="btn btn-success btn-sm">Get started</a>
                <?php endif; ?>

            </div>
        </div>
    </div>
</nav>
