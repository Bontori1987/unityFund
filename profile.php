<?php
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';

$viewId   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$me       = isLoggedIn() ? currentUser() : null;
$isOwnProfile = $me && ($viewId === 0 || $viewId === (int)$me['id']);

// Redirect to own profile if not logged in and no ID given
if (!$isOwnProfile && $viewId <= 0) {
    requireLogin('profile.php');
}
if ($isOwnProfile && !$me) {
    requireLogin('profile.php');
}

$targetId = $isOwnProfile ? (int)$me['id'] : $viewId;

// Fetch MS SQL user info
$sqlUser = null;
try {
    $stmt = $conn->prepare("SELECT UserID, Username, Email, Role, IsAnonymous FROM Users WHERE UserID = ?");
    $stmt->execute([$targetId]);
    $sqlUser = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

if (!$sqlUser) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:2rem;">User not found. <a href="index.php">Go back</a></p>');
}

// Block public access to anonymous profiles — even with direct ?id= URL
if (!$isOwnProfile && $sqlUser['IsAnonymous']) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem;">This profile is private. <a href="index.php">Go back</a></p>');
}

// Fetch MongoDB profile
$profile = getProfile($targetId) ?? [
    'bio' => '', 'location' => '', 'website' => '', 'avatar_url' => '', 'joined_at' => null,
];

