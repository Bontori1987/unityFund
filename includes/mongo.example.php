<?php
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

function getProfile(int $userId): ?array {
    $doc = mongoFindOne('user_profiles', ['user_id' => $userId]);
    if (!$doc) return null;
    return [
        'user_id'    => $doc->user_id    ?? $userId,
        'bio'        => $doc->bio        ?? '',
        'location'   => $doc->location   ?? '',
        'website'    => $doc->website    ?? '',
        'avatar_url' => $doc->avatar_url ?? '',
        'joined_at'  => isset($doc->joined_at)
                            ? $doc->joined_at->toDateTime()->format('F Y')
                            : null,
    ];
}

function saveProfile(int $userId, array $fields): bool {
    $allowed = ['bio', 'location', 'website', 'avatar_url'];
    $data = ['updated_at' => new MongoDB\BSON\UTCDateTime()];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $fields)) {
            $data[$k] = trim((string)$fields[$k]);
        }
    }
    return mongoUpsert('user_profiles', ['user_id' => $userId], $data);
}

function seedProfile(int $userId): void {
    $exists = mongoFindOne('user_profiles', ['user_id' => $userId]);
    if (!$exists) {
        mongoUpsert('user_profiles', ['user_id' => $userId], [
            'bio'        => '',
            'location'   => '',
            'website'    => '',
            'avatar_url' => '',
            'joined_at'  => new MongoDB\BSON\UTCDateTime(),
        ]);
    }
}
