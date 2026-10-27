<?php

mb_test('sync table schema uses the site prefix and unique post rows', function () {
    $sql = MomoBirdWP_SyncRepository::schemaSql('wp_2_', 'DEFAULT CHARSET=utf8mb4');
    mb_assert(strpos($sql, 'CREATE TABLE wp_2_momobird_sync (') === 0, 'table name not prefixed');
    mb_assert(strpos($sql, 'UNIQUE KEY post_row (post_id,row_key)') !== false, 'post rows not unique');
    mb_assert(strpos($sql, 'PRIMARY KEY  (id)') !== false, 'dbDelta needs two spaces after PRIMARY KEY');
});

mb_test('sync table schema rejects unsafe prefixes', function () {
    mb_assert_throws(function () {
        MomoBirdWP_SyncRepository::schemaSql('wp_; DROP TABLE wp_posts;', '');
    }, 'InvalidArgumentException');
});
