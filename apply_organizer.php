<?php
require_once 'includes/auth.php';
require_once 'db.php';
require_once 'includes/mongo.php';
require_once 'includes/mail.php';

requireLogin('apply_organizer.php');

$user = currentUser();
$userId = (int)$user['id'];
$role = currentRole();

$ID_TYPES = ['Passport', 'National ID', "Driver's license"];
$ORG_TYPES = ['Individual', 'Registered NGO', 'Community Group', 'School/University', 'Business'];
$CATEGORIES = ['Technology', 'Arts', 'Community', 'Education', 'Environment', 'Health', 'Food', 'Other'];
$GOAL_RANGES = ['Under $1k', '$1k-$10k', '$10k-$100k', 'Above $100k'];

$latestApplication = getLatestOrganizerApplication($userId);
$error = '';
$success = '';

function oldValue(string $key): string {
    return htmlspecialchars((string)($_POST[$key] ?? ''));
}

function selectedValue(string $key, string $value): string {
    return (($_POST[$key] ?? '') === $value) ? 'selected' : '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'donor') {
    $payload = [
        'legal_name'                         => trim($_POST['legal_name'] ?? ''),
        'phone'                              => trim($_POST['phone'] ?? ''),
        'date_of_birth'                      => trim($_POST['date_of_birth'] ?? ''),
        'government_id_type'                 => trim($_POST['government_id_type'] ?? ''),
        'organization_name'                  => trim($_POST['organization_name'] ?? ''),
        'organization_type'                  => trim($_POST['organization_type'] ?? ''),
        'website_social'                     => trim($_POST['website_social'] ?? ''),
        'campaign_intent'                    => trim($_POST['campaign_intent'] ?? ''),
        'focus_category'                     => trim($_POST['focus_category'] ?? ''),
        'estimated_goal_range'               => trim($_POST['estimated_goal_range'] ?? ''),
        'has_fundraising_experience'         => ($_POST['has_fundraising_experience'] ?? 'no') === 'yes',
        'fundraising_experience_description' => trim($_POST['fundraising_experience_description'] ?? ''),
        'id_image_front'                     => trim($_POST['id_image_front'] ?? ''),
        'id_image_back'                      => trim($_POST['id_image_back'] ?? ''),
        'terms_of_service'                   => isset($_POST['terms_of_service']),
        'no_fraud'                           => isset($_POST['no_fraud']),
        'admin_review'                       => isset($_POST['admin_review']),
    ];

    if ($payload['legal_name'] === '' || $payload['phone'] === '' || $payload['date_of_birth'] === '') {
        $error = 'Legal name, phone number, and date of birth are required.';
    } elseif (!in_array($payload['government_id_type'], $ID_TYPES, true)) {
        $error = 'Please select a valid government ID type.';
    } elseif (!in_array($payload['organization_type'], $ORG_TYPES, true)) {
        $error = 'Please select a valid organization type.';
    } elseif ($payload['website_social'] === '') {
        $error = 'Please provide a website or social media link.';
    } elseif (!in_array($payload['focus_category'], $CATEGORIES, true)) {
        $error = 'Please select a valid campaign focus category.';
    } elseif (!in_array($payload['estimated_goal_range'], $GOAL_RANGES, true)) {
        $error = 'Please select a valid fundraising goal range.';
    } elseif ($payload['campaign_intent'] === '' || strlen($payload['campaign_intent']) < 30) {
        $error = 'Please explain your campaign intent in at least 30 characters.';
    } elseif ($payload['has_fundraising_experience'] && $payload['fundraising_experience_description'] === '') {
        $error = 'Please briefly describe your previous fundraising experience.';
    } elseif (
        $payload['id_image_front'] === '' ||
        $payload['id_image_back'] === '' ||
        ($_SESSION['organizer_id_image_front'] ?? '') !== $payload['id_image_front'] ||
        ($_SESSION['organizer_id_image_back'] ?? '') !== $payload['id_image_back']
    ) {
        $error = 'Please upload both front and back ID photos before submitting.';
    } elseif (!$payload['terms_of_service'] || !$payload['no_fraud'] || !$payload['admin_review']) {
        $error = 'All agreement checkboxes are required.';
    } else {
        try {
            $dob = new DateTimeImmutable($payload['date_of_birth']);
            $age = $dob->diff(new DateTimeImmutable('today'))->y;
            if ($age < 18) {
                $error = 'You must be at least 18 years old to apply as an organizer.';
            }
        } catch (Exception $e) {
            $error = 'Please enter a valid date of birth.';
        }
    }

    if ($error === '') {
        if (!str_starts_with($payload['website_social'], 'http://') && !str_starts_with($payload['website_social'], 'https://')) {
            $payload['website_social'] = 'https://' . $payload['website_social'];
        }

        try {
            if (!saveOrganizerApplication($userId, $payload)) {
                $error = 'Could not save your application. Is MongoDB running?';
            } else {
                $conn->prepare("UPDATE Users SET Role = 'pending_organizer' WHERE UserID = ?")
                     ->execute([$userId]);
                $_SESSION['role'] = 'pending_organizer';
                unset($_SESSION['organizer_id_image_front'], $_SESSION['organizer_id_image_back']);
                $role = 'pending_organizer';
                $success = 'Application submitted. An admin will review it first. After approval, you must connect Stripe before creating campaigns.';
                $latestApplication = getLatestOrganizerApplication($userId);

                try {
                    $admins = $conn->query("SELECT Username, Email FROM Users WHERE Role = 'admin'")->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($admins as $admin) {
                        $adminEmail = trim((string)($admin['Email'] ?? ''));
                        if ($adminEmail === '') continue;
                        sendOrganizerApplicationNoticeToAdmin(
                            $adminEmail,
                            (string)($admin['Username'] ?? 'Admin'),
                            $payload['legal_name'],
                            $payload['focus_category'],
                            $payload['estimated_goal_range']
                        );
                }
                } catch (PDOException $mailEx) {
                    error_log('Admin organizer application email failed: ' . $mailEx->getMessage());
                }
            }
        } catch (PDOException $e) {
            deleteOrganizerApplication($userId);
            $error = 'Application could not be completed due to a database error. Please try again.';
        }
    }
}

