<?php
require_once __DIR__ . '/time.php';

// Raw MongoDB driver helpers — no Composer required.
// Requires: extension=mongodb in php.ini + php_mongodb.dll in ext/

define('MONGO_URI', getenv('MONGO_URI') ?: 'mongodb://localhost:27017');
define('MONGO_DB',  getenv('MONGO_DB')  ?: 'unityfund');

function mongoManager(): MongoDB\Driver\Manager {
    static $m = null;
    if (!$m) $m = new MongoDB\Driver\Manager(MONGO_URI);
    return $m;
}

function mongoFindOne(string $col, array $filter): ?object {
    try {
        $cursor = mongoManager()->executeQuery(
            MONGO_DB . '.' . $col,
            new MongoDB\Driver\Query($filter, ['limit' => 1])
        );
        $rows = $cursor->toArray();
        return $rows[0] ?? null;
    } catch (Exception $e) {
        return null;
    }
}

function mongoUpsert(string $col, array $filter, array $fields): bool {
    try {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update($filter, ['$set' => $fields], ['upsert' => true]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.' . $col, $bulk);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Fetch a user profile by MS SQL UserID. Returns array or null.
function getProfile(int $userId): ?array {
    $doc = mongoFindOne('user_profiles', ['user_id' => $userId]);
    if (!$doc) return null;
    return [
        'user_id'    => $doc->user_id    ?? $userId,
        'bio'        => $doc->bio        ?? '',
        'location'   => $doc->location   ?? '',
        'website'    => $doc->website    ?? '',
        'avatar_url' => $doc->avatar_url ?? '',
        'joined_at'  => isset($doc->joined_at) ? formatMongoDate($doc->joined_at, 'F Y') : null,
    ];
}

// Create or update a user profile.
function saveProfile(int $userId, array $fields): bool {
    $allowed = ['bio', 'location', 'website', 'avatar_url'];
    $data = ['updated_at' => mongoNow()];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) {
            $data[$k] = trim((string)$fields[$k]);
        }
    }
    return mongoUpsert('user_profiles', ['user_id' => $userId], $data);
}

// Insert a single document (no upsert — each call adds a new doc).
function mongoInsert(string $col, array $doc): bool {
    try {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert($doc);
        mongoManager()->executeBulkWrite(MONGO_DB . '.' . $col, $bulk);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Find multiple documents, returns array of plain objects.
function mongoFind(string $col, array $filter, array $opts = []): array {
    try {
        $cursor = mongoManager()->executeQuery(
            MONGO_DB . '.' . $col,
            new MongoDB\Driver\Query($filter, $opts)
        );
        return $cursor->toArray();
    } catch (Exception $e) {
        return [];
    }
}

// Add a campaign comment or reply. Returns the new MongoDB ObjectId string.
function addCampaignComment(int $campId, int $userId, string $username, string $body, ?string $parentId = null): ?string {
    $body = trim($body);
    if ($campId <= 0 || $userId <= 0 || $body === '' || strlen($body) > 1000) {
        return null;
    }

    $parentObjectId = null;
    if ($parentId !== null && trim($parentId) !== '') {
        try {
            $parentObjectId = new MongoDB\BSON\ObjectId($parentId);
        } catch (Exception $e) {
            return null;
        }

        $parent = mongoFindOne('comments', ['_id' => $parentObjectId, 'camp_id' => $campId]);
        if (!$parent) return null;
    }

    try {
        $id = new MongoDB\BSON\ObjectId();
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert([
            '_id'        => $id,
            'camp_id'    => $campId,
            'user_id'    => $userId,
            'username'   => trim($username) ?: 'User',
            'body'       => $body,
            'parent_id'  => $parentObjectId,
            'created_at' => mongoNow(),
            'edited_at'  => null,
        ]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.comments', $bulk);
        return (string)$id;
    } catch (Exception $e) {
        return null;
    }
}

// Fetch comments for a campaign as a nested thread tree.
function getCampaignComments(int $campId, int $limit = 200): array {
    $docs = mongoFind('comments', ['camp_id' => $campId], [
        'sort'  => ['created_at' => 1],
        'limit' => $limit,
    ]);

    $byId = [];
    $roots = [];
    foreach ($docs as $d) {
        $id = (string)($d->_id ?? '');
        if ($id === '') continue;

        $byId[$id] = [
            'id'         => $id,
            'camp_id'    => (int)($d->camp_id ?? $campId),
            'user_id'    => (int)($d->user_id ?? 0),
            'username'   => $d->username ?? 'User',
            'body'       => $d->body ?? '',
            'parent_id'  => isset($d->parent_id) && $d->parent_id ? (string)$d->parent_id : null,
            'created_at' => isset($d->created_at) ? formatMongoDate($d->created_at) : '',
            'replies'    => [],
        ];
    }

    foreach ($byId as $id => &$comment) {
        $parentId = $comment['parent_id'];
        if ($parentId && isset($byId[$parentId])) {
            $byId[$parentId]['replies'][] =& $comment;
        } else {
            $roots[] =& $comment;
        }
    }
    unset($comment);

    return [
        'total' => count($byId),
        'roots' => $roots,
    ];
}

function getCampaignCommentsFeed(int $campId, int $limit = 200): array {
    $thread = getCampaignComments($campId, $limit);
    return [
        'total' => (int)($thread['total'] ?? 0),
        'roots' => array_map('normalizeCampaignCommentNode', $thread['roots'] ?? []),
    ];
}

function normalizeCampaignCommentNode(array $comment): array {
    return [
        'id' => (string)($comment['id'] ?? ''),
        'camp_id' => (int)($comment['camp_id'] ?? 0),
        'user_id' => (int)($comment['user_id'] ?? 0),
        'username' => (string)($comment['username'] ?? 'User'),
        'body' => (string)($comment['body'] ?? ''),
        'parent_id' => $comment['parent_id'] ?? null,
        'created_at' => (string)($comment['created_at'] ?? ''),
        'replies' => array_map('normalizeCampaignCommentNode', $comment['replies'] ?? []),
    ];
}

function getMostActiveCommentersForCampaigns(array $campIds, int $limit = 8): array {
    $campIds = array_values(array_unique(array_filter(array_map('intval', $campIds), fn($id) => $id > 0)));
    if (empty($campIds)) return [];

    $docs = mongoFind('comments', ['camp_id' => ['$in' => $campIds]], [
        'sort' => ['created_at' => -1],
        'limit' => 2000,
    ]);

    $stats = [];
    foreach ($docs as $doc) {
        $userId = (int)($doc->user_id ?? 0);
        $username = trim((string)($doc->username ?? 'User'));
        if ($userId <= 0 || $username === '') continue;

        if (!isset($stats[$userId])) {
            $stats[$userId] = [
                'user_id' => $userId,
                'username' => $username,
                'comment_count' => 0,
                'campaign_count' => 0,
                'latest_at_ts' => 0,
                'latest_at' => '',
                'campaign_ids' => [],
            ];
        }

        $campId = (int)($doc->camp_id ?? 0);
        $stats[$userId]['comment_count']++;
        if ($campId > 0) {
            $stats[$userId]['campaign_ids'][$campId] = true;
        }

        if (($doc->created_at ?? null) instanceof MongoDB\BSON\UTCDateTime) {
            $ts = $doc->created_at->toDateTime()->getTimestamp();
            if ($ts > $stats[$userId]['latest_at_ts']) {
                $stats[$userId]['latest_at_ts'] = $ts;
                $stats[$userId]['latest_at'] = formatMongoDate($doc->created_at);
            }
        }
    }

    foreach ($stats as &$row) {
        $row['campaign_count'] = count($row['campaign_ids']);
    }
    unset($row);

    usort($stats, function (array $a, array $b): int {
        return [$b['comment_count'], $b['campaign_count'], $b['latest_at_ts']]
            <=> [$a['comment_count'], $a['campaign_count'], $a['latest_at_ts']];
    });

    $top = array_slice($stats, 0, max(1, $limit));
    foreach ($top as &$row) {
        unset($row['campaign_ids'], $row['latest_at_ts']);
    }
    unset($row);
    return $top;
}

// Store a detailed organizer application in MongoDB.
function saveOrganizerApplication(int $userId, array $fields): bool {
    if ($userId <= 0) return false;

    $doc = [
        'user_id'                            => $userId,
        'legal_name'                         => trim((string)($fields['legal_name'] ?? '')),
        'phone'                              => trim((string)($fields['phone'] ?? '')),
        'date_of_birth'                      => trim((string)($fields['date_of_birth'] ?? '')),
        'government_id_type'                 => trim((string)($fields['government_id_type'] ?? '')),
        'organization_name'                  => trim((string)($fields['organization_name'] ?? '')),
        'organization_type'                  => trim((string)($fields['organization_type'] ?? '')),
        'website_social'                     => trim((string)($fields['website_social'] ?? '')),
        'campaign_intent'                    => trim((string)($fields['campaign_intent'] ?? '')),
        'focus_category'                     => trim((string)($fields['focus_category'] ?? '')),
        'estimated_goal_range'               => trim((string)($fields['estimated_goal_range'] ?? '')),
        'has_fundraising_experience'         => (bool)($fields['has_fundraising_experience'] ?? false),
        'fundraising_experience_description' => trim((string)($fields['fundraising_experience_description'] ?? '')),
        'id_image_front'                     => trim((string)($fields['id_image_front'] ?? '')),
        'id_image_back'                      => trim((string)($fields['id_image_back'] ?? '')),
        'agreements'                         => [
            'terms_of_service'   => (bool)($fields['terms_of_service'] ?? false),
            'no_fraud'           => (bool)($fields['no_fraud'] ?? false),
            'admin_review'       => (bool)($fields['admin_review'] ?? false),
        ],
        'status'               => 'pending',
        'admin_decision_notes' => '',
        'decided_by'           => null,
        'decided_at'           => null,
        'reviewed_at'          => null,
        'submitted_at'         => mongoNow(),
    ];

    return mongoInsert('organizer_applications', $doc);
}

function formatOrganizerApplication(?object $doc): ?array {
    if (!$doc) return null;
    return [
        'id'                                  => (string)($doc->_id ?? ''),
        'user_id'                             => (int)($doc->user_id ?? 0),
        'legal_name'                          => $doc->legal_name ?? '',
        'phone'                               => $doc->phone ?? '',
        'date_of_birth'                       => $doc->date_of_birth ?? '',
        'government_id_type'                  => $doc->government_id_type ?? '',
        'organization_name'                   => $doc->organization_name ?? '',
        'organization_type'                   => $doc->organization_type ?? '',
        'website_social'                      => $doc->website_social ?? '',
        'campaign_intent'                     => $doc->campaign_intent ?? '',
        'focus_category'                      => $doc->focus_category ?? '',
        'estimated_goal_range'                => $doc->estimated_goal_range ?? '',
        'has_fundraising_experience'          => (bool)($doc->has_fundraising_experience ?? false),
        'fundraising_experience_description'  => $doc->fundraising_experience_description ?? '',
        'id_image_front'                      => $doc->id_image_front ?? '',
        'id_image_back'                       => $doc->id_image_back ?? '',
        'status'                              => $doc->status ?? 'pending',
        'admin_decision_notes'                => $doc->admin_decision_notes ?? '',
        'submitted_at'                        => isset($doc->submitted_at) ? formatMongoDate($doc->submitted_at) : '',
        'decided_at'                          => isset($doc->decided_at) && $doc->decided_at ? formatMongoDate($doc->decided_at) : '',
    ];
}

function getLatestOrganizerApplication(int $userId): ?array {
    $docs = mongoFind('organizer_applications', ['user_id' => $userId], [
        'sort'  => ['submitted_at' => -1],
        'limit' => 1,
    ]);
    return formatOrganizerApplication($docs[0] ?? null);
}

function getOrganizerApplicationsForUsers(array $userIds): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $userIds = array_filter($userIds, fn($id) => $id > 0);
    if (empty($userIds)) return [];

    $docs = mongoFind('organizer_applications', ['user_id' => ['$in' => $userIds]], [
        'sort' => ['submitted_at' => -1],
    ]);

    $byUser = [];
    foreach ($docs as $doc) {
        $userId = (int)($doc->user_id ?? 0);
        if ($userId > 0 && !isset($byUser[$userId])) {
            $byUser[$userId] = formatOrganizerApplication($doc);
        }
    }
    return $byUser;
}

function updateOrganizerApplicationDecision(int $userId, string $status, string $notes, int $adminId): bool {
    if (!in_array($status, ['approved', 'rejected'], true)) return false;

    try {
        $latest = mongoFind('organizer_applications', ['user_id' => $userId, 'status' => 'pending'], [
            'sort'  => ['submitted_at' => -1],
            'limit' => 1,
        ]);
        if (empty($latest) || empty($latest[0]->_id)) return false;

        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => $latest[0]->_id],
            ['$set' => [
                'status'               => $status,
                'admin_decision_notes' => trim($notes),
                'decided_by'           => $adminId,
                'decided_at'           => mongoNow(),
                'reviewed_at'          => mongoNow(),
            ]]
        );
        mongoManager()->executeBulkWrite(MONGO_DB . '.organizer_applications', $bulk);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function saveCampaignImages(int $campId, string $bannerPath, string $thumbnailPath): bool {
    if ($campId <= 0 || $bannerPath === '' || $thumbnailPath === '') return false;

    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id'    => $campId,
        'banner'     => $bannerPath,
        'thumbnail'  => $thumbnailPath,
        'updated_at' => mongoNow(),
    ]);
}

function saveCampaignDescription(int $campId, string $description): bool {
    if ($campId <= 0) return false;
    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id'     => $campId,
        'description' => trim($description),
        'updated_at'  => mongoNow(),
    ]);
}

