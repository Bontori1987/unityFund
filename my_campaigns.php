<?php
$pageTitle = 'Campaigns';
$basePath  = '';
require_once 'includes/auth.php';
require_once 'db.php';

requireRole(['organizer', 'admin']);

$userID  = (int)currentUser()['id'];
$isAdmin = isAdmin();

$CATEGORIES = ['Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];

// Pending organizer applications (admin only)
$pendingOrganizers = [];
if ($isAdmin) {
    try {
        $stmt = $conn->query(
            "SELECT UserID, Username, Email, CreatedAt
             FROM Users WHERE Role = 'pending_organizer'
             ORDER BY CreatedAt ASC"
        );
        $pendingOrganizers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { }
}

// Campaigns
try {
    if ($isAdmin) {
        $stmt = $conn->query(
            "SELECT c.CampID, c.Title, c.Description, c.GoalAmt, c.Status, c.Category, c.CreatedAt,
                    u.Username AS HostName,
                    COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                    COUNT(DISTINCT d.ID)       AS DonationCount,
                    COUNT(DISTINCT d.DonorID)  AS DonorCount
             FROM Campaigns c
             JOIN Users u ON c.HostID = u.UserID
             LEFT JOIN Donations d ON d.CampID = c.CampID
             GROUP BY c.CampID, c.Title, c.Description, c.GoalAmt, c.Status, c.Category, c.CreatedAt, u.Username
             ORDER BY
               CASE c.Status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END,
               c.CreatedAt DESC"
        );
    } else {
        $stmt = $conn->prepare(
            "SELECT c.CampID, c.Title, c.Description, c.GoalAmt, c.Status, c.Category, c.CreatedAt,
                    COALESCE(SUM(d.Amt), 0)   AS TotalRaised,
                    COUNT(DISTINCT d.ID)       AS DonationCount,
                    COUNT(DISTINCT d.DonorID)  AS DonorCount
             FROM Campaigns c
             LEFT JOIN Donations d ON d.CampID = c.CampID
             WHERE c.HostID = ?
             GROUP BY c.CampID, c.Title, c.Description, c.GoalAmt, c.Status, c.Category, c.CreatedAt
             ORDER BY c.CreatedAt DESC"
        );
        $stmt->execute([$userID]);
    }
    $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $campaigns = [];
    $dbError   = $e->getMessage();
}

$pageTitle = $isAdmin ? 'Manage Campaigns' : 'My Campaigns';
require_once 'includes/header.php';
?>

<div class="container py-5">

    <div class="d-flex justify-content-between align-items-end mb-4 flex-wrap gap-3">
        <div>
            <h1 class="fw-bold mb-1"><?= $isAdmin ? 'Manage Campaigns' : 'My Campaigns' ?></h1>
            <p class="text-muted mb-0">
                <?= $isAdmin
                    ? 'Approve organizer applications and manage all campaigns.'
                    : 'Create and manage your fundraising campaigns.' ?>
            </p>
        </div>
        <?php if (!$isAdmin): ?>
        <button class="btn btn-success px-4 fw-semibold"
                data-bs-toggle="collapse" data-bs-target="#newCampPanel">
            <i class="bi bi-plus-lg me-1"></i>New Campaign
        </button>
        <?php endif; ?>
    </div>

    <?php if (isset($dbError)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <!-- Pending organizer applications -->
    <?php if ($isAdmin && !empty($pendingOrganizers)): ?>
    <div class="card border-warning mb-4">
        <div class="card-body">
            <h5 class="fw-bold text-warning mb-3">
                <i class="bi bi-exclamation-triangle me-2"></i>
                Pending Organizer Applications (<?= count($pendingOrganizers) ?>)
            </h5>
            <div class="row g-3">
                <?php foreach ($pendingOrganizers as $org): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="border rounded p-3" style="border-left:4px solid #e65100 !important;">
                        <div class="fw-bold"><?= htmlspecialchars($org['Username']) ?></div>
                        <div class="text-muted small"><?= htmlspecialchars($org['Email']) ?></div>
                        <div class="text-muted small mb-2">
                            Applied <?= date('M j, Y', strtotime($org['CreatedAt'])) ?>
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-success"
                                    onclick="approveOrganizer(<?= $org['UserID'] ?>, 'organizer', this)">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </button>
                            <button class="btn btn-sm btn-outline-danger"
                                    onclick="approveOrganizer(<?= $org['UserID'] ?>, 'donor', this)">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php elseif ($isAdmin): ?>
    <div class="alert alert-success py-2 small mb-4">
        <i class="bi bi-check-circle me-1"></i>No pending organizer applications.
    </div>
    <?php endif; ?>

    <!-- New campaign form (organizer) -->
    <?php if (!$isAdmin): ?>
    <div class="collapse mb-4" id="newCampPanel">
        <div class="card">
            <div class="card-body">
                <h5 class="fw-bold mb-1">Create New Campaign</h5>
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Campaigns start as <strong>Pending</strong> and go live only after admin approval.
                </p>
                <form id="newCampForm">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">Campaign Title</label>
                            <input type="text" name="title" class="form-control"
                                   placeholder="e.g. Clean Water for Village A" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Goal (USD)</label>
                            <input type="number" name="goal" class="form-control"
                                   min="1" step="0.01" placeholder="5000.00" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold small">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($CATEGORIES as $cat): ?>
                                <option value="<?= $cat ?>"><?= $cat ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">
                                Description <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <textarea name="description" class="form-control" rows="3"
                                      placeholder="Tell donors what this campaign is about…"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success fw-semibold px-4">
                                Submit for Approval
                            </button>
                            <span class="ms-3 small" id="newCampMsg"></span>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Campaign list -->
    <?php if (empty($campaigns)): ?>
    <div class="empty-state">
        <i class="bi bi-grid"></i>
        <p class="mt-3">
            <?= $isAdmin ? 'No campaigns found.' : 'No campaigns yet. Click "New Campaign" to get started.' ?>
        </p>
    </div>
    <?php else: ?>
    <?php foreach ($campaigns as $c):
        $raised = (float)$c['TotalRaised'];
        $goal   = (float)$c['GoalAmt'];
        $pct    = $goal > 0 ? min(($raised / $goal) * 100, 100) : 0;
        $cid    = $c['CampID'];

        $statusClass = ['active'=>'camp-status-active','pending'=>'camp-status-pending','closed'=>'camp-status-closed'][$c['Status']] ?? '';
        $statusLabel = ['active'=>'Active','pending'=>'Pending Approval','closed'=>'Closed'][$c['Status']] ?? $c['Status'];
    ?>
    <div class="card mb-3" id="card-<?= $cid ?>">
        <div class="card-body">

            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <h5 class="fw-bold mb-0"><?= htmlspecialchars($c['Title']) ?></h5>
                    <?php if ($isAdmin): ?>
                    <div class="text-muted small">by <?= htmlspecialchars($c['HostName']) ?></div>
                    <?php endif; ?>
                    <span class="badge bg-success bg-opacity-10 text-success fw-semibold small mt-1">
                        <?= htmlspecialchars($c['Category'] ?? 'Other') ?>
                    </span>
                </div>
                <span class="status-pill <?= $statusClass ?>"><?= $statusLabel ?></span>
            </div>

            <div class="row g-3 mb-3 text-center">
                <div class="col-6 col-sm-3">
                    <div class="fw-bold text-success fs-5">$<?= number_format($raised,0) ?></div>
                    <div class="section-title">Raised</div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="fw-bold fs-5">$<?= number_format($goal,0) ?></div>
                    <div class="section-title">Goal</div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="fw-bold fs-5"><?= $c['DonorCount'] ?></div>
                    <div class="section-title">Donors</div>
                </div>
                <div class="col-6 col-sm-3">
                    <div class="fw-bold fs-5"><?= number_format($pct,1) ?>%</div>
                    <div class="section-title">Funded</div>
                </div>
            </div>

            <div class="progress mb-3" style="height:6px;">
                <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>

            <div class="d-flex flex-wrap gap-2">
                <button class="btn btn-sm btn-outline-secondary"
                        data-bs-toggle="collapse" data-bs-target="#edit-<?= $cid ?>">
                    <i class="bi bi-pencil me-1"></i>Edit
                </button>
                <button class="btn btn-sm btn-outline-secondary"
                        onclick="loadDonations(<?= $cid ?>)">
                    <i class="bi bi-list-ul me-1"></i>Donations
                </button>
                <?php if ($isAdmin): ?>
                    <?php if ($c['Status'] === 'pending'): ?>
                    <button class="btn btn-sm btn-success" onclick="quickStatus(<?= $cid ?>,'active')">
                        <i class="bi bi-check-lg me-1"></i>Approve
                    </button>
                    <button class="btn btn-sm btn-outline-danger" onclick="quickStatus(<?= $cid ?>,'closed')">
                        <i class="bi bi-x-lg me-1"></i>Reject
                    </button>
                    <?php elseif ($c['Status'] === 'active'): ?>
                    <button class="btn btn-sm btn-outline-danger" onclick="quickStatus(<?= $cid ?>,'closed')">
                        <i class="bi bi-lock me-1"></i>Close
                    </button>
                    <?php elseif ($c['Status'] === 'closed'): ?>
                    <button class="btn btn-sm btn-outline-success" onclick="quickStatus(<?= $cid ?>,'active')">
                        <i class="bi bi-unlock me-1"></i>Reactivate
                    </button>
                    <?php endif; ?>
                    <a href="donate.php?camp_id=<?= $cid ?>" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-heart me-1"></i>Donate
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Edit panel -->
        <div class="collapse" id="edit-<?= $cid ?>">
            <div class="card-body border-top" style="background:#fafafa;">
                <h6 class="section-title mb-3">Edit Campaign</h6>
                <?php if (!$isAdmin && $c['Status'] !== 'active'): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Status: <strong><?= $statusLabel ?></strong>.
                    <?= $c['Status'] === 'pending' ? 'You can still edit while waiting for approval.' : 'Contact admin to reactivate.' ?>
                </div>
                <?php endif; ?>
                <form onsubmit="saveCampaign(event, <?= $cid ?>)">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label small fw-semibold">Title</label>
                            <input type="text" name="title" class="form-control"
                                   value="<?= htmlspecialchars($c['Title']) ?>" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Goal (USD)</label>
                            <input type="number" name="goal" class="form-control"
                                   min="1" step="0.01" value="<?= $goal ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small fw-semibold">Category</label>
                            <select name="category" class="form-select">
                                <?php foreach ($CATEGORIES as $cat): ?>
                                <option value="<?= $cat ?>"
                                    <?= ($c['Category'] ?? 'Other') === $cat ? 'selected' : '' ?>>
                                    <?= $cat ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label small fw-semibold">
                                Description <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($c['Description'] ?? '') ?></textarea>
                        </div>
                        <?php if ($isAdmin): ?>
                        <div class="col-md-2">
                            <label class="form-label small fw-semibold">Status <span class="text-muted fw-normal">(admin)</span></label>
                            <select name="status" class="form-select">
                                <option value="active"  <?= $c['Status']==='active'  ?'selected':'' ?>>Active</option>
                                <option value="pending" <?= $c['Status']==='pending' ?'selected':'' ?>>Pending</option>
                                <option value="closed"  <?= $c['Status']==='closed'  ?'selected':'' ?>>Closed</option>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success btn-sm px-4">Save Changes</button>
                            <span class="ms-2 small" id="save-msg-<?= $cid ?>"></span>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Donations panel -->
        <div class="panel-body" id="don-<?= $cid ?>">
            <div class="card-body border-top" style="background:#fafafa;">
                <h6 class="section-title mb-3">Donations — <?= htmlspecialchars($c['Title']) ?></h6>
                <div id="don-content-<?= $cid ?>">
                    <p class="text-muted small">Click "Donations" to load.</p>
                </div>
            </div>
        </div>

    </div>
    <?php endforeach; ?>
    <?php endif; ?>

</div>

<script>
async function quickStatus(campID, newStatus) {
    if (!confirm(`Set campaign status to "${newStatus}"?`)) return;
    const data = await fetch('api/update_campaign.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ camp_id: campID, status: newStatus })
    }).then(r => r.json());
    if (data.success) location.reload();
    else alert('Error: ' + (data.error || 'Update failed'));
}

async function saveCampaign(e, campID) {
    e.preventDefault();
    const form  = e.target;
    const msgEl = document.getElementById('save-msg-' + campID);
    const payload = {
        camp_id:     campID,
        title:       form.title.value.trim(),
        goal:        parseFloat(form.goal.value),
        category:    form.category.value,
        description: form.description.value.trim(),
    };
    if (form.status) payload.status = form.status.value;
    msgEl.textContent = 'Saving…'; msgEl.className = 'small';
    try {
        const data = await fetch('api/update_campaign.php', {
            method: 'POST', headers: {'Content-Type':'application/json'},
            body: JSON.stringify(payload)
        }).then(r => r.json());
        if (data.success) {
            msgEl.textContent = '✓ Saved!'; msgEl.className = 'small text-success fw-semibold';
            setTimeout(() => location.reload(), 600);
        } else {
            msgEl.textContent = '✗ ' + (data.error || 'Failed'); msgEl.className = 'small text-danger';
        }
    } catch { msgEl.textContent = '✗ Network error'; msgEl.className = 'small text-danger'; }
}

async function loadDonations(campID) {
    const panel   = document.getElementById('don-' + campID);
    const content = document.getElementById('don-content-' + campID);
    if (panel.classList.contains('open')) { panel.classList.remove('open'); return; }
    content.innerHTML = '<p class="text-muted small">Loading…</p>';
    panel.classList.add('open');
    try {
        const data = await fetch('api/campaign_donations.php?camp_id=' + campID).then(r => r.json());
        if (!data.success || data.donations.length === 0) {
            content.innerHTML = '<p class="text-muted small">No donations yet.</p>';
            return;
        }
        let html = `<div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
            <thead><tr><th>#</th><th>Donor</th><th>Amount</th><th>Date</th><th>Receipt</th></tr></thead><tbody>`;
        data.donations.forEach(d => {
            html += `<tr>
                <td class="text-muted small">${d.ID}</td>
                <td>${d.DonorName}</td>
                <td><strong>$${parseFloat(d.Amt).toFixed(2)}</strong></td>
                <td class="text-muted small">${d.Time}</td>
                <td>${d.HasReceipt
                    ? '<span class="badge bg-success bg-opacity-10 text-success">Issued</span>'
                    : '<span class="text-muted">—</span>'}</td>
            </tr>`;
        });
        html += '</tbody></table></div>';
        content.innerHTML = html;
    } catch { content.innerHTML = '<p class="text-danger small">Failed to load.</p>'; }
}

const newForm = document.getElementById('newCampForm');
if (newForm) {
    newForm.addEventListener('submit', async function(e) {
        e.preventDefault();
        const msg = document.getElementById('newCampMsg');
        msg.textContent = 'Submitting…'; msg.className = 'small';
        try {
            const data = await fetch('api/update_campaign.php', {
                method: 'POST', headers: {'Content-Type':'application/json'},
                body: JSON.stringify({
                    action:      'create',
                    title:       this.title.value.trim(),
                    goal:        parseFloat(this.goal.value),
                    category:    this.category.value,
                    description: this.description.value.trim(),
                })
            }).then(r => r.json());
            if (data.success) {
                msg.textContent = '✓ Submitted! Waiting for admin approval.';
                msg.className   = 'small text-success fw-semibold';
                setTimeout(() => location.reload(), 900);
            } else {
                msg.textContent = '✗ ' + (data.error || 'Failed');
                msg.className   = 'small text-danger';
            }
        } catch { msg.textContent = '✗ Network error'; msg.className = 'small text-danger'; }
    });
}

async function approveOrganizer(userID, newRole, btn) {
    btn.disabled = true;
    const data = await fetch('api/update_user_role.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ user_id: userID, role: newRole })
    }).then(r => r.json());
    if (data.success) location.reload();
    else { alert('Error: ' + (data.error || 'Failed')); btn.disabled = false; }
}
</script>

<?php require_once 'includes/footer.php'; ?>
