<?php
require_once __DIR__ . '/time.php';

if (file_exists(__DIR__ . '/mail.local.php')) {
    require_once __DIR__ . '/mail.local.php';
}

require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

if (!defined('MAIL_SMTP_HOST')) {
    define('MAIL_SMTP_HOST', getenv('MAIL_SMTP_HOST') ?: 'smtp.gmail.com');
}
if (!defined('MAIL_SMTP_PORT')) {
    define('MAIL_SMTP_PORT', (int)(getenv('MAIL_SMTP_PORT') ?: 587));
}
if (!defined('MAIL_SMTP_SECURE')) {
    define('MAIL_SMTP_SECURE', getenv('MAIL_SMTP_SECURE') ?: PHPMailer::ENCRYPTION_STARTTLS);
}
if (!defined('MAIL_SMTP_USERNAME')) {
    define('MAIL_SMTP_USERNAME', getenv('MAIL_SMTP_USERNAME') ?: '');
}
if (!defined('MAIL_SMTP_PASSWORD')) {
    define('MAIL_SMTP_PASSWORD', getenv('MAIL_SMTP_PASSWORD') ?: '');
}
if (!defined('MAIL_FROM_EMAIL')) {
    define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: MAIL_SMTP_USERNAME);
}
if (!defined('MAIL_FROM_NAME')) {
    define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'UnityFund');
}
if (!defined('MAIL_REPLY_TO')) {
    define('MAIL_REPLY_TO', getenv('MAIL_REPLY_TO') ?: MAIL_FROM_EMAIL);
}
if (!defined('MAIL_BASE_URL')) {
    define('MAIL_BASE_URL', getenv('MAIL_BASE_URL') ?: 'http://localhost/unityfund');
}

function mailIsConfigured(): bool {
    return MAIL_SMTP_USERNAME !== '' && MAIL_SMTP_PASSWORD !== '' && MAIL_FROM_EMAIL !== '';
}

function mailerInstance(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = MAIL_SMTP_HOST;
    $mail->SMTPAuth = true;
    $mail->Username = MAIL_SMTP_USERNAME;
    $mail->Password = MAIL_SMTP_PASSWORD;
    $mail->SMTPSecure = MAIL_SMTP_SECURE;
    $mail->Port = MAIL_SMTP_PORT;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    if (MAIL_REPLY_TO !== '') {
        $mail->addReplyTo(MAIL_REPLY_TO, MAIL_FROM_NAME);
    }
    $mail->isHTML(true);
    return $mail;
}

function emailMask(string $email): string {
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($local === '' || $domain === '') return $email;
    $visible = substr($local, 0, min(3, strlen($local)));
    return $visible . str_repeat('*', max(strlen($local) - strlen($visible), 2)) . '@' . $domain;
}