function getCampaignDetails(int $campId): ?array {
    $doc = mongoFindOne('campaign_details', ['camp_id' => $campId]);
    if (!$doc) return null;

    return [
        'camp_id'     => (int)($doc->camp_id ?? $campId),
        'banner'      => $doc->banner      ?? '',
        'thumbnail'   => $doc->thumbnail   ?? '',
        'description' => $doc->description ?? '',
        'force_closed_by_admin' => (bool)($doc->force_closed_by_admin ?? false),
        'force_closed_reason' => $doc->force_closed_reason ?? '',
        'force_closed_at' => isset($doc->force_closed_at) && $doc->force_closed_at ? formatMongoDate($doc->force_closed_at) : '',
        'appeal_status' => $doc->appeal_status ?? 'none',
        'appeal_message' => $doc->appeal_message ?? '',
        'appeal_review_notes' => $doc->appeal_review_notes ?? '',
        'appeal_reviewed_at' => isset($doc->appeal_reviewed_at) && $doc->appeal_reviewed_at ? formatMongoDate($doc->appeal_reviewed_at) : '',
    ];
}

function getCampaignDetailsMap(array $campIds): array {
    $campIds = array_values(array_unique(array_filter(array_map('intval', $campIds), fn($id) => $id > 0)));
    if (empty($campIds)) return [];

    $map = [];
    foreach (mongoFind('campaign_details', ['camp_id' => ['$in' => $campIds]]) as $doc) {
        $campId = (int)($doc->camp_id ?? 0);
        if ($campId <= 0) continue;
        $map[$campId] = [
            'camp_id'     => $campId,
            'banner'      => $doc->banner      ?? '',
            'thumbnail'   => $doc->thumbnail   ?? '',
            'description' => $doc->description ?? '',
            'force_closed_by_admin' => (bool)($doc->force_closed_by_admin ?? false),
            'force_closed_reason' => $doc->force_closed_reason ?? '',
            'force_closed_at' => isset($doc->force_closed_at) && $doc->force_closed_at ? formatMongoDate($doc->force_closed_at) : '',
            'appeal_status' => $doc->appeal_status ?? 'none',
            'appeal_message' => $doc->appeal_message ?? '',
            'appeal_review_notes' => $doc->appeal_review_notes ?? '',
            'appeal_reviewed_at' => isset($doc->appeal_reviewed_at) && $doc->appeal_reviewed_at ? formatMongoDate($doc->appeal_reviewed_at) : '',
        ];
    }
    return $map;
}

