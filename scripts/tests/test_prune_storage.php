<?php

declare(strict_types=1);

require dirname(__DIR__).'/prune-artifact-storage.php';

$key = 'originals/ab/'.str_repeat('a', 64).'.ipa';
$now = 1000000;
$count = 0;
function check(bool $condition, string $message): void
{
    global $count;
    if (! $condition) {
        throw new RuntimeException($message);
    }
    $count++;
}
check(StoragePrunePolicy::reason($key, [['kind' => 'artifact', 'status' => 'EXPIRED'], ['kind' => 'artifact', 'status' => 'PUBLISHED']], 1, $now) === null, 'Shared published source must survive');
check(StoragePrunePolicy::reason($key, [['kind' => 'artifact', 'status' => 'INSPECTING']], 1, $now) === null, 'In-flight artifact must survive');
check(StoragePrunePolicy::reason($key, [['kind' => 'artifact', 'status' => 'QUARANTINED']], 1, $now) === null, 'Quarantine evidence must survive');
check(StoragePrunePolicy::reason($key, [['kind' => 'artifact', 'status' => 'EXPIRED'], ['kind' => 'artifact', 'status' => 'REJECTED']], $now, $now) === 'terminal_references', 'Explicit obsolete sources can be removed');
check(StoragePrunePolicy::reason($key, [], $now - 100, $now) === null, 'Fresh upload without a row must survive');
check(StoragePrunePolicy::reason($key, [], $now - 86401, $now) === 'unreferenced_older_than_24h', 'Old orphan can be removed');
$signed = 'signed/'.str_repeat('a', 26).'.ipa';
check(StoragePrunePolicy::reason($signed, [['kind' => 'build', 'status' => 'EXPIRED'], ['kind' => 'build', 'status' => 'DELIVERABLE']], 1, $now) === null, 'Usable shared signed build must survive');
check(StoragePrunePolicy::reason($signed, [['kind' => 'build', 'status' => 'SIGNING_PENDING']], 1, $now) === null, 'Retrying build must survive');
foreach (['../credentials.json', 'originals/../../secret.ipa', '/signed/'.str_repeat('a', 26).'.ipa', 'other/file.ipa', $key."\n"] as $unsafe) {
    check(! StoragePrunePolicy::managed($unsafe), 'Only managed object paths are eligible');
}
$md5 = md5('known bytes');
check(StoragePrunePolicy::remoteMd5(['ETag' => '"'.$md5.'"']) === $md5, 'Single-part S3 MD5');
check(StoragePrunePolicy::remoteMd5(['ETag' => '"'.$md5.'-7"']) === null, 'Multipart ETag is not an MD5');
check(StoragePrunePolicy::remoteMd5(['ETag' => '"'.$md5.'-7"', 'Metadata' => ['md5chksum' => base64_encode(hex2bin($md5))]]) === $md5, 'Rclone multipart checksum metadata');
check(StoragePrunePolicy::remoteMd5(['Metadata' => ['md5chksum' => 'not-base64']]) === null, 'Invalid checksum metadata cannot authorize deletion');
check(! StoragePrunePolicy::abandonedMultipart($signed, [['kind' => 'build', 'status' => 'SIGNING']], 1, $now, false), 'Running multipart upload must survive');
check(! StoragePrunePolicy::abandonedMultipart($signed, [['kind' => 'build', 'status' => 'DELIVERABLE']], $now - 10, $now, true), 'Recent multipart parts must survive');
check(StoragePrunePolicy::abandonedMultipart($signed, [['kind' => 'build', 'status' => 'DELIVERABLE']], $now - 4000, $now, true), 'Abandoned attempts for a completed build can be removed');
check(! StoragePrunePolicy::abandonedMultipart($signed, [], $now - 4000, $now, false), 'Fresh unreferenced multipart upload must survive');
echo "{$count} storage protection checks passed\n";