$pageTitle = 'Apply as Organizer';
$basePath = '';
require_once 'includes/header.php';
?>

<div class="container py-5" style="max-width:920px;">
    <div class="d-flex align-items-start justify-content-between gap-3 mb-4 flex-wrap">
        <div>
            <h1 class="fw-bold mb-1" style="font-size:1.7rem;">Organizer Application</h1>
            <p class="text-muted mb-0">Tell admins who you are, what you plan to fundraise for, and how they can verify you.</p>
        </div>
        <span class="badge bg-success bg-opacity-10 text-success">
            <i class="bi bi-database me-1"></i>Stored in MongoDB
        </span>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2">
        <i class="bi bi-exclamation-circle me-1"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success py-2">
        <i class="bi bi-check-circle me-1"></i><?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <?php if ($role === 'organizer'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-2">You are already an organizer.</h5>
            <p class="text-muted mb-3">You can create and manage campaigns from your dashboard. Stripe must be connected before submitting a new campaign.</p>
            <a href="my_campaigns.php" class="btn btn-success">Open dashboard</a>
        </div>
    </div>
    <?php elseif ($role === 'pending_organizer'): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex align-items-center gap-3 mb-3">
                <div class="rounded-circle bg-warning bg-opacity-25 text-warning d-flex align-items-center justify-content-center"
                     style="width:46px;height:46px;">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div>
                    <h5 class="fw-bold mb-1">Application pending review</h5>
                    <p class="text-muted small mb-0">
                        Submitted <?= htmlspecialchars($latestApplication['submitted_at'] ?? 'recently') ?>.
                    </p>
                </div>
            </div>
            <p class="text-muted mb-0">You can keep donating while an admin reviews your organizer request.</p>
        </div>
    </div>
    <?php else: ?>

    <?php if (($latestApplication['status'] ?? '') === 'rejected'): ?>
    <div class="alert alert-warning py-2">
        <i class="bi bi-info-circle me-1"></i>
        Your previous application was rejected<?= !empty($latestApplication['admin_decision_notes']) ? ': ' . htmlspecialchars($latestApplication['admin_decision_notes']) : '.' ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="card border-0 shadow-sm" id="organizerApplicationForm" enctype="multipart/form-data">
        <div class="card-body p-4">
            <h5 class="fw-bold mb-3">Identity &amp; Credibility</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Full legal name</label>
                    <input type="text" name="legal_name" class="form-control" value="<?= oldValue('legal_name') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Phone number</label>
                    <input type="tel" name="phone" class="form-control" value="<?= oldValue('phone') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Date of birth</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?= oldValue('date_of_birth') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Government ID type</label>
                    <select name="government_id_type" class="form-select" required>
                        <option value="">Select type</option>
                        <?php foreach ($ID_TYPES as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= selectedValue('government_id_type', $type) ?>><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Government ID photo - front</label>
                    <input type="file" name="id_photo_front" id="id_photo_front" class="form-control"
                           accept="image/jpeg,image/png,image/webp" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Government ID photo - back</label>
                    <input type="file" name="id_photo_back" id="id_photo_back" class="form-control"
                           accept="image/jpeg,image/png,image/webp" required>
                </div>
            </div>

            <h5 class="fw-bold mb-3">Organization Info</h5>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Organization name <span class="text-muted fw-normal">(optional for individuals)</span></label>
                    <input type="text" name="organization_name" class="form-control" value="<?= oldValue('organization_name') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Organization type</label>
                    <select name="organization_type" class="form-select" required>
                        <option value="">Select type</option>
                        <?php foreach ($ORG_TYPES as $type): ?>
                        <option value="<?= htmlspecialchars($type) ?>" <?= selectedValue('organization_type', $type) ?>><?= htmlspecialchars($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold small">Website or social media link</label>
                    <input type="text" name="website_social" class="form-control" placeholder="https://example.org or social profile" value="<?= oldValue('website_social') ?>" required>
                </div>
            </div>

            <h5 class="fw-bold mb-3">Campaign Intent</h5>
            <div class="mb-3">
                <label class="form-label fw-semibold small">Why do you want to create campaigns?</label>
                <textarea name="campaign_intent" class="form-control" rows="4" minlength="30" required><?= oldValue('campaign_intent') ?></textarea>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Campaign focus category</label>
                    <select name="focus_category" class="form-select" required>
                        <option value="">Select category</option>
                        <?php foreach ($CATEGORIES as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>" <?= selectedValue('focus_category', $cat) ?>><?= htmlspecialchars($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold small">Estimated fundraising goal range</label>
                    <select name="estimated_goal_range" class="form-select" required>
                        <option value="">Select range</option>
                        <?php foreach ($GOAL_RANGES as $range): ?>
                        <option value="<?= htmlspecialchars($range) ?>" <?= selectedValue('estimated_goal_range', $range) ?>><?= htmlspecialchars($range) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold small">Have you run fundraising campaigns before?</label>
                <div class="d-flex gap-3 mb-2">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_fundraising_experience" id="exp_no" value="no" <?= ($_POST['has_fundraising_experience'] ?? 'no') !== 'yes' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="exp_no">No</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="has_fundraising_experience" id="exp_yes" value="yes" <?= ($_POST['has_fundraising_experience'] ?? '') === 'yes' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="exp_yes">Yes</label>
                    </div>
                </div>
                <textarea name="fundraising_experience_description" class="form-control" rows="3"
                          placeholder="If yes, briefly describe prior campaigns."><?= oldValue('fundraising_experience_description') ?></textarea>
            </div>

            <h5 class="fw-bold mb-3">Agreements</h5>
            <div class="d-flex flex-column gap-2 mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="terms_of_service" id="terms_of_service" <?= isset($_POST['terms_of_service']) ? 'checked' : '' ?> required>
                    <label class="form-check-label" for="terms_of_service">I agree to UnityFund's Terms of Service.</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="no_fraud" id="no_fraud" <?= isset($_POST['no_fraud']) ? 'checked' : '' ?> required>
                    <label class="form-check-label" for="no_fraud">I declare that I will not create fraudulent or misleading campaigns.</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="admin_review" id="admin_review" <?= isset($_POST['admin_review']) ? 'checked' : '' ?> required>
                    <label class="form-check-label" for="admin_review">I consent to admin review and understand admins can close campaigns.</label>
                </div>
            </div>

            <button type="submit" class="btn btn-success fw-semibold px-4">
                <i class="bi bi-send me-1"></i>Submit application
            </button>
            <span class="ms-3 small" id="applicationUploadMsg"></span>
        </div>
    </form>
    <?php endif; ?>
</div>

<?php if ($role === 'donor'): ?>
<script>
const applicationForm = document.getElementById('organizerApplicationForm');
if (applicationForm) {
    applicationForm.addEventListener('submit', async (event) => {
        if (applicationForm.dataset.ready === '1') return;
        event.preventDefault();

        const msg = document.getElementById('applicationUploadMsg');
        const front = document.getElementById('id_photo_front').files[0];
        const back = document.getElementById('id_photo_back').files[0];
        if (!front || !back) {
            msg.textContent = 'Please choose front and back ID photos.';
            msg.className = 'ms-3 small text-danger';
            return;
        }

        const submitBtn = applicationForm.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        msg.textContent = 'Uploading ID photos...';
        msg.className = 'ms-3 small text-muted';

        try {
            const data = new FormData();
            data.append('front', front);
            data.append('back', back);

            const response = await fetch('api/upload_id_photo.php', {
                method: 'POST',
                body: data
            });
            const result = await response.json().catch(() => ({}));
            if (!response.ok || !result.success) {
                throw new Error(result.error || 'Could not upload ID photos.');
            }

            for (const [name, value] of Object.entries({
                id_image_front: result.id_image_front,
                id_image_back: result.id_image_back
            })) {
                let input = applicationForm.querySelector(`input[name="${name}"]`);
                if (!input) {
                    input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    applicationForm.appendChild(input);
                }
                input.value = value || '';
            }

            applicationForm.dataset.ready = '1';
            msg.textContent = 'ID photos uploaded. Submitting...';
            applicationForm.submit();
        } catch (err) {
            msg.textContent = err.message || 'Upload failed.';
            msg.className = 'ms-3 small text-danger';
            submitBtn.disabled = false;
        }
    });
}
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