function renderEmailLayout(array $data): array {
    $brand = htmlspecialchars($data['brand'] ?? 'UNITYFUND');
    $tag = htmlspecialchars($data['tag'] ?? 'SYSTEM UPDATE');
    $title = htmlspecialchars($data['title'] ?? 'UnityFund update');
    $subtitle = htmlspecialchars($data['subtitle'] ?? '');
    $greeting = htmlspecialchars($data['greeting'] ?? 'Hello,');
    $intro = htmlspecialchars($data['intro'] ?? '');
    $summary = $data['summary'] ?? [];
    $bullets = $data['bullets'] ?? [];
    $ctaText = htmlspecialchars($data['cta_text'] ?? '');
    $ctaUrl = htmlspecialchars($data['cta_url'] ?? '');
    $footer = htmlspecialchars($data['footer'] ?? 'If you did not expect this message, you can ignore it.');

    $summaryRows = '';
    $textSummary = '';
    foreach ($summary as $label => $value) {
        $labelEsc = htmlspecialchars((string)$label);
        $valueEsc = htmlspecialchars((string)$value);
        $summaryRows .= "
            <tr>
                <td style=\"padding:14px 20px;color:#5b6780;border-bottom:1px solid #e8edf5;font-size:14px;\">{$labelEsc}</td>
                <td style=\"padding:14px 20px;color:#0f172a;border-bottom:1px solid #e8edf5;font-size:14px;font-weight:700;text-align:right;\">{$valueEsc}</td>
            </tr>";
        $textSummary .= $label . ': ' . $value . "\n";
    }

    $bulletHtml = '';
    $textBullets = '';
    if (!empty($bullets)) {
        $items = '';
        foreach ($bullets as $bullet) {
            $items .= '<li style="margin:0 0 10px 0;">' . htmlspecialchars((string)$bullet) . '</li>';
            $textBullets .= '- ' . $bullet . "\n";
        }
        $bulletHtml = "
        <div style=\"margin-top:22px;padding:24px;border:1px solid #d7e4f5;border-left:4px solid #18b5b3;border-radius:18px;background:#f7fbff;\">
            <div style=\"font-size:13px;letter-spacing:.08em;font-weight:800;color:#62748e;margin-bottom:12px;\">WHAT TO DO NEXT</div>
            <ul style=\"margin:0;padding-left:20px;color:#132238;font-size:16px;line-height:1.7;\">{$items}</ul>
        </div>";
    }

    $ctaHtml = '';
    $textCta = '';
    if ($ctaText !== '' && $ctaUrl !== '') {
        $ctaHtml = "
        <div style=\"margin-top:28px;\">
            <a href=\"{$ctaUrl}\" style=\"display:inline-block;padding:14px 22px;background:#0ea56b;color:#ffffff;text-decoration:none;border-radius:12px;font-weight:700;\">{$ctaText}</a>
        </div>
        <div style=\"margin-top:12px;color:#738199;font-size:13px;\">If the button does not work, open: {$ctaUrl}</div>";
        $textCta = "\nAction: {$ctaText}\n{$ctaUrl}\n";
    }

    $html = '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $title . '</title>
</head>
<body style="margin:0;padding:32px 16px;background:#eef4fb;font-family:Arial,Helvetica,sans-serif;color:#132238;">
    <div style="max-width:720px;margin:0 auto;background:#ffffff;border-radius:24px;overflow:hidden;border:1px solid #dde6f2;">
        <div style="padding:30px 34px 42px;background:linear-gradient(135deg,#173f77 0%,#17708c 56%,#18b5b3 100%);color:#ffffff;">
            <div style="font-size:22px;font-weight:800;letter-spacing:.08em;">' . $brand . '</div>
            <div style="display:inline-block;margin-top:18px;padding:10px 18px;border-radius:999px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.18);font-size:12px;font-weight:800;letter-spacing:.08em;">' . $tag . '</div>
            <h1 style="margin:24px 0 12px;font-size:52px;line-height:1.06;font-weight:800;">' . $title . '</h1>
            <p style="margin:0;font-size:18px;line-height:1.6;opacity:.95;">' . $subtitle . '</p>
        </div>
        <div style="padding:34px;">
            <div style="display:inline-block;padding:11px 18px;border-radius:999px;background:#ecf4ff;color:#5577a5;font-size:12px;font-weight:800;letter-spacing:.08em;">WELCOME</div>
            <p style="margin:26px 0 10px;font-size:18px;line-height:1.7;">' . $greeting . '</p>
            <p style="margin:0 0 24px;font-size:17px;line-height:1.8;color:#22324b;">' . $intro . '</p>' .
            ($summaryRows !== '' ? '
            <div style="border:1px solid #dce6f2;border-radius:18px;overflow:hidden;background:#ffffff;">
                <table role="presentation" cellspacing="0" cellpadding="0" width="100%" style="border-collapse:collapse;">' . $summaryRows . '
                </table>
            </div>' : '') .
            $bulletHtml .
            $ctaHtml . '
            <p style="margin:28px 0 0;color:#6a768a;font-size:13px;line-height:1.7;">' . $footer . '</p>
        </div>
    </div>
</body>
</html>';

    $text = trim(
        ($data['title'] ?? 'UnityFund update') . "\n" .
        ($data['subtitle'] ?? '') . "\n\n" .
        ($data['greeting'] ?? 'Hello,') . "\n\n" .
        ($data['intro'] ?? '') . "\n\n" .
        $textSummary .
        ($textSummary !== '' ? "\n" : '') .
        $textBullets .
        $textCta .
        "\n" . ($data['footer'] ?? 'If you did not expect this message, you can ignore it.')
    );

    return ['html' => $html, 'text' => $text];
}

function sendEmailMessage(string $toEmail, string $toName, string $subject, string $html, string $text = ''): array {
    if (!mailIsConfigured()) {
        return ['success' => false, 'error' => 'Mail is not configured'];
    }

    try {
        $mail = mailerInstance();
        $mail->addAddress($toEmail, $toName);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text !== '' ? $text : strip_tags($html);
        $mail->send();
        return ['success' => true];
    } catch (Exception $e) {
        error_log('Mail send failed: ' . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function sendTemplatedEmail(string $toEmail, string $toName, string $subject, array $template): array {
    $rendered = renderEmailLayout($template);
    return sendEmailMessage($toEmail, $toName, $subject, $rendered['html'], $rendered['text']);
}

function sendWelcomeEmail(string $toEmail, string $toName, string $roleLabel): array {
    return sendTemplatedEmail($toEmail, $toName, 'Welcome to UnityFund', [
        'tag' => 'ACCOUNT CREATED',
        'title' => 'Welcome to UnityFund',
        'subtitle' => 'Your UnityFund account is ready.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => 'Your account is now active. You can sign in, browse campaigns, and manage fundraising actions from one place.',
        'summary' => [
            'Account email' => $toEmail,
            'Account type' => $roleLabel,
            'Status' => 'Ready to use',
        ],
        'bullets' => [
            'Sign in and complete your profile information.',
            'Browse active campaigns and review recent updates.',
            'If you plan to organize campaigns later, submit the organizer application after sign-in.',
        ],
        'cta_text' => 'Open UnityFund',
        'cta_url' => MAIL_BASE_URL . '/login.php',
        'footer' => 'This mailbox is used for password recovery, donation verification, and important admin notices.',
    ]);
}

function sendPasswordResetEmail(string $toEmail, string $toName, string $resetUrl): array {
    return sendTemplatedEmail($toEmail, $toName, 'Reset your UnityFund password', [
        'tag' => 'PASSWORD RESET',
        'title' => 'Reset your password',
        'subtitle' => 'Use the secure link below to choose a new password.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => 'A password reset was requested for your UnityFund account. The reset link expires in 60 minutes.',
        'summary' => [
            'Account email' => $toEmail,
            'Link validity' => '60 minutes',
            'Requested at' => appNow()->format('M j, Y g:i A'),
        ],
        'bullets' => [
            'Open the reset link and choose a new password.',
            'Ignore this email if you did not request a reset.',
            'Request a fresh link if this one expires.',
        ],
        'cta_text' => 'Reset password',
        'cta_url' => $resetUrl,
        'footer' => 'For security, the old password remains active until the reset is completed.',
    ]);
}

function sendOtpEmail(string $toEmail, string $toName, string $subject, string $title, string $subtitle, string $code, array $summary, array $bullets): array {
    return sendTemplatedEmail($toEmail, $toName, $subject, [
        'tag' => 'EMAIL VERIFICATION',
        'title' => $title,
        'subtitle' => $subtitle,
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => 'Enter the verification code below in UnityFund. The code expires in 10 minutes and can be used once.',
        'summary' => array_merge(['Verification code' => $code], $summary),
        'bullets' => $bullets,
        'footer' => 'If you did not start this action, do not share the code with anyone.',
    ]);
}

function sendRoleChangeEmail(string $toEmail, string $toName, string $subject, string $title, string $intro, array $summary, array $bullets = []): array {
    return sendTemplatedEmail($toEmail, $toName, $subject, [
        'tag' => 'ADMIN NOTICE',
        'title' => $title,
        'subtitle' => 'An admin updated your account status in UnityFund.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => $intro,
        'summary' => $summary,
        'bullets' => $bullets,
        'cta_text' => 'Open dashboard',
        'cta_url' => MAIL_BASE_URL . '/my_campaigns.php',
        'footer' => 'If you need clarification, contact an admin through UnityFund.',
    ]);
}

function sendCampaignChangeRequestEmail(string $toEmail, string $toName, string $campaignTitle, string $changeType, string $message): array {
    $label = $changeType === 'goal' ? 'Funding goal' : 'Campaign title';
    return sendTemplatedEmail($toEmail, $toName, 'Admin requested campaign changes', [
        'tag' => 'CAMPAIGN REVIEW',
        'title' => 'Campaign update required',
        'subtitle' => 'An admin left feedback on one of your campaigns.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => 'Review the admin message, update the campaign, and resubmit if needed.',
        'summary' => [
            'Campaign' => $campaignTitle,
            'Requested area' => $label,
            'Mailbox' => emailMask($toEmail),
        ],
        'bullets' => [
            'Admin note: ' . $message,
            'Open My Campaigns to edit the campaign details.',
            'Keep the campaign accurate before requesting approval again.',
        ],
        'cta_text' => 'Review campaign',
        'cta_url' => MAIL_BASE_URL . '/my_campaigns.php',
    ]);
}

function sendOrganizerApplicationNoticeToAdmin(string $toEmail, string $toName, string $applicantName, string $focusCategory, string $goalRange): array {
    return sendTemplatedEmail($toEmail, $toName, 'New organizer application pending review', [
        'tag' => 'ADMIN REVIEW',
        'title' => 'Organizer application pending',
        'subtitle' => 'A new organizer application requires admin review.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => 'A donor submitted an organizer application. Review it in the admin dashboard and record the decision there.',
        'summary' => [
            'Applicant' => $applicantName,
            'Focus category' => $focusCategory,
            'Goal range' => $goalRange,
        ],
        'bullets' => [
            'Open the Applications tab in the admin dashboard.',
            'Review the identity details and uploaded ID images.',
            'Approve or reject the request and include decision notes if needed.',
        ],
        'cta_text' => 'Open admin dashboard',
        'cta_url' => MAIL_BASE_URL . '/my_campaigns.php',
    ]);
}

function sendCampaignAppealResultEmail(string $toEmail, string $toName, string $campaignTitle, string $decision, string $notes): array {
    $approved = $decision === 'approved';
    return sendTemplatedEmail($toEmail, $toName, $approved ? 'Campaign appeal approved' : 'Campaign appeal rejected', [
        'tag' => 'APPEAL RESULT',
        'title' => $approved ? 'Appeal approved' : 'Appeal rejected',
        'subtitle' => 'An admin reviewed your campaign reopening appeal.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => $approved
            ? 'Your appeal was approved. The campaign has been reopened and can accept donations again.'
            : 'Your appeal was reviewed but the campaign remains closed. Review the admin notes before appealing again.',
        'summary' => [
            'Campaign' => $campaignTitle,
            'Decision' => ucfirst($decision),
            'Admin notes' => $notes !== '' ? $notes : 'No additional notes',
        ],
        'bullets' => $approved
            ? ['Open My Campaigns to confirm the campaign is active again.']
            : ['Update the campaign details if needed before submitting another appeal.'],
        'cta_text' => 'Open My Campaigns',
        'cta_url' => MAIL_BASE_URL . '/my_campaigns.php',
    ]);
}

function sendRoleAppealResultEmail(
    string $toEmail,
    string $toName,
    string $oldRole,
    string $newRole,
    string $decision,
    string $notes
): array {
    $approved = $decision === 'approved';
    return sendTemplatedEmail($toEmail, $toName, $approved ? 'Role appeal approved' : 'Role appeal rejected', [
        'tag' => 'ROLE APPEAL',
        'title' => $approved ? 'Role appeal approved' : 'Role appeal rejected',
        'subtitle' => 'An admin reviewed your appeal about an account role decision.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => $approved
            ? 'Your appeal was approved and your account role was restored.'
            : 'Your appeal was reviewed, but the original role decision remains in effect.',
        'summary' => [
            'Previous role' => ucfirst(str_replace('_', ' ', $oldRole)),
            'Changed role' => ucfirst(str_replace('_', ' ', $newRole)),
            'Appeal result' => ucfirst($decision),
            'Admin notes' => $notes !== '' ? $notes : 'No additional notes',
        ],
        'bullets' => $approved
            ? ['Sign in again if the role badge does not update immediately.']
            : ['Review the admin notes before submitting another appeal.'],
        'cta_text' => 'Open UnityFund',
        'cta_url' => MAIL_BASE_URL . '/inbox.php',
        'footer' => 'This message was generated from the UnityFund role-review workflow.',
    ]);
}

function sendDonationStatusEmail(
    string $toEmail,
    string $toName,
    string $campaignTitle,
    float $amount,
    string $gatewayRef,
    bool $succeeded,
    string $reason = ''
): array {
    return sendTemplatedEmail($toEmail, $toName, $succeeded ? 'Donation confirmed' : 'Donation failed', [
        'tag' => $succeeded ? 'DONATION CONFIRMED' : 'DONATION FAILED',
        'title' => $succeeded ? 'Donation received' : 'Donation not completed',
        'subtitle' => $succeeded
            ? 'Your payment was confirmed and recorded in UnityFund.'
            : 'The payment did not complete and no donation was recorded.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => $succeeded
            ? 'Thank you for supporting a campaign on UnityFund. A confirmation is included below for your records.'
            : 'The donation attempt did not succeed. You can retry from the campaign page when you are ready.',
        'summary' => [
            'Campaign' => $campaignTitle,
            'Amount' => '$' . number_format($amount, 2),
            'Reference' => $gatewayRef,
            'Status' => $succeeded ? 'Succeeded' : 'Failed',
        ] + ($reason !== '' ? ['Failure reason' => $reason] : []),
        'bullets' => $succeeded
            ? [
                'Open the transaction history if you need the full payment record.',
                'Visit the campaign page to see updated funding progress and supporter standings.',
            ]
            : [
                'No money should be captured for this failed attempt.',
                'Review the failure reason and retry only after correcting the issue.',
            ],
        'cta_text' => $succeeded ? 'Open transactions' : 'Try donating again',
        'cta_url' => $succeeded ? MAIL_BASE_URL . '/transactions.php' : MAIL_BASE_URL . '/donate.php',
        'footer' => 'This confirmation was generated automatically by UnityFund.',
    ]);
}

function sendDonationReceiptEmail(
    string $toEmail,
    string $toName,
    string $campaignTitle,
    float $amount,
    string $issuedAt,
    string $receiptId,
    string $gatewayRef
): array {
    return sendTemplatedEmail($toEmail, $toName, 'Tax receipt for your donation', [
        'tag' => 'TAX RECEIPT',
        'title' => 'Your tax receipt is ready',
        'subtitle' => 'UnityFund generated a tax-deduction receipt for your donation above $50.',
        'greeting' => 'Hello ' . $toName . ',',
        'intro' => 'This receipt is generated automatically. No amount is deducted from the donation total shown below.',
        'summary' => [
            'Campaign' => $campaignTitle,
            'Deductible amount' => '$' . number_format($amount, 2),
            'Receipt ID' => $receiptId,
            'Issued at' => $issuedAt,
            'Payment reference' => $gatewayRef,
        ],
        'bullets' => [
            'Keep this receipt for your records.',
            'Open UnityFund receipts to review the full history of issued documents.',
        ],
        'cta_text' => 'Open receipts',
        'cta_url' => MAIL_BASE_URL . '/receipts.php',
        'footer' => 'If the donation amount is incorrect, contact an administrator before using the receipt.',
    ]);
}