function markCampaignForceClosedByAdmin(int $campId, int $adminId, string $reason = ''): bool {
    if ($campId <= 0 || $adminId <= 0) return false;
    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id' => $campId,
        'force_closed_by_admin' => true,
        'force_closed_reason' => trim($reason),
        'force_closed_by' => $adminId,
        'force_closed_at' => mongoNow(),
        'appeal_status' => 'none',
        'appeal_message' => '',
        'appeal_submitted_by' => null,
        'appeal_submitted_at' => null,
        'appeal_review_notes' => '',
        'appeal_reviewed_by' => null,
        'appeal_reviewed_at' => null,
        'updated_at' => mongoNow(),
    ]);
}

function clearCampaignForceClosedState(int $campId): bool {
    if ($campId <= 0) return false;
    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id' => $campId,
        'force_closed_by_admin' => false,
        'force_closed_reason' => '',
        'force_closed_by' => null,
        'force_closed_at' => null,
        'appeal_status' => 'none',
        'appeal_message' => '',
        'appeal_submitted_by' => null,
        'appeal_submitted_at' => null,
        'appeal_review_notes' => '',
        'appeal_reviewed_by' => null,
        'appeal_reviewed_at' => null,
        'updated_at' => mongoNow(),
    ]);
}

function submitCampaignAppeal(int $campId, int $organizerId, string $message): bool {
    if ($campId <= 0 || $organizerId <= 0 || trim($message) === '') return false;
    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id' => $campId,
        'appeal_status' => 'pending',
        'appeal_message' => trim($message),
        'appeal_submitted_by' => $organizerId,
        'appeal_submitted_at' => mongoNow(),
        'appeal_review_notes' => '',
        'appeal_reviewed_by' => null,
        'appeal_reviewed_at' => null,
        'updated_at' => mongoNow(),
    ]);
}

