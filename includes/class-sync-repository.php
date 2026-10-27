<?php

defined('ABSPATH') || exit;

// Sync state in {$wpdb->prefix}momobird_sync: one row per post (row_key
// '_post') plus bookkeeping rows on post_id 0 for the full sync run. Never
// stores post bodies.
final class MomoBirdWP_SyncRepository
{
    const POST_ROW = '_post';
    // A pending row younger than this belongs to a request that may still be flushing.
    const PENDING_GRACE_SECONDS = 600;

    private $db;
    private $table;

    public function __construct($wpdb)
    {
        $this->db = $wpdb;
        $this->table = $wpdb->prefix . 'momobird_sync';
    }

    public static function schemaSql($prefix, $charsetCollate)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/', (string) $prefix)) {
            throw new InvalidArgumentException('Database prefix is unsafe');
        }
        $table = $prefix . 'momobird_sync';
        // dbDelta wants two spaces after PRIMARY KEY and one column per line.
        return "CREATE TABLE {$table} (
id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
post_id bigint(20) unsigned NOT NULL,
row_key varchar(64) NOT NULL,
external_id varchar(256) NOT NULL,
document_id varchar(64) NULL,
content_hash char(64) NOT NULL DEFAULT '',
sync_status varchar(32) NOT NULL,
attempts int(10) unsigned NOT NULL DEFAULT 0,
last_error_code varchar(64) NULL,
last_error_message varchar(255) NULL,
last_attempt_at bigint(20) NOT NULL DEFAULT 0,
last_success_at bigint(20) NOT NULL DEFAULT 0,
PRIMARY KEY  (id),
UNIQUE KEY post_row (post_id,row_key),
KEY sync_status (sync_status)
) {$charsetCollate};";
    }

    public function forPost($postId)
    {
        return $this->row((int) $postId, self::POST_ROW);
    }

    public function saveSynced($postId, $documentId, $hash)
    {
        $this->upsert($postId, array(
            'row_key' => self::POST_ROW,
            'document_id' => (string) $documentId,
            'content_hash' => (string) $hash,
            'sync_status' => 'synced',
        ), false);
    }

    // markPending records that a post changed and is about to be pushed; the
    // synced hash is kept so an unchanged post is not re-sent.
    public function markPending($postId, $action)
    {
        $existing = $this->forPost($postId);
        $this->upsert($postId, array(
            'row_key' => self::POST_ROW,
            'document_id' => $existing ? $existing['document_id'] : null,
            'content_hash' => $existing ? (string) $existing['content_hash'] : '',
            'sync_status' => $action === 'delete' ? 'pending_delete' : 'pending_upsert',
            'attempts' => $existing ? (int) $existing['attempts'] : 0,
        ), false);
    }

    public function markFailure($postId, $status, $code, $message)
    {
        $existing = $this->forPost($postId);
        $this->upsert($postId, array(
            'row_key' => self::POST_ROW,
            'document_id' => $existing ? $existing['document_id'] : null,
            'content_hash' => '',
            'sync_status' => (string) $status,
            'last_error_code' => mb_substr((string) $code, 0, 64, 'UTF-8'),
            'last_error_message' => mb_substr((string) $message, 0, 255, 'UTF-8'),
        ), true);
    }

    public function remove($postId, $rowKey = self::POST_ROW)
    {
        $this->db->delete($this->table, array('post_id' => (int) $postId, 'row_key' => (string) $rowKey), array('%d', '%s'));
    }

    public function failed($afterId = 0, $limit = 200)
    {
        return $this->db->get_results($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE post_id > 0 AND id > %d AND sync_status NOT IN ('synced','skipped_empty')
             AND NOT (sync_status LIKE 'pending\\_%%' AND last_attempt_at > %d)
             ORDER BY id ASC LIMIT %d",
            max(0, (int) $afterId),
            time() - self::PENDING_GRACE_SECONDS,
            min(200, max(1, (int) $limit))
        ), ARRAY_A);
    }

    // pending returns every row a save marked but no push has settled yet.
    public function pending()
    {
        return $this->db->get_results(
            "SELECT * FROM {$this->table} WHERE post_id > 0 AND sync_status IN ('pending_upsert','pending_delete') ORDER BY id ASC LIMIT 200",
            ARRAY_A
        );
    }

    public function counts()
    {
        $rows = $this->db->get_results("SELECT sync_status, COUNT(*) AS count FROM {$this->table} WHERE post_id > 0 GROUP BY sync_status", ARRAY_A);
        $counts = array('synced' => 0, 'failed_upsert' => 0, 'failed_delete' => 0, 'plan_required' => 0, 'pending_upsert' => 0, 'pending_delete' => 0, 'skipped_empty' => 0);
        foreach ((array) $rows as $row) {
            $counts[$row['sync_status']] = (int) $row['count'];
        }
        return $counts;
    }

    public function allPostIds()
    {
        $ids = array();
        foreach ((array) $this->db->get_col($this->db->prepare("SELECT post_id FROM {$this->table} WHERE post_id > 0 AND row_key = %s", self::POST_ROW)) as $id) {
            $ids[(int) $id] = true;
        }
        return $ids;
    }

    public function syncedPostCount()
    {
        return (int) $this->db->get_var("SELECT COUNT(DISTINCT post_id) FROM {$this->table} WHERE post_id > 0 AND sync_status = 'synced'");
    }

    public function lastFullSyncAt()
    {
        $row = $this->row(0, '_last_full_sync');
        return $row ? (int) $row['last_success_at'] : 0;
    }

    public function recentError()
    {
        $row = $this->db->get_row("SELECT last_error_code, last_error_message, last_attempt_at FROM {$this->table}
            WHERE post_id > 0 AND sync_status NOT IN ('synced','skipped_empty','pending_upsert','pending_delete') ORDER BY last_attempt_at DESC LIMIT 1", ARRAY_A);
        if (!is_array($row)) {
            return null;
        }
        return array(
            'code' => $row['last_error_code'] !== null ? (string) $row['last_error_code'] : 'sync_error',
            'message' => (string) $row['last_error_message'],
            'at' => (int) $row['last_attempt_at'],
        );
    }

    public function startRun($token)
    {
        $this->upsert(0, array('row_key' => '_run', 'external_id' => (string) $token, 'content_hash' => '0', 'sync_status' => 'running'), false);
    }

    public function expectedRunCursor($token)
    {
        if (!$this->runIsActive($token)) {
            throw new RuntimeException('Full sync run is invalid or expired');
        }
        $cursor = (string) $this->row(0, '_run')['content_hash'];
        if (!ctype_digit($cursor)) {
            throw new RuntimeException('Full sync cursor state is invalid');
        }
        return (int) $cursor;
    }

    public function advanceRun($token, $expected, $next)
    {
        if ($this->expectedRunCursor($token) !== (int) $expected || (int) $next < (int) $expected) {
            throw new RuntimeException('Full sync cursor is out of sequence');
        }
        $row = $this->row(0, '_run');
        $row['content_hash'] = (string) (int) $next;
        $this->upsert(0, $row, false);
    }

    public function runIsActive($token)
    {
        $row = $this->row(0, '_run');
        return is_array($row)
            && in_array($row['sync_status'], array('running', 'cleanup_allowed'), true)
            && (int) $row['last_attempt_at'] >= time() - 7200
            && hash_equals((string) $row['external_id'], (string) $token);
    }

    public function allowCleanup($token, $expectedCursor)
    {
        if ($this->expectedRunCursor($token) !== (int) $expectedCursor) {
            throw new RuntimeException('Full sync run is invalid or expired');
        }
        $row = $this->row(0, '_run');
        $row['sync_status'] = 'cleanup_allowed';
        $this->upsert(0, $row, false);
    }

    public function cleanupIsAllowed($token)
    {
        $row = $this->row(0, '_run');
        return $this->runIsActive($token) && $row['sync_status'] === 'cleanup_allowed';
    }

    public function finishRun($token)
    {
        if (!$this->cleanupIsAllowed($token)) {
            throw new RuntimeException('Cleanup is not authorized for this run');
        }
        $this->upsert(0, array('row_key' => '_last_full_sync', 'external_id' => 'last-full-sync', 'sync_status' => 'synced'), false);
        $this->remove(0, '_run');
    }

    private function row($postId, $rowKey)
    {
        $row = $this->db->get_row($this->db->prepare(
            "SELECT * FROM {$this->table} WHERE post_id = %d AND row_key = %s LIMIT 1",
            (int) $postId,
            (string) $rowKey
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    private function upsert($postId, array $mapping, $incrementAttempts)
    {
        $existing = $this->row((int) $postId, (string) $mapping['row_key']);
        $now = time();
        $status = isset($mapping['sync_status']) ? $mapping['sync_status'] : 'synced';
        $attempts = isset($mapping['attempts']) ? (int) $mapping['attempts'] : 0;
        if ($incrementAttempts) {
            $attempts = ($existing ? (int) $existing['attempts'] : 0) + 1;
        }
        $row = array(
            'post_id' => (int) $postId,
            'row_key' => (string) $mapping['row_key'],
            'external_id' => isset($mapping['external_id']) ? (string) $mapping['external_id'] : (string) (int) $postId,
            'document_id' => !empty($mapping['document_id']) ? (string) $mapping['document_id'] : null,
            'content_hash' => isset($mapping['content_hash']) ? (string) $mapping['content_hash'] : '',
            'sync_status' => $status,
            'attempts' => $attempts,
            'last_error_code' => isset($mapping['last_error_code']) ? $mapping['last_error_code'] : null,
            'last_error_message' => isset($mapping['last_error_message']) ? $mapping['last_error_message'] : null,
            'last_attempt_at' => $now,
            'last_success_at' => $status === 'synced' ? $now : 0,
        );
        if ($existing) {
            $this->db->update($this->table, $row, array('id' => (int) $existing['id']));
        } else {
            $this->db->insert($this->table, $row);
        }
    }
}
