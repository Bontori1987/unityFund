<?php
$pageTitle = 'Inbox';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

requireLogin();

$userId        = (int)currentUser()['id'];
$notifications = getNotifications($userId, false); // all, not just unread

// Bulk-fetch sender usernames from MS SQL
$senderNames = [];
$senderIds   = array_unique(array_filter(array_column($notifications, 'from_user_id')));
// getNotifications doesn't expose from_user_id — re-fetch raw docs for inbox
$rawDocs = mongoFind('notifications', ['to_user_id' => $userId], [
    'sort'  => ['created_at' => -1],
    'limit' => 200,
]);

// Build full notification list with sender info
$items = [];
$fromIds = [];
foreach ($rawDocs as $d) {
    $fromIds[] = (int)($d->from_user_id ?? 0);
}
$fromIds = array_unique(array_filter($fromIds));

if (!empty($fromIds)) {
    try {
        $ph   = implode(',', array_fill(0, count($fromIds), '?'));
        $stmt = $conn->prepare("SELECT UserID, Username FROM Users WHERE UserID IN ($ph)");
        $stmt->execute(array_values($fromIds));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $senderNames[$row['UserID']] = $row['Username'];
        }
    } catch (PDOException $e) {}
}

foreach ($rawDocs as $d) {
    $fromId = (int)($d->from_user_id ?? 0);
    $items[] = [
        'id'          => (string)($d->_id ?? ''),
        'type'        => $d->type        ?? 'notification',
        'camp_id'     => $d->camp_id     ?? 0,
        'camp_title'  => $d->camp_title  ?? '',
        'change_type' => $d->change_type ?? '',
        'message'     => $d->message     ?? '',
        'read'        => (bool)($d->read ?? false),
        'from_user_id'=> $fromId,
        'from_name'   => $senderNames[$fromId] ?? 'System',
        'created_at'  => isset($d->created_at)
                            ? $d->created_at->toDateTime()->format('M j, Y g:i A')
                            : '',
    ];
}

$unreadCount = count(array_filter($items, fn($n) => !$n['read']));

require_once 'includes/header.php';
?>

<div class="container py-5" style="max-width:780px;">

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1">
                <i class="bi bi-bell me-2 text-success"></i>Inbox
            </h1>
            <p class="text-muted mb-0 small">
                <?= $unreadCount > 0
                    ? "$unreadCount unread message" . ($unreadCount > 1 ? 's' : '')
                    : 'All caught up' ?>
            </p>
        </div>
        <?php if ($unreadCount > 0): ?>
        <button class="btn btn-outline-secondary btn-sm" onclick="markAll()">
            <i class="bi bi-check2-all me-1"></i>Mark all as read
        </button>
        <?php endif; ?>
    </div>

    <?php if (empty($items)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-bell-slash d-block" style="font-size:2.5rem;opacity:.35;"></i>
            <p class="mt-3 mb-0 fw-semibold">No notifications yet</p>
            <p class="small">You'll see messages from admins and system alerts here.</p>
        </div>
    </div>
    <?php else: ?>

    <div class="d-flex flex-column gap-2" id="notification-list">
        <?php foreach ($items as $n):
            $isRead   = $n['read'];
            $typeIcon = match($n['type']) {
                'change_request' => 'bi-send text-warning',
                default          => 'bi-bell text-muted',
            };
            $changeLabel = match($n['change_type']) {
                'name' => ['bg-info',           'Name change'],
                'goal' => ['bg-warning text-dark','Goal change'],
                default => ['bg-secondary',     ucfirst($n['change_type'])],
            };
        ?>
        <div class="card border-0 shadow-sm notification-item <?= $isRead ? '' : 'unread' ?>"
             id="notif-<?= htmlspecialchars($n['id']) ?>"
             style="<?= $isRead ? '' : 'border-left:3px solid #f59e0b!important;background:#fffbeb;' ?>">
            <div class="card-body py-3 px-4">
                <div class="d-flex gap-3 align-items-start">

                    <!-- Icon -->
                    <div class="flex-shrink-0 mt-1">
                        <i class="bi <?= $typeIcon ?>" style="font-size:1.25rem;"></i>
                    </div>

                    <!-- Content -->
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                            <?php if ($n['change_type']): ?>
                            <span class="badge <?= $changeLabel[0] ?>" style="font-size:.65rem;">
                                <?= $changeLabel[1] ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($n['camp_title']): ?>
                            <span class="fw-semibold small">
                                <a href="partner/campaign/campaign-detail.php?id=<?= $n['camp_id'] ?>"
                                   class="text-success text-decoration-none">
                                    <?= htmlspecialchars($n['camp_title']) ?>
                                </a>
                            </span>
                            <?php endif; ?>
                            <?php if (!$isRead): ?>
                            <span class="badge bg-warning text-dark" style="font-size:.6rem;">New</span>
                            <?php endif; ?>
                        </div>
                        <p class="mb-1 small"><?= nl2br(htmlspecialchars($n['message'])) ?></p>
                        <div class="text-muted" style="font-size:.75rem;">
                            From <strong><?= htmlspecialchars($n['from_name']) ?></strong>
                            &middot; <?= $n['created_at'] ?>
                        </div>
                    </div>

                    <!-- Mark read button -->
                    <?php if (!$isRead): ?>
                    <button class="btn btn-sm btn-link text-muted p-0 flex-shrink-0"
                            title="Mark as read"
                            onclick="markOne('<?= htmlspecialchars($n['id']) ?>', this)">
                        <i class="bi bi-check2-circle" style="font-size:1.1rem;"></i>
                    </button>
                    <?php endif; ?>

                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php endif; ?>
</div>

<script>
async function markOne(id, btn) {
    await fetch('api/mark_notifications_read.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id })
    });
    const card = document.getElementById('notif-' + id);
    if (card) {
        card.classList.remove('unread');
        card.style.borderLeft  = '';
        card.style.background  = '';
        card.querySelector('.badge.bg-warning.text-dark')?.remove();
        btn.remove();
    }
    updateUnreadLabel();
}

async function markAll() {
    await fetch('api/mark_notifications_read.php', { method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({}) });
    location.reload();
}

function updateUnreadLabel() {
    const unread = document.querySelectorAll('.notification-item.unread').length;
    const sub = document.querySelector('h1 + p');
    if (sub) sub.textContent = unread > 0 ? `${unread} unread message${unread > 1 ? 's' : ''}` : 'All caught up';
}
</script>

<?php require_once 'includes/footer.php'; ?>