function reviewCampaignAppeal(int $campId, int $adminId, string $decision, string $notes): bool {
    if ($campId <= 0 || $adminId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) return false;
    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id' => $campId,
        'appeal_status' => $decision,
        'appeal_review_notes' => trim($notes),
        'appeal_reviewed_by' => $adminId,
        'appeal_reviewed_at' => mongoNow(),
        'updated_at' => mongoNow(),
    ]);
}

function sendCampaignAppealSubmittedNotification(int $organizerId, int $adminId, int $campId, string $campTitle, string $message): bool {
    return mongoInsert('notifications', [
        'to_user_id' => $adminId,
        'from_user_id' => $organizerId,
        'type' => 'campaign_appeal',
        'camp_id' => $campId,
        'camp_title' => $campTitle,
        'message' => $message,
        'read' => false,
        'created_at' => mongoNow(),
    ]);
}

function sendCampaignAppealResultNotification(int $adminId, int $toUserId, int $campId, string $campTitle, string $decision, string $notes): bool {
    $summary = $decision === 'approved'
        ? "Your appeal for {$campTitle} was approved. The campaign is active again."
        : "Your appeal for {$campTitle} was rejected. The campaign remains closed.";
    if (trim($notes) !== '') {
        $summary .= ' Notes: ' . trim($notes);
    }
    return mongoInsert('notifications', [
        'to_user_id' => $toUserId,
        'from_user_id' => $adminId,
        'type' => 'campaign_appeal_result',
        'camp_id' => $campId,
        'camp_title' => $campTitle,
        'decision' => $decision,
        'message' => $summary,
        'read' => false,
        'created_at' => mongoNow(),
    ]);
}

