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
        'decision_id' => $d->decision_id ?? '',
        'camp_id'     => $d->camp_id     ?? 0,
        'camp_title'  => $d->camp_title  ?? '',
        'change_type' => $d->change_type ?? '',
        'message'     => $d->message     ?? '',
        'read'        => (bool)($d->read ?? false),
        'from_user_id'=> $fromId,
        'from_name'   => $senderNames[$fromId] ?? 'System',
        'created_at'  => isset($d->created_at) ? formatMongoDate($d->created_at) : '',
    ];
}

$roleDecisionMap = [];
try {
    $roleDecisionMap = getRoleChangeDecisionsMap(array_column($items, 'decision_id'));
} catch (Exception $e) {
    $roleDecisionMap = [];
}

$campaignImages = [];
try {
    $campaignImages = getCampaignDetailsMap(array_column($items, 'camp_id'));
} catch (Exception $e) {
    $campaignImages = [];
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
                'role_change'    => 'bi-shield-lock text-danger',
                'role_appeal_result' => 'bi-arrow-repeat text-primary',
                default          => 'bi-bell text-muted',
            };
            $unreadBorder = match($n['type']) {
                'role_change' => 'border-left:3px solid #ef4444!important;background:#fef2f2;',
                'role_appeal_result' => 'border-left:3px solid #3b82f6!important;background:#eff6ff;',
                default       => 'border-left:3px solid #f59e0b!important;background:#fffbeb;',
            };
            $changeLabel = match($n['change_type']) {
                'name' => ['bg-info',           'Name change'],
                'goal' => ['bg-warning text-dark','Goal change'],
                default => ['bg-secondary',     ucfirst($n['change_type'])],
            };
            $decisionMeta = !empty($n['decision_id']) ? ($roleDecisionMap[(string)$n['decision_id']] ?? null) : null;
        ?>
        <div class="card border-0 shadow-sm notification-item <?= $isRead ? '' : 'unread' ?>"
             id="notif-<?= htmlspecialchars($n['id']) ?>"
             style="<?= $isRead ? '' : $unreadBorder ?>">
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
                            <?php $thumb = $campaignImages[(int)$n['camp_id']]['thumbnail'] ?? ''; ?>
                            <?php if ($thumb): ?>
                            <img src="<?= htmlspecialchars($thumb) ?>"
                                 alt="<?= htmlspecialchars($n['camp_title']) ?>"
                                 style="width:34px;height:26px;object-fit:cover;border-radius:4px;">
                            <?php endif; ?>
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
                        <?php if (($n['type'] ?? '') === 'role_change' && $decisionMeta): ?>
                        <div class="mt-2">
                            <div class="small text-muted mb-2">
                                Appeal status:
                                <strong><?= htmlspecialchars(ucfirst($decisionMeta['appeal_status'] ?? 'none')) ?></strong>
                                <?php if (!empty($decisionMeta['appeal_review_notes']) && ($decisionMeta['appeal_status'] ?? '') !== 'pending'): ?>
                                &middot; <?= htmlspecialchars($decisionMeta['appeal_review_notes']) ?>
                                <?php endif; ?>
                            </div>
                            <?php if (($decisionMeta['appeal_status'] ?? 'none') === 'none' || ($decisionMeta['appeal_status'] ?? 'none') === 'rejected'): ?>
                            <div class="d-flex gap-2 flex-wrap">
                                <textarea class="form-control form-control-sm role-appeal-message"
                                          id="role-appeal-msg-<?= htmlspecialchars($n['decision_id']) ?>"
                                          rows="2"
                                          placeholder="Explain why the role decision should be reconsidered."></textarea>
                            </div>
                            <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
                                <button class="btn btn-sm btn-outline-secondary"
                                        id="role-appeal-send-otp-<?= htmlspecialchars($n['decision_id']) ?>"
                                        onclick="sendRoleAppealOtp('<?= htmlspecialchars($n['decision_id']) ?>', this)">
                                    Send Gmail OTP
                                </button>
                                <input type="text"
                                       class="form-control form-control-sm"
                                       id="role-appeal-otp-<?= htmlspecialchars($n['decision_id']) ?>"
                                       inputmode="numeric"
                                       maxlength="6"
                                       placeholder="Enter 6-digit OTP"
                                       style="max-width:170px;">
                                <button class="btn btn-sm btn-outline-primary"
                                        onclick="submitRoleAppeal('<?= htmlspecialchars($n['decision_id']) ?>', this)">
                                    Verify and appeal
                                </button>
                            </div>
                            <input type="hidden"
                                   id="role-appeal-challenge-<?= htmlspecialchars($n['decision_id']) ?>"
                                   value="">
                            <div class="small text-muted mt-1" id="role-appeal-status-<?= htmlspecialchars($n['decision_id']) ?>">
                                Send a Gmail OTP before submitting the appeal.
                            </div>
                            <?php elseif (($decisionMeta['appeal_status'] ?? '') === 'pending'): ?>
                            <div class="small text-warning">An appeal is already pending review.</div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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

