<?php
// Requires auth.php to be loaded first
$_nav_user = currentUser();
$_nav_role = $_nav_user['role'];
$_nav_name = htmlspecialchars($_nav_user['username']);

$_role_labels = [
    'guest'              => ['label' => 'Guest',             'color' => '#757575'],
    'donor'              => ['label' => 'Donor',             'color' => '#1565c0'],
    'pending_organizer'  => ['label' => 'Pending Approval',  'color' => '#e65100'],
    'organizer'          => ['label' => 'Organizer',         'color' => '#6a1b9a'],
    'admin'              => ['label' => 'Admin',             'color' => '#b71c1c'],
];
$_badge = $_role_labels[$_nav_role] ?? $_role_labels['guest'];
?>
<nav class="navbar">
    <a href="index.php" class="nav-brand">UnityFund</a>

    <div class="nav-links">
        <!-- Pages visible to everyone -->
        <a href="top_donors.php"   <?= basename($_SERVER['PHP_SELF']) === 'top_donors.php'   ? 'class="active"' : '' ?>>Top Donors</a>
        <a href="running_total.php"<?= basename($_SERVER['PHP_SELF']) === 'running_total.php' ? 'class="active"' : '' ?>>Progress</a>

        <?php if (isLoggedIn()): ?>
            <!-- Donors, Organizers, Admins can donate -->
            <?php if (canDonate()): ?>
            <a href="donate.php" <?= basename($_SERVER['PHP_SELF']) === 'donate.php' ? 'class="active"' : '' ?>>Donate</a>
            <?php endif; ?>

            <!-- Donors & above see their receipts -->
            <a href="receipts.php" <?= basename($_SERVER['PHP_SELF']) === 'receipts.php' ? 'class="active"' : '' ?>>
                <?= isAdmin() ? 'All Receipts' : 'My Receipts' ?>
            </a>

            <!-- Organizer: my campaigns link -->
            <?php if (isOrganizer() && !isAdmin()): ?>
            <a href="my_campaigns.php" <?= basename($_SERVER['PHP_SELF']) === 'my_campaigns.php' ? 'class="active"' : '' ?>>My Campaigns</a>
            <?php endif; ?>

            <!-- Admin-only links -->
            <?php if (isAdmin()): ?>
            <a href="admin_dashboard.php" <?= basename($_SERVER['PHP_SELF']) === 'admin_dashboard.php' ? 'class="active"' : '' ?>>Dashboard</a>
            <?php endif; ?>

            <!-- Role badge + logout -->
            <span class="nav-role-badge" style="background:<?= $_badge['color'] ?>">
                <?= $_badge['label'] ?>
            </span>
            <span class="nav-username"><?= $_nav_name ?></span>
            <a href="logout.php">Logout</a>

        <?php else: ?>
            <!-- Guest: show login -->
            <a href="login.php"   <?= basename($_SERVER['PHP_SELF']) === 'login.php'    ? 'class="active"' : '' ?>>Login</a>
            <a href="register.php"<?= basename($_SERVER['PHP_SELF']) === 'register.php' ? 'class="active"' : '' ?>>Register</a>
        <?php endif; ?>

        <!-- Dev switcher shortcut (remove in prod) -->
        <a href="dev_login.php" style="opacity:.55;font-size:.8rem;">&#9881; Dev</a>
    </div>
</nav>

<style>
.nav-role-badge {
    display: inline-block;
    padding: .18rem .55rem;
    border-radius: 10px;
    font-size: .75rem;
    font-weight: 700;
    color: #fff;
    margin-left: .4rem;
    letter-spacing: .4px;
    vertical-align: middle;
}
.nav-username {
    color: rgba(255,255,255,.85);
    font-size: .9rem;
    margin-left: .1rem;
}
</style>
