<?php
const UNITYFUND_TIMEZONE = 'Asia/Ho_Chi_Minh';

date_default_timezone_set(UNITYFUND_TIMEZONE);

function appTimeZone(): DateTimeZone {
    static $tz = null;
    if (!$tz) $tz = new DateTimeZone(UNITYFUND_TIMEZONE);
    return $tz;
}

function appNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', appTimeZone());
}

function sqlNow(): string {
    return appNow()->format('Y-m-d H:i:s');
}

function mongoNow(): MongoDB\BSON\UTCDateTime {
    return new MongoDB\BSON\UTCDateTime((int)round(microtime(true) * 1000));
}

function mongoNowPlus(int $seconds): MongoDB\BSON\UTCDateTime {
    $millis = ((int)appNow()->format('U') + $seconds) * 1000;
    return new MongoDB\BSON\UTCDateTime($millis);
}

function formatMongoDate($date, string $format = 'M j, Y g:i A'): string {
    if (!$date instanceof MongoDB\BSON\UTCDateTime) return '';
    return $date->toDateTime()->setTimezone(appTimeZone())->format($format);
}

function formatSqlDate($date, string $format = 'M j, Y g:i A'): string {
    if (!$date) return '';
    try {
        return (new DateTimeImmutable((string)$date, appTimeZone()))->format($format);
    } catch (Exception $e) {
        return '';
    }
}
