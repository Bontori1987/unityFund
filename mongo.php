<?php
require_once __DIR__ . '/time.php';

// Copy this file to mongo.php and fill in your connection details.
// includes/mongo.php is listed in .gitignore — never commit real credentials.

define('MONGO_URI', 'mongodb://localhost:27017');   // add user:pass if auth enabled
define('MONGO_DB',  'unityfund');

// ── Helpers (copy these as-is) ────────────────────────────────────────────────

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

function saveOrganizerApplication(int $userId, array $fields): bool {
    if ($userId <= 0) return false;

    return mongoInsert('organizer_applications', [
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
            'terms_of_service' => (bool)($fields['terms_of_service'] ?? false),
            'no_fraud'         => (bool)($fields['no_fraud'] ?? false),
            'admin_review'     => (bool)($fields['admin_review'] ?? false),
        ],
        'status'               => 'pending',
        'admin_decision_notes' => '',
        'decided_by'           => null,
        'decided_at'           => null,
        'reviewed_at'          => null,
        'submitted_at'         => mongoNow(),
    ]);
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

//ATTENTION: UPDATING TO ENSURE THE CAMPAIGN TO HAVE 'COMPENSATING TRANSACTION'
function deleteOrganizerApplication(int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $latest = mongoFind('organizer_applications', ['user_id' => $userId, 'status' => 'pending'], [
            'sort'  => ['submitted_at' => -1],
            'limit' => 1,
        ]);
        if (empty($latest) || empty($latest[0]->_id)) return true;
        $bulk = new MongoDB\Driver\BulkWrite();
        $bulk->delete(['_id' => $latest[0]->_id], ['limit' => 1]);
        mongoManager()->executeBulkWrite(MONGO_DB . '.organizer_applications', $bulk);
        return true;
    } catch (Exception $e) {
        return false;
    }
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

//ATTENTION: DEPLOT THE saveCampaignDescription
//TESTING PROCESS: SUCCESS
function saveCampaignDescription(int $campId, string $desc): bool {
    if ($campId <= 0) return false;
    return mongoUpsert('campaign_details', ['camp_id' => $campId], [
        'camp_id'     => $campId,
        'description' => trim($desc),
        'updated_at'  => mongoNow(),
    ]);
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

function getCampaignDetails(int $campId): ?array {
    $doc = mongoFindOne('campaign_details', ['camp_id' => $campId]);
    if (!$doc) return null;

    return [
        'camp_id'     => (int)($doc->camp_id ?? $campId),
        'banner'      => $doc->banner ?? '',
        'thumbnail'   => $doc->thumbnail ?? '',
        'description' => $doc->description ?? '',
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
            'banner'      => $doc->banner ?? '',
            'thumbnail'   => $doc->thumbnail ?? '',
            'description' => $doc->description ?? '',
        ];
    }
    return $map;
}

// ── Notifications (not yet implemented) ──────────────────────────────────────

function getNotifications(int $userId, int $limit = 20): array        { return []; }
function countUnreadNotifications(int $userId): int                    { return 0; }
function markNotificationsRead(int $userId): bool                      { return true; }
function markOneNotificationRead(int $userId, string $notifId): bool   { return true; }
function sendChangeRequest(int $userId, array $data): bool             { return false; }

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