// Mark all notifications as read for a user.
function markNotificationsRead(int $userId): void {
    try {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['to_user_id' => $userId, 'read' => false],
            ['$set' => ['read' => true]],
            ['multi' => true]
        );
        mongoManager()->executeBulkWrite(MONGO_DB . '.notifications', $bulk);
    } catch (Exception $e) {}
}

// Notify a user that their role was changed by an admin.
function sendRoleChangeNotification(int $adminId, int $toUserId, string $oldRole, string $newRole, string $reason): bool {
    return sendRoleChangeNotificationWithDecision($adminId, $toUserId, $oldRole, $newRole, $reason, '');
}

function sendRoleChangeNotificationWithDecision(int $adminId, int $toUserId, string $oldRole, string $newRole, string $reason, string $decisionId): bool {
    $labels = [
        'donor'             => 'Donor',
        'pending_organizer' => 'Pending Organizer',
        'organizer'         => 'Organizer',
        'admin'             => 'Admin',
    ];
    $fromLabel = $labels[$oldRole] ?? ucfirst($oldRole);
    $toLabel   = $labels[$newRole] ?? ucfirst($newRole);

    return mongoInsert('notifications', [
        'to_user_id'   => $toUserId,
        'from_user_id' => $adminId,
        'type'         => 'role_change',
        'old_role'     => $oldRole,
        'new_role'     => $newRole,
        'decision_id'  => $decisionId,
        'message'      => "Your account role has been changed from {$fromLabel} to {$toLabel}. Reason: {$reason}",
        'read'         => false,
        'created_at'   => mongoNow(),
    ]);
}

// Notify an organizer that approval is complete and Stripe must be connected next.
function sendStripeRequiredNotification(int $adminId, int $toUserId): bool {
    return mongoInsert('notifications', [
        'to_user_id'   => $toUserId,
        'from_user_id' => $adminId,
        'type'         => 'stripe_required',
        'message'      => 'Your organizer application was approved. Connect Stripe before creating your first campaign.',
        'read'         => false,
        'created_at'   => mongoNow(),
    ]);
}

// Send a change-request notification from admin to an organizer.
function sendChangeRequest(int $fromUserId, int $toUserId, int $campId, string $campTitle, string $type, string $message): bool {
    return mongoInsert('notifications', [
        'to_user_id'  => $toUserId, 
        'from_user_id'=> $fromUserId,
        'type'        => 'change_request',
        'camp_id'     => $campId,
        'camp_title'  => $campTitle,
        'change_type' => $type,   // 'name' or 'goal'
        'message'     => $message,
        'read'        => false,
        'created_at'  => mongoNow(),
    ]);
}

// Get unread notifications for a user, newest first.
function getNotifications(int $userId, bool $unreadOnly = false): array {
    $filter = ['to_user_id' => $userId];
    if ($unreadOnly) $filter['read'] = false;
    $docs = mongoFind('notifications', $filter, ['sort' => ['created_at' => -1], 'limit' => 50]);
    return array_map(fn($d) => [
        'id'          => (string)($d->_id ?? ''),
        'type'        => $d->type        ?? '',
        'camp_id'     => $d->camp_id     ?? 0,
        'camp_title'  => $d->camp_title  ?? '',
        'change_type' => $d->change_type ?? '',
        'message'     => $d->message     ?? '',
        'read'        => $d->read        ?? false,
        'created_at'  => isset($d->created_at) ? formatMongoDate($d->created_at) : '',
    ], $docs);
}

