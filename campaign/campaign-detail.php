<?php
require_once '../../includes/auth.php';
require_once '../../db.php';   // MS SQL PDO ($conn)
require_once '../../includes/mongo.php';

$isLoggedIn = isLoggedIn();
$canDonate  = canDonate();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header('Location: ../../index.php'); exit; }

try {
    $stmt = $conn->prepare(
        "SELECT c.CampID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt,
                c.HostID,
                COALESCE(u.Username, 'Unknown') AS HostName,
                COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                COUNT(DISTINCT d.DonorID) AS DonorCount
         FROM Campaigns c
         LEFT JOIN Users u ON c.HostID = u.UserID
         LEFT JOIN Donations d ON d.CampID = c.CampID
         WHERE c.CampID = ?
         GROUP BY c.CampID, c.Title, c.GoalAmt, c.Status, c.Category, c.CreatedAt, c.HostID, u.Username"
    );
    $stmt->execute([$id]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaign = null;
    $dbErr = $e->getMessage();
}

if (!$campaign) {
    http_response_code(404);
    $msg = isset($dbErr) ? htmlspecialchars($dbErr) : 'Campaign not found.';
    die('<p style="font-family:sans-serif;padding:2rem;">' . $msg . ' <a href="../../index.php">Go back</a></p>');
}

$raised = (float)$campaign['TotalRaised'];
$goal   = (float)$campaign['GoalAmt'];
$pct    = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;

// Running total over time — window function via view, chronological order
$donations = [];
try {
    $dStmt = $conn->prepare(
        "SELECT DonorID, DonorName, Amt, Time, RunningTotal, RankInCampaign
         FROM vw_DonationRunningTotal
         WHERE CampID = ?
         ORDER BY Time ASC"
    );
    $dStmt->execute([$id]);
    $donations = $dStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* view may not exist yet */ }

$topSupporters = [];
try {
    $sStmt = $conn->prepare(
        "SELECT DonorID, DonorName, Amt, RankInCampaign
         FROM vw_DonationRunningTotal
         WHERE CampID = ? AND RankInCampaign <= 3
         ORDER BY RankInCampaign, Amt DESC, Time ASC"
    );
    $sStmt->execute([$id]);
    $topSupporters = $sStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* view may not exist yet */ }

$commentThread = ['total' => 0, 'roots' => []];
try {
    $commentThread = getCampaignComments($id);
} catch (Exception $e) { /* MongoDB may be unavailable */ }

$campaignDetails = null;
try {
    $campaignDetails = getCampaignDetails($id);
} catch (Exception $e) { /* MongoDB may be unavailable */ }

function assetUrl(string $path, string $basePath): string {
    if ($path === '' || preg_match('#^https?://#', $path) || str_starts_with($path, '/')) {
        return $path;
    }
    return $basePath . $path;
}

function renderCampaignComment(array $comment, bool $isLoggedIn, int $depth = 0): void {
    $commentId = htmlspecialchars($comment['id'] ?? '');
    $username  = htmlspecialchars($comment['username'] ?? 'User');
    $body      = nl2br(htmlspecialchars($comment['body'] ?? ''));
    $createdAt = htmlspecialchars($comment['created_at'] ?? '');
    $userId    = (int)($comment['user_id'] ?? 0);
    $indent    = $depth > 0 ? 'ms-4 ps-3 border-start' : '';
?>
    <div class="comment-item <?= $indent ?> mt-3"
         id="comment-<?= $commentId ?>"
         data-comment-id="<?= $commentId ?>"
         data-depth="<?= $depth ?>">
        <div class="d-flex gap-2">
            <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center flex-shrink-0"
                 style="width:34px;height:34px;">
                <i class="bi bi-person"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($userId > 0): ?>
                    <a href="../../profile.php?id=<?= $userId ?>" class="fw-semibold text-success text-decoration-none">
                        <?= $username ?>
                    </a>
                    <?php else: ?>
                    <span class="fw-semibold"><?= $username ?></span>
                    <?php endif; ?>
                    <?php if ($createdAt): ?>
                    <span class="text-muted small"><?= $createdAt ?></span>
                    <?php endif; ?>
                </div>
                <div class="text-muted mt-1" style="line-height:1.6;white-space:normal;">
                    <?= $body ?>
                </div>

                <?php if ($isLoggedIn): ?>
                <button class="btn btn-link btn-sm text-success p-0 mt-1"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#reply-<?= $commentId ?>">
                    Reply
                </button>
                <div class="collapse mt-2" id="reply-<?= $commentId ?>">
                    <form class="comment-form" data-parent-id="<?= $commentId ?>">
                        <textarea class="form-control form-control-sm mb-2"
                                  name="body"
                                  rows="2"
                                  maxlength="1000"
                                  placeholder="Write a reply..."
                                  required></textarea>
                        <div class="d-flex align-items-center gap-2">
                            <button type="submit" class="btn btn-success btn-sm">Post reply</button>
                            <span class="comment-error text-danger small"></span>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

                <div class="comment-replies" data-replies-for="<?= $commentId ?>">
                    <?php foreach (($comment['replies'] ?? []) as $reply): ?>
                        <?php renderCampaignComment($reply, $isLoggedIn, $depth + 1); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
<?php
}

$categoryIcons = [
    'Technology'  => 'bi-cpu',
    'Arts'        => 'bi-palette',
    'Community'   => 'bi-people',
    'Education'   => 'bi-book',
    'Environment' => 'bi-tree',
    'Health'      => 'bi-heart-pulse',
    'Food'        => 'bi-egg-fried',
    'Other'       => 'bi-grid',
];
$icon = $categoryIcons[$campaign['Category']] ?? 'bi-grid';

$statusLabel = ['active' => 'Live', 'pending' => 'Pending', 'closed' => 'Ended'][$campaign['Status']] ?? $campaign['Status'];

$basePath  = '../../';
$pageTitle = $campaign['Title'];
require_once '../../includes/header.php';
?>

<div class="container py-4">
    <nav aria-label="breadcrumb" class="mb-3">
        <ol class="breadcrumb small">
            <li class="breadcrumb-item"><a href="../../index.php" class="text-success text-decoration-none">Discover</a></li>
            <li class="breadcrumb-item active"><?= htmlspecialchars($campaign['Title']) ?></li>
        </ol>
    </nav>

    <div class="row g-4">

        <!-- ── LEFT: Banner + description ───────────────────────── -->
        <div class="col-lg-8">

            <h1 class="fw-bold mb-1" style="font-size:1.6rem;">
                <?= htmlspecialchars($campaign['Title']) ?>
            </h1>

            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
                <span class="badge bg-success bg-opacity-10 text-success fw-semibold">
                    <i class="bi <?= $icon ?> me-1"></i><?= htmlspecialchars($campaign['Category']) ?>
                </span>
                <span class="text-muted small">by
                    <?php if ($campaign['HostID']): ?>
                        <a href="../../profile.php?id=<?= $campaign['HostID'] ?>"
                           class="text-success text-decoration-none fw-semibold">
                            <?= htmlspecialchars($campaign['HostName']) ?>
                        </a>
                    <?php else: ?>
                        <?= htmlspecialchars($campaign['HostName']) ?>
                    <?php endif; ?>
                </span>
                <span class="text-muted small">·</span>
                <span class="text-muted small">
                    Started <?= date('M j, Y', strtotime($campaign['CreatedAt'])) ?>
                </span>
            </div>

            <!-- Campaign banner (icon-based) -->
            <?php if (!empty($campaignDetails['banner'])): ?>
            <img src="<?= htmlspecialchars(assetUrl($campaignDetails['banner'], '../../')) ?>"
                 alt="<?= htmlspecialchars($campaign['Title']) ?>"
                 class="rounded mb-4 w-100"
                 style="height:280px;object-fit:cover;">
            <?php else: ?>
            <div class="rounded mb-4 d-flex align-items-center justify-content-center"
                 style="height:280px;background:linear-gradient(135deg,#e6f7ef,#d4edda);">
                <i class="bi <?= $icon ?> text-success" style="font-size:6rem;opacity:.5;"></i>
            </div>
            <?php endif; ?>

            <!-- Description -->
            <!-- NOTE: have implemented the 'Description' successfully 
                        but need to edit the UI/UX of this section-->
            <div class="card">
                <!-- <div class="card-body"> -->
                    <div class="card-body" style="padding: 0.75rem 1rem;">
                    <!-- <h5 class="fw-bold mb-3">About this campaign</h5> -->
                    <h5 class="fw-bold mb-2">About this campaign</h5>
                    <?php if (!empty($campaignDetails['description'] ?? '')): ?>
                    <!-- <div class="text-muted" style="line-height:1.8;white-space:pre-wrap;"> -->
                    <div class="text-muted" style="line-height:1.6;white-space:pre-wrap;">    
                        <?= nl2br(htmlspecialchars($campaignDetails['description'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-muted fst-italic">No description provided.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Donation activity — running total over time -->
            <?php if (!empty($donations)): ?>
            <div class="card mt-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-1">
                        <h5 class="fw-bold mb-0">Funding over time</h5>
                        <span class="badge bg-success bg-opacity-10 text-success small">
                            <?= count($donations) ?> donation<?= count($donations) != 1 ? 's' : '' ?>
                        </span>
                    </div>
                    <p class="text-muted small mb-4">
                        Each bar shows cumulative total raised after that donation
                        <code>SUM() OVER (PARTITION BY CampID ORDER BY Time)</code>
                    </p>

                    <?php
                    // Each bar width = RunningTotal / GoalAmt — shows progress toward goal over time
                    $barMax = $goal > 0 ? $goal : (float)end($donations)['RunningTotal'];
                    reset($donations);
                    ?>
                    <div class="d-flex flex-column gap-2">
                    <?php foreach ($donations as $i => $row):
                        $pctOfGoal  = $barMax > 0 ? min(($row['RunningTotal'] / $barMax) * 100, 100) : 0;
                        $prevTotal  = $i > 0 ? (float)$donations[$i-1]['RunningTotal'] : 0;
                        $isLatest   = $i === count($donations) - 1;
                    ?>
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-1"
                                 style="font-size:.82rem;">
                                <span class="text-muted">
                                    <?= date('M j, Y', strtotime($row['Time'])) ?>
                                    <span class="ms-1 fw-semibold">
                                        <?php if ($row['DonorID']): ?>
                                            <a href="../../profile.php?id=<?= $row['DonorID'] ?>"
                                               class="text-success text-decoration-none">
                                                <?= htmlspecialchars($row['DonorName']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted"><?= htmlspecialchars($row['DonorName']) ?></span>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-success ms-1">
                                        +$<?= number_format($row['Amt'], 0) ?>
                                    </span>
                                </span>
                                <span class="fw-bold <?= $isLatest ? 'text-success' : 'text-dark' ?>">
                                    $<?= number_format($row['RunningTotal'], 0) ?>
                                    <?php if ($goal > 0): ?>
                                    <span class="text-muted fw-normal">
                                        (<?= number_format($pctOfGoal, 0) ?>%)
                                    </span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div style="background:#e9ecef;border-radius:4px;height:10px;overflow:hidden;">
                                <div style="width:<?= number_format($pctOfGoal, 2) ?>%;
                                            height:100%;border-radius:4px;
                                            background:<?= $isLatest ? '#198754' : '#6aaa85' ?>;
                                            transition:width .5s ease;">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>

                    <?php if ($goal > 0): ?>
                    <div class="d-flex justify-content-between text-muted mt-2" style="font-size:.78rem;">
                        <span>$0</span>
                        <span>Goal: $<?= number_format($goal, 0) ?></span>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
            <?php endif; ?>

            <!-- Threaded comments -->
            <div class="card mt-4" id="comments">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="fw-bold mb-1">Discussion</h5>
                            <p class="text-muted small mb-0">Ask questions, share encouragement, or reply in a thread.</p>
                        </div>
                        <span class="badge bg-success bg-opacity-10 text-success small" id="commentCountBadge">
                            <?= (int)$commentThread['total'] ?> comment<?= (int)$commentThread['total'] !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <?php if ($isLoggedIn): ?>
                    <form class="comment-form mb-4" data-parent-id="">
                        <textarea class="form-control mb-2"
                                  name="body"
                                  rows="3"
                                  maxlength="1000"
                                  placeholder="Join the discussion..."
                                  required></textarea>
                        <div class="d-flex align-items-center gap-2">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-chat-left-text me-1"></i>Post comment
                            </button>
                            <span class="comment-error text-danger small"></span>
                        </div>
                    </form>
                    <?php else: ?>
                    <div class="alert alert-light border d-flex align-items-center justify-content-between gap-3 mb-4">
                        <span class="small text-muted">Sign in to join the discussion.</span>
                        <a href="../../login.php?redirect=<?= urlencode('partner/campaign/campaign-detail.php?id='.$id.'#comments') ?>"
                           class="btn btn-outline-success btn-sm">Sign in</a>
                    </div>
                    <?php endif; ?>

                    <?php if (empty($commentThread['roots'])): ?>
                    <p class="text-muted fst-italic mb-0" id="noCommentsMessage">No comments yet. Be the first to start the discussion.</p>
                    <?php endif; ?>
                    <div class="comment-thread" id="commentThread">
                    <?php if (!empty($commentThread['roots'])): ?>
                        <?php foreach ($commentThread['roots'] as $comment): ?>
                            <?php renderCampaignComment($comment, $isLoggedIn); ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── RIGHT: Stats sidebar ───────────────────────────────── -->
        <div class="col-lg-4">
            <div class="card campaign-stats-card">
                <div class="card-body">

                    <div class="mb-1">
                        <span class="fw-bold text-success" style="font-size:1.8rem;">
                            $<?= number_format($raised, 0) ?>
                        </span>
                        <span class="text-muted small"> raised of $<?= number_format($goal, 0) ?> goal</span>
                    </div>

                    <div class="progress mb-3" style="height:8px;">
                        <div class="progress-bar bg-success" style="width:<?= number_format($pct, 1) ?>%"></div>
                    </div>

                    <div class="row g-2 text-center mb-4">
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= number_format($pct, 0) ?>%</div>
                            <div class="text-muted small">funded</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $campaign['DonorCount'] ?></div>
                            <div class="text-muted small">donor<?= $campaign['DonorCount'] != 1 ? 's' : '' ?></div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold fs-5"><?= $statusLabel ?></div>
                            <div class="text-muted small">status</div>
                        </div>
                    </div>

                    <?php if ($campaign['Status'] === 'active'): ?>
                        <?php if ($canDonate): ?>
                        <a href="../../donate.php?camp_id=<?= $id ?>"
                           class="btn btn-success w-100 fw-semibold py-2 mb-2">
                            <i class="bi bi-heart me-1"></i>Donate now
                        </a>
                        <?php elseif (!$isLoggedIn): ?>
                        <a href="../../login.php?redirect=<?= urlencode('partner/campaign/campaign-detail.php?id='.$id) ?>"
                           class="btn btn-outline-success w-100 fw-semibold py-2 mb-2">
                            Sign in to donate
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                    <div class="alert alert-secondary text-center small py-2 mb-2">
                        This campaign is no longer accepting donations.
                    </div>
                    <?php endif; ?>

                    <button class="btn btn-outline-secondary w-100 btn-sm" onclick="handleShare()">
                        <i class="bi bi-share me-1"></i>Share
                    </button>
                    <div id="toastMsg" class="alert alert-success text-center small mt-2 py-2"
                         style="display:none;">Link copied!</div>

                </div>
            </div>

            <div class="card mt-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="fw-bold mb-0">Top Supporters</h6>
                        <span class="text-muted small" id="supportersSyncLabel">Live</span>
                    </div>
                    <div id="supportersList">
                        <?php if (empty($topSupporters)): ?>
                        <p class="text-muted small mb-0">No supporters yet.</p>
                        <?php else: ?>
                            <?php foreach ($topSupporters as $supporter): ?>
                            <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                                <div>
                                    <div class="fw-semibold small">
                                        <?= [1 => '🥇', 2 => '🥈', 3 => '🥉'][(int)$supporter['RankInCampaign']] ?? '#' . (int)$supporter['RankInCampaign'] ?>
                                        <?php if ($supporter['DonorID']): ?>
                                        <a href="../../profile.php?id=<?= (int)$supporter['DonorID'] ?>" class="text-success text-decoration-none ms-1">
                                            <?= htmlspecialchars($supporter['DonorName']) ?>
                                        </a>
                                        <?php else: ?>
                                        <span class="text-muted ms-1"><?= htmlspecialchars($supporter['DonorName']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="fw-semibold text-success small">$<?= number_format((float)$supporter['Amt'], 2) ?></div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="small text-muted mt-3">Supporter standings refresh automatically every few seconds.</div>
                </div>
            </div>
        </div>

    </div>
</div>

<script>
const CAMPAIGN_ID = <?= (int)$id ?>;
const CAN_COMMENT = <?= $isLoggedIn ? 'true' : 'false' ?>;
let commentCount = <?= (int)$commentThread['total'] ?>;

function handleShare() {
    navigator.clipboard.writeText(window.location.href).then(() => {
        const t = document.getElementById('toastMsg');
        t.style.display = 'block';
        setTimeout(() => t.style.display = 'none', 2500);
    });
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

function nl2br(value) {
    return escapeHtml(value).replace(/\r?\n/g, '<br>');
}

function updateCommentCount() {
    const badge = document.getElementById('commentCountBadge');
    if (badge) {
        badge.textContent = `${commentCount} comment${commentCount === 1 ? '' : 's'}`;
    }
}

function commentHtml(comment, depth, repliesHtml = '') {
    const id = escapeHtml(comment.id);
    const username = escapeHtml(comment.username || 'User');
    const createdAt = escapeHtml(comment.created_at || '');
    const body = nl2br(comment.body || '');
    const userId = Number(comment.user_id || 0);
    const indent = depth > 0 ? 'ms-4 ps-3 border-start' : '';
    const profileLink = userId > 0
        ? `<a href="../../profile.php?id=${userId}" class="fw-semibold text-success text-decoration-none">${username}</a>`
        : `<span class="fw-semibold">${username}</span>`;
    const replyUi = CAN_COMMENT ? `
                    <button class="btn btn-link btn-sm text-success p-0 mt-1"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#reply-${id}">
                        Reply
                    </button>
                    <div class="collapse mt-2" id="reply-${id}">
                        <form class="comment-form" data-parent-id="${id}">
                            <textarea class="form-control form-control-sm mb-2"
                                      name="body"
                                      rows="2"
                                      maxlength="1000"
                                      placeholder="Write a reply..."
                                      required></textarea>
                            <div class="d-flex align-items-center gap-2">
                                <button type="submit" class="btn btn-success btn-sm">Post reply</button>
                                <span class="comment-error text-danger small"></span>
                            </div>
                        </form>
                    </div>` : '';

    return `
        <div class="comment-item ${indent} mt-3" id="comment-${id}" data-comment-id="${id}" data-depth="${depth}">
            <div class="d-flex gap-2">
                <div class="rounded-circle bg-success bg-opacity-10 text-success d-flex align-items-center justify-content-center flex-shrink-0"
                     style="width:34px;height:34px;">
                    <i class="bi bi-person"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        ${profileLink}
                        ${createdAt ? `<span class="text-muted small">${createdAt}</span>` : ''}
                    </div>
                    <div class="text-muted mt-1" style="line-height:1.6;white-space:normal;">
                        ${body}
                    </div>

                    ${replyUi}
                    <div class="comment-replies" data-replies-for="${id}">${repliesHtml}</div>
                </div>
            </div>
        </div>
    `;
}

function commentTreeHtml(comments, depth = 0) {
    return (comments || []).map((comment) => {
        const repliesHtml = commentTreeHtml(comment.replies || [], depth + 1);
        return commentHtml(comment, depth, repliesHtml);
    }).join('');
}

function insertComment(comment) {
    const parentId = comment.parent_id || '';
    let depth = 0;
    let target = document.getElementById('commentThread');

    if (parentId) {
        const parent = document.querySelector(`[data-comment-id="${parentId}"]`);
        const replies = document.querySelector(`[data-replies-for="${parentId}"]`);
        if (parent) depth = Number(parent.dataset.depth || 0) + 1;
        if (replies) target = replies;
    }

    if (!target) return;
    target.insertAdjacentHTML('beforeend', commentHtml(comment, depth));

    const empty = document.getElementById('noCommentsMessage');
    if (empty) empty.style.display = 'none';

    commentCount += 1;
    updateCommentCount();

    const inserted = document.getElementById(`comment-${comment.id}`);
    if (inserted) {
        inserted.querySelectorAll('.comment-form').forEach((form) => bindCommentForm(form));
        inserted.scrollIntoView({behavior: 'smooth', block: 'center'});
    }
}

function bindCommentForm(form) {
    if (form.dataset.bound === '1') return;
    form.dataset.bound = '1';

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        const textarea = form.querySelector('textarea[name="body"]');
        const error = form.querySelector('.comment-error');
        const button = form.querySelector('button[type="submit"]');
        const body = textarea.value.trim();

        if (!body) {
            error.textContent = 'Write a comment first.';
            return;
        }

        error.textContent = '';
        button.disabled = true;

        try {
            const response = await fetch('../../api/campaign_comments.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    camp_id: CAMPAIGN_ID,
                    parent_id: form.dataset.parentId || null,
                    body: body
                })
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok || !data.success || !data.comment) {
                throw new Error(data.error || 'Could not save comment.');
            }

            textarea.value = '';
            insertComment(data.comment);

            const collapse = form.closest('.collapse');
            if (collapse && window.bootstrap) {
                bootstrap.Collapse.getOrCreateInstance(collapse).hide();
            }
        } catch (err) {
            error.textContent = err.message || 'Could not save comment.';
        } finally {
            button.disabled = false;
        }
    });
}

document.querySelectorAll('.comment-form').forEach((form) => {
    bindCommentForm(form);
});

function renderSupporters(supporters) {
    const list = document.getElementById('supportersList');
    if (!list) return;
    if (!Array.isArray(supporters) || supporters.length === 0) {
        list.innerHTML = '<p class="text-muted small mb-0">No supporters yet.</p>';
        return;
    }
    const medals = {1: '🥇', 2: '🥈', 3: '🥉'};
    list.innerHTML = supporters.map((supporter) => `
        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <div class="fw-semibold small">
                ${medals[Number(supporter.rank)] || '#' + Number(supporter.rank)}
                ${supporter.donor_id ? `<a href="../../profile.php?id=${Number(supporter.donor_id)}" class="text-success text-decoration-none ms-1">${escapeHtml(supporter.donor_name)}</a>` : `<span class="text-muted ms-1">${escapeHtml(supporter.donor_name)}</span>`}
            </div>
            <div class="fw-semibold text-success small">$${Number(supporter.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}</div>
        </div>
    `).join('');
}

async function refreshSupporters() {
    try {
        const data = await fetch(`../../api/campaign_supporters.php?camp_id=${encodeURIComponent(CAMPAIGN_ID)}`, { cache: 'no-store' }).then((r) => r.json());
        if (!data.success) return;
        renderSupporters(data.supporters || []);
        const sync = document.getElementById('supportersSyncLabel');
        if (sync) sync.textContent = `Updated ${new Date().toLocaleTimeString()}`;
    } catch (e) {}
}

async function refreshCommentsFeed() {
    if (document.activeElement && document.activeElement.tagName === 'TEXTAREA') {
        return;
    }
    try {
        const data = await fetch(`../../api/campaign_comments_feed.php?camp_id=${encodeURIComponent(CAMPAIGN_ID)}`, { cache: 'no-store' }).then((r) => r.json());
        if (!data.success || !data.thread) return;
        commentCount = Number(data.thread.total || 0);
        updateCommentCount();
        const empty = document.getElementById('noCommentsMessage');
        if (empty) empty.style.display = commentCount === 0 ? '' : 'none';
        const thread = document.getElementById('commentThread');
        if (!thread) return;
        thread.innerHTML = commentTreeHtml(data.thread.roots || []);
        thread.querySelectorAll('.comment-form').forEach((form) => bindCommentForm(form));
    } catch (e) {}
}

setInterval(() => {
    refreshSupporters();
    refreshCommentsFeed();
}, 8000);

</script>

<?php require_once '../../includes/footer.php'; ?>