async function submitRoleAppeal(decisionId, btn) {
    const textarea = document.getElementById('role-appeal-msg-' + decisionId);
    const message = textarea ? textarea.value.trim() : '';
    const otpInput = document.getElementById('role-appeal-otp-' + decisionId);
    const challengeInput = document.getElementById('role-appeal-challenge-' + decisionId);
    const statusEl = document.getElementById('role-appeal-status-' + decisionId);
    const code = otpInput ? otpInput.value.replace(/\D+/g, '').slice(0, 6) : '';
    const challengeId = challengeInput ? challengeInput.value.trim() : '';
    if (!message) {
        textarea?.focus();
        return;
    }
    if (!challengeId) {
        statusEl && (statusEl.textContent = 'Request a Gmail OTP first.');
        return;
    }
    if (code.length !== 6) {
        otpInput?.focus();
        statusEl && (statusEl.textContent = 'Enter the 6-digit Gmail OTP.');
        return;
    }
    btn.disabled = true;
    if (statusEl) statusEl.textContent = 'Verifying Gmail OTP…';
    const verify = await fetch('api/verify_email_otp.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ purpose: 'role_appeal_auth', challenge_id: challengeId, code })
    }).then(r => r.json()).catch(() => ({ success: false, error: 'Network error' }));
    if (!verify.success) {
        alert('Error: ' + (verify.error || 'Could not verify OTP'));
        btn.disabled = false;
        if (statusEl) statusEl.textContent = verify.error || 'OTP verification failed.';
        return;
    }
    if (statusEl) statusEl.textContent = 'Submitting appeal…';
    const data = await fetch('api/role_change_appeal.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'submit', decision_id: decisionId, message, otp_challenge_id: challengeId })
    }).then(r => r.json()).catch(() => ({ success: false, error: 'Network error' }));
    if (data.success) {
        location.reload();
    } else {
        alert('Error: ' + (data.error || 'Could not submit appeal'));
        btn.disabled = false;
        if (statusEl) statusEl.textContent = data.error || 'Could not submit appeal.';
    }
}

async function sendRoleAppealOtp(decisionId, btn) {
    btn.disabled = true;
    const statusEl = document.getElementById('role-appeal-status-' + decisionId);
    const challengeInput = document.getElementById('role-appeal-challenge-' + decisionId);
    if (statusEl) statusEl.textContent = 'Sending Gmail OTP…';
    const data = await fetch('api/send_email_otp.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ purpose: 'role_appeal_auth', decision_id: decisionId })
    }).then(r => r.json()).catch(() => ({ success: false, error: 'Network error' }));
    if (data.success) {
        if (challengeInput) challengeInput.value = data.challenge_id || '';
        if (statusEl) statusEl.textContent = 'OTP sent to your Gmail. Enter it here, then submit the appeal.';
    } else {
        alert('Error: ' + (data.error || 'Could not send OTP'));
        if (statusEl) statusEl.textContent = data.error || 'Could not send OTP.';
    }
    btn.disabled = false;
}
</script>

<?php require_once 'includes/footer.php'; ?>