// Fetch donation stats from MS SQL (public info)
$stats = ['total_donated' => 0, 'donation_count' => 0, 'campaigns_supported' => 0];
try {
    $sStmt = $conn->prepare(
        "SELECT COALESCE(SUM(Amt), 0) AS total_donated,
                COUNT(*) AS donation_count,
                COUNT(DISTINCT CampID) AS campaigns_supported
         FROM Donations WHERE DonorID = ? AND IsAnonymous = 0"
    );
    $sStmt->execute([$targetId]);
    $stats = $sStmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (PDOException $e) {}

$pageTitle = $isOwnProfile ? 'My Profile' : htmlspecialchars($sqlUser['Username']) . "'s Profile";
$basePath  = '';
require_once 'includes/header.php';
?>

<div class="container py-5" style="max-width:720px;">

    <!-- Avatar + name header -->
    <div class="d-flex align-items-center gap-4 mb-4">
        <div class="position-relative" style="width:88px;height:88px;flex-shrink:0;">
            <img id="avatar-preview"
                 src="<?= !empty($profile['avatar_url']) ? htmlspecialchars($profile['avatar_url']) : '' ?>"
                 alt="Avatar" class="rounded-circle border bg-light"
                 style="width:88px;height:88px;object-fit:cover;"
                 onerror="this.style.display='none';document.getElementById('avatar-fallback').style.display='flex';">
            <div id="avatar-fallback"
                 class="rounded-circle bg-success d-flex align-items-center justify-content-center text-white fw-bold position-absolute top-0 start-0"
                 style="width:88px;height:88px;font-size:2rem;<?= !empty($profile['avatar_url']) ? 'display:none!important;' : '' ?>">
                <?= strtoupper(substr($sqlUser['Username'], 0, 1)) ?>
            </div>
            <?php if ($isOwnProfile): ?>
            <label for="avatar-file" title="Upload photo"
                   class="position-absolute bottom-0 end-0 bg-white border rounded-circle d-flex align-items-center justify-content-center"
                   style="width:28px;height:28px;cursor:pointer;">
                <i class="bi bi-camera-fill text-success" style="font-size:.75rem;"></i>
            </label>
            <input type="file" id="avatar-file" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none">
            <?php endif; ?>
        </div>

        <div class="flex-grow-1">
            <h2 class="mb-0 fw-bold"><?= htmlspecialchars($sqlUser['Username']) ?></h2>
            <?php if ($isOwnProfile): ?>
                <span class="text-muted small"><?= htmlspecialchars($sqlUser['Email']) ?></span>
            <?php endif; ?>
            <div class="d-flex flex-wrap gap-3 mt-1 text-muted small">
                <?php if (!empty($profile['location'])): ?>
                <span><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($profile['location']) ?></span>
                <?php endif; ?>
                <?php if (!empty($profile['website'])): ?>
                <a href="<?= htmlspecialchars($profile['website']) ?>" target="_blank" rel="noopener"
                   class="text-success text-decoration-none">
                    <i class="bi bi-link-45deg me-1"></i><?= htmlspecialchars(parse_url($profile['website'], PHP_URL_HOST) ?: $profile['website']) ?>
                </a>
                <?php endif; ?>
                <?php if (!empty($profile['joined_at'])): ?>
                <span><i class="bi bi-calendar3 me-1"></i>Joined <?= $profile['joined_at'] ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="profile-alert"></div>
    <div id="upload-progress" class="d-none mb-3">
        <div class="progress" style="height:4px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-success w-100"></div>
        </div>
    </div>

    <!-- Donation stats -->
    <?php if ($stats['donation_count'] > 0): ?>
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="card border-0 bg-light text-center p-3">
                <div class="fw-bold fs-5 text-success">$<?= number_format($stats['total_donated'], 0) ?></div>
                <div class="text-muted small">Total donated</div>
            </div>
        </div>
        <div class="col-4">
            <div class="card border-0 bg-light text-center p-3">
                <div class="fw-bold fs-5"><?= $stats['donation_count'] ?></div>
                <div class="text-muted small">Donation<?= $stats['donation_count'] != 1 ? 's' : '' ?></div>
            </div>
        </div>
        <div class="col-4">
            <div class="card border-0 bg-light text-center p-3">
                <div class="fw-bold fs-5"><?= $stats['campaigns_supported'] ?></div>
                <div class="text-muted small">Campaign<?= $stats['campaigns_supported'] != 1 ? 's' : '' ?> backed</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Bio -->
    <?php if (!empty($profile['bio']) || $isOwnProfile): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <?php if ($isOwnProfile): ?>
                <h5 class="fw-semibold mb-3">Edit Profile</h5>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Bio</label>
                    <textarea id="bio" class="form-control" rows="3"
                              placeholder="Tell us about yourself..."><?= htmlspecialchars($profile['bio']) ?></textarea>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Location</label>
                        <input type="text" id="location" class="form-control"
                               placeholder="City, Country"
                               value="<?= htmlspecialchars($profile['location']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold small">Website</label>
                        <input type="text" id="website" class="form-control"
                               placeholder="https://yoursite.com"
                               value="<?= htmlspecialchars($profile['website']) ?>">
                    </div>
                </div>
                <!-- Anonymous mode toggle -->
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="fw-semibold small">Anonymous mode</div>
                        <div class="text-muted" style="font-size:.82rem;">
                            Hide your name everywhere on the site. Your profile becomes private
                            and cannot be viewed by others.
                        </div>
                    </div>
                    <div class="form-check form-switch ms-3 mt-1">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="is_anonymous" style="width:2.5em;height:1.3em;"
                               <?= $sqlUser['IsAnonymous'] ? 'checked' : '' ?>>
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <button id="save-btn" class="btn btn-success px-4 fw-semibold">
                        <i class="bi bi-check2 me-1"></i>Save changes
                    </button>
                    <button id="cancel-btn" class="btn btn-outline-secondary px-4">Cancel</button>
                </div>
            <?php else: ?>
                <h6 class="fw-semibold text-muted small text-uppercase mb-2">About</h6>
                <p class="mb-0" style="line-height:1.7;"><?= nl2br(htmlspecialchars($profile['bio'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php if ($isOwnProfile): ?>
<script>
const original = {
    bio:          <?= json_encode($profile['bio']) ?>,
    location:     <?= json_encode($profile['location']) ?>,
    website:      <?= json_encode($profile['website']) ?>,
    is_anonymous: <?= $sqlUser['IsAnonymous'] ? 'true' : 'false' ?>,
};

document.getElementById('avatar-file').addEventListener('change', async function () {
    if (!this.files[0]) return;
    const progress = document.getElementById('upload-progress');
    const alertDiv = document.getElementById('profile-alert');
    progress.classList.remove('d-none');
    alertDiv.innerHTML = '';
    const form = new FormData();
    form.append('avatar', this.files[0]);
    try {
        const res  = await fetch('api/upload_avatar.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) {
            const img = document.getElementById('avatar-preview');
            const fb  = document.getElementById('avatar-fallback');
            img.src = data.avatar_url;
            img.style.display = '';
            fb.style.display  = 'none';
            alertDiv.innerHTML = `<div class="alert alert-success py-2 small">
                <i class="bi bi-check-circle me-1"></i>Photo updated!</div>`;
            setTimeout(() => alertDiv.innerHTML = '', 2500);
        } else {
            alertDiv.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`;
        }
    } catch (e) {
        alertDiv.innerHTML = `<div class="alert alert-danger py-2 small">Upload failed.</div>`;
    } finally {
        progress.classList.add('d-none');
        this.value = '';
    }
});

document.getElementById('cancel-btn').addEventListener('click', () => {
    document.getElementById('bio').value          = original.bio;
    document.getElementById('location').value     = original.location;
    document.getElementById('website').value      = original.website;
    document.getElementById('is_anonymous').checked = original.is_anonymous;
});

document.getElementById('save-btn').addEventListener('click', async () => {
    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';
    const payload = {
        bio:          document.getElementById('bio').value.trim(),
        location:     document.getElementById('location').value.trim(),
        website:      document.getElementById('website').value.trim(),
        is_anonymous: document.getElementById('is_anonymous').checked,
    };
    try {
        const res  = await fetch('api/update_profile.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await res.json();
        const alertDiv = document.getElementById('profile-alert');
        if (data.success) {
            alertDiv.innerHTML = `<div class="alert alert-success py-2 small">
                <i class="bi bi-check-circle me-1"></i>Profile saved!</div>`;
            setTimeout(() => { alertDiv.innerHTML = ''; location.reload(); }, 1500);
        } else {
            alertDiv.innerHTML = `<div class="alert alert-danger py-2 small">${data.error}</div>`;
        }
    } catch (e) {
        document.getElementById('profile-alert').innerHTML =
            `<div class="alert alert-danger py-2 small">Network error</div>`;
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check2 me-1"></i>Save changes';
    }
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