// Count unread notifications for a user (used for header badge).
function countUnreadNotifications(int $userId): int {
    try {
        $cmd    = new MongoDB\Driver\Command(['count' => 'notifications', 'query' => ['to_user_id' => $userId, 'read' => false]]);
        $result = mongoManager()->executeCommand(MONGO_DB, $cmd)->toArray();
        return (int)($result[0]->n ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

// Mark a single notification as read by its string _id.
function markOneNotificationRead(string $id): void {
    try {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            ['$set' => ['read' => true]]
        );
        mongoManager()->executeBulkWrite(MONGO_DB . '.notifications', $bulk);
    } catch (Exception $e) {}
}

// Save or update a Stripe Connect account ID for an organizer.
function saveStripeAccountId(int $userId, string $stripeAccountId): bool {
    return mongoUpsert('user_profiles', ['user_id' => $userId], [
        'stripe_account_id'       => $stripeAccountId,
        'stripe_onboarded'        => false,
        'stripe_account_updated'  => mongoNow(),
    ]);
}

// Mark a Stripe account as fully onboarded (charges_enabled = true).
function markStripeOnboarded(int $userId): bool {
    return mongoUpsert('user_profiles', ['user_id' => $userId], [
        'stripe_onboarded' => true,
        'stripe_account_updated' => mongoNow(),
    ]);
}

// Clear saved Stripe Connect linkage so onboarding can be redone from scratch.
function clearStripeAccount(int $userId): bool {
    return mongoUpsert('user_profiles', ['user_id' => $userId], [
        'stripe_account_id' => '',
        'stripe_onboarded' => false,
        'stripe_account_updated' => mongoNow(),
    ]);
}

// Get Stripe Connect account ID + onboarding status for a user.
function getStripeAccount(int $userId): array {
    $doc = mongoFindOne('user_profiles', ['user_id' => $userId]);
    return [
        'account_id' => $doc->stripe_account_id ?? '',
        'onboarded'  => (bool)($doc->stripe_onboarded ?? false),
    ];
}

function createEmailOtpChallenge(int $userId, string $email, string $purpose, array $payload = [], int $ttlSeconds = 600): ?array {
    if ($userId <= 0 || $email === '' || $purpose === '') return null;

    try {
        $id = new MongoDB\BSON\ObjectId();
        $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert([
            '_id' => $id,
            'user_id' => $userId,
            'email' => $email,
            'purpose' => $purpose,
            'payload' => $payload,
            'code_hash' => password_hash($code, PASSWORD_DEFAULT),
            'verified' => false,
            'consumed' => false,
            'attempts' => 0,
            'created_at' => mongoNow(),
            'expires_at' => mongoNowPlus($ttlSeconds),
        ]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.email_otp_challenges', $bulk);
        return ['challenge_id' => (string)$id, 'code' => $code];
    } catch (Exception $e) {
        error_log('createEmailOtpChallenge failed: ' . $e->getMessage());
        return null;
    }
}

function verifyEmailOtpChallenge(int $userId, string $challengeId, string $purpose, string $code): array {
    try {
        $doc = mongoFindOne('email_otp_challenges', [
            '_id' => new MongoDB\BSON\ObjectId($challengeId),
            'user_id' => $userId,
            'purpose' => $purpose,
            'consumed' => false,
        ]);
        if (!$doc) return ['success' => false, 'error' => 'Verification request not found'];
        if (!($doc->expires_at ?? null) instanceof MongoDB\BSON\UTCDateTime || $doc->expires_at->toDateTime()->getTimestamp() < time()) {
            return ['success' => false, 'error' => 'Verification code expired'];
        }
        if ((int)($doc->attempts ?? 0) >= 5) {
            return ['success' => false, 'error' => 'Too many incorrect attempts'];
        }
        if (!password_verify($code, (string)($doc->code_hash ?? ''))) {
            $bulk = new MongoDB\Driver\BulkWrite();
            $bulk->update(['_id' => $doc->_id], ['$inc' => ['attempts' => 1]]);
            mongoManager()->executeBulkWrite(MONGO_DB . '.email_otp_challenges', $bulk);
            return ['success' => false, 'error' => 'Incorrect verification code'];
        }
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(['_id' => $doc->_id], ['$set' => ['verified' => true, 'verified_at' => mongoNow()]]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.email_otp_challenges', $bulk);
        return ['success' => true];
    } catch (Exception $e) {
        error_log('verifyEmailOtpChallenge failed: ' . $e->getMessage());
        return ['success' => false, 'error' => 'Could not verify OTP right now'];
    }
}

function getVerifiedEmailOtpChallenge(int $userId, string $challengeId, string $purpose): ?array {
    try {
        $doc = mongoFindOne('email_otp_challenges', [
            '_id' => new MongoDB\BSON\ObjectId($challengeId),
            'user_id' => $userId,
            'purpose' => $purpose,
            'verified' => true,
            'consumed' => false,
        ]);
        if (!$doc) return null;
        if (!($doc->expires_at ?? null) instanceof MongoDB\BSON\UTCDateTime || $doc->expires_at->toDateTime()->getTimestamp() < time()) {
            return null;
        }
        return [
            'id' => (string)$doc->_id,
            'payload' => (array)($doc->payload ?? []),
            'email' => (string)($doc->email ?? ''),
        ];
    } catch (Exception $e) {
        return null;
    }
}

function consumeEmailOtpChallenge(int $userId, string $challengeId, string $purpose): bool {
    try {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            [
                '_id' => new MongoDB\BSON\ObjectId($challengeId),
                'user_id' => $userId,
                'purpose' => $purpose,
                'verified' => true,
                'consumed' => false,
            ],
            ['$set' => ['consumed' => true, 'consumed_at' => mongoNow()]]
        );
        $result = mongoManager()->executeBulkWrite(MONGO_DB . '.email_otp_challenges', $bulk);
        return $result->getModifiedCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function createPasswordResetToken(int $userId, string $email, int $ttlSeconds = 3600): ?array {
    if ($userId <= 0 || $email === '') return null;
    try {
        $selector = bin2hex(random_bytes(8));
        $token = bin2hex(random_bytes(16));
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert([
            'selector' => $selector,
            'token_hash' => password_hash($token, PASSWORD_DEFAULT),
            'user_id' => $userId,
            'email' => $email,
            'used' => false,
            'created_at' => mongoNow(),
            'expires_at' => mongoNowPlus($ttlSeconds),
        ]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.password_reset_tokens', $bulk);
        return ['selector' => $selector, 'token' => $token];
    } catch (Exception $e) {
        error_log('createPasswordResetToken failed: ' . $e->getMessage());
        return null;
    }
}

function validatePasswordResetToken(string $selector, string $token): ?array {
    if ($selector === '' || $token === '') return null;
    $doc = mongoFindOne('password_reset_tokens', ['selector' => $selector, 'used' => false]);
    if (!$doc) return null;
    if (!($doc->expires_at ?? null) instanceof MongoDB\BSON\UTCDateTime || $doc->expires_at->toDateTime()->getTimestamp() < time()) {
        return null;
    }
    if (!password_verify($token, (string)($doc->token_hash ?? ''))) {
        return null;
    }
    return [
        'selector' => $selector,
        'user_id' => (int)($doc->user_id ?? 0),
        'email' => (string)($doc->email ?? ''),
    ];
}

function consumePasswordResetToken(string $selector, string $token): ?array {
    $data = validatePasswordResetToken($selector, $token);
    if (!$data) return null;
    try {
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['selector' => $selector, 'used' => false],
            ['$set' => ['used' => true, 'used_at' => mongoNow()]]
        );
        $result = mongoManager()->executeBulkWrite(MONGO_DB . '.password_reset_tokens', $bulk);
        return $result->getModifiedCount() > 0 ? $data : null;
    } catch (Exception $e) {
        return null;
    }
}

// Seed a blank profile when a user first registers.
function seedProfile(int $userId): void {
    $exists = mongoFindOne('user_profiles', ['user_id' => $userId]);
    if (!$exists) {
        mongoUpsert('user_profiles', ['user_id' => $userId], [
            'bio'        => '',
            'location'   => '',
            'website'    => '',
            'avatar_url' => '',
            'joined_at'  => mongoNow(),
        ]);
    }
}

function createRoleChangeDecision(int $userId, int $adminId, string $oldRole, string $newRole, string $reason): ?string {
    if ($userId <= 0 || $adminId <= 0 || trim($reason) === '') return null;
    try {
        $id = new MongoDB\BSON\ObjectId();
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->insert([
            '_id' => $id,
            'user_id' => $userId,
            'admin_id' => $adminId,
            'old_role' => $oldRole,
            'new_role' => $newRole,
            'reason' => trim($reason),
            'created_at' => mongoNow(),
            'appeal_status' => 'none',
            'appeal_message' => '',
            'appeal_submitted_at' => null,
            'appeal_review_notes' => '',
            'appeal_reviewed_by' => null,
            'appeal_reviewed_at' => null,
        ]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.role_change_decisions', $bulk);
        return (string)$id;
    } catch (Exception $e) {
        error_log('createRoleChangeDecision failed: ' . $e->getMessage());
        return null;
    }
}

function getRoleChangeDecisionsMap(array $decisionIds): array {
    $ids = [];
    foreach ($decisionIds as $id) {
        $id = trim((string)$id);
        if ($id === '') continue;
        try {
            $ids[] = new MongoDB\BSON\ObjectId($id);
        } catch (Exception $e) {}
    }
    if (empty($ids)) return [];

    $map = [];
    foreach (mongoFind('role_change_decisions', ['_id' => ['$in' => $ids]]) as $doc) {
        $key = (string)($doc->_id ?? '');
        if ($key === '') continue;
        $map[$key] = [
            'id' => $key,
            'user_id' => (int)($doc->user_id ?? 0),
            'admin_id' => (int)($doc->admin_id ?? 0),
            'old_role' => (string)($doc->old_role ?? ''),
            'new_role' => (string)($doc->new_role ?? ''),
            'reason' => (string)($doc->reason ?? ''),
            'appeal_status' => (string)($doc->appeal_status ?? 'none'),
            'appeal_message' => (string)($doc->appeal_message ?? ''),
            'appeal_review_notes' => (string)($doc->appeal_review_notes ?? ''),
            'created_at' => isset($doc->created_at) ? formatMongoDate($doc->created_at) : '',
            'appeal_submitted_at' => isset($doc->appeal_submitted_at) && $doc->appeal_submitted_at ? formatMongoDate($doc->appeal_submitted_at) : '',
            'appeal_reviewed_at' => isset($doc->appeal_reviewed_at) && $doc->appeal_reviewed_at ? formatMongoDate($doc->appeal_reviewed_at) : '',
        ];
    }
    return $map;
}

function submitRoleChangeAppeal(string $decisionId, int $userId, string $message): bool {
    if ($decisionId === '' || $userId <= 0 || trim($message) === '') return false;
    try {
        $oid = new MongoDB\BSON\ObjectId($decisionId);
        $doc = mongoFindOne('role_change_decisions', ['_id' => $oid, 'user_id' => $userId]);
        if (!$doc) return false;
        if (($doc->appeal_status ?? 'none') === 'pending') return false;

        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => $oid, 'user_id' => $userId],
            ['$set' => [
                'appeal_status' => 'pending',
                'appeal_message' => trim($message),
                'appeal_submitted_at' => mongoNow(),
                'appeal_review_notes' => '',
                'appeal_reviewed_by' => null,
                'appeal_reviewed_at' => null,
            ]]
        );
        mongoManager()->executeBulkWrite(MONGO_DB . '.role_change_decisions', $bulk);
        return true;
    } catch (Exception $e) {
        error_log('submitRoleChangeAppeal failed: ' . $e->getMessage());
        return false;
    }
}

function getPendingRoleChangeAppeals(int $limit = 50): array {
    $docs = mongoFind('role_change_decisions', ['appeal_status' => 'pending'], [
        'sort' => ['appeal_submitted_at' => -1],
        'limit' => $limit,
    ]);
    $items = [];
    foreach ($docs as $doc) {
        $items[] = [
            'id' => (string)($doc->_id ?? ''),
            'user_id' => (int)($doc->user_id ?? 0),
            'admin_id' => (int)($doc->admin_id ?? 0),
            'old_role' => (string)($doc->old_role ?? ''),
            'new_role' => (string)($doc->new_role ?? ''),
            'reason' => (string)($doc->reason ?? ''),
            'appeal_message' => (string)($doc->appeal_message ?? ''),
            'created_at' => isset($doc->created_at) ? formatMongoDate($doc->created_at) : '',
            'appeal_submitted_at' => isset($doc->appeal_submitted_at) ? formatMongoDate($doc->appeal_submitted_at) : '',
        ];
    }
    return $items;
}

function reviewRoleChangeAppeal(string $decisionId, int $adminId, string $decision, string $notes): bool {
    if ($decisionId === '' || $adminId <= 0 || !in_array($decision, ['approved', 'rejected'], true)) return false;
    try {
        $oid = new MongoDB\BSON\ObjectId($decisionId);
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->update(
            ['_id' => $oid, 'appeal_status' => 'pending'],
            ['$set' => [
                'appeal_status' => $decision,
                'appeal_review_notes' => trim($notes),
                'appeal_reviewed_by' => $adminId,
                'appeal_reviewed_at' => mongoNow(),
            ]]
        );
        $result = mongoManager()->executeBulkWrite(MONGO_DB . '.role_change_decisions', $bulk);
        return $result->getModifiedCount() > 0;
    } catch (Exception $e) {
        error_log('reviewRoleChangeAppeal failed: ' . $e->getMessage());
        return false;
    }
}

function sendRoleAppealResultNotification(int $adminId, int $toUserId, string $decisionId, string $decision, string $notes): bool {
    $message = $decision === 'approved'
        ? 'Your role-change appeal was approved.'
        : 'Your role-change appeal was rejected.';
    if (trim($notes) !== '') {
        $message .= ' Notes: ' . trim($notes);
    }
    return mongoInsert('notifications', [
        'to_user_id' => $toUserId,
        'from_user_id' => $adminId,
        'type' => 'role_appeal_result',
        'decision_id' => $decisionId,
        'decision' => $decision,
        'message' => $message,
        'read' => false,
        'created_at' => mongoNow(),
    ]);
}
