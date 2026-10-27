<?php

class MomoBirdWP_TestSource
{
    public function load($id)
    {
        return mb_wp_post('<p>body ' . $id . '</p>', $id);
    }
}

function mb_wp_object($id, $type, $status, $password = '')
{
    $post = (object) array('ID' => $id, 'post_type' => $type, 'post_status' => $status, 'post_password' => $password);
    $GLOBALS['mb_posts'][$id] = $post;
    return $post;
}

function mb_plugin($autoSync = true)
{
    $state = new MomoBirdWP_TestStateRepository();
    $client = new MomoBirdWP_TestClient();
    $plugin = new MomoBirdWP_Plugin($state, new MomoBirdWP_TestSource(), function () use ($client, $state, $autoSync) {
        return $autoSync ? mb_sync_service($client, $state) : null;
    });
    return array($plugin, $state, $client);
}

function mb_hooks()
{
    $hooks = array();
    foreach ($GLOBALS['mb_actions'] as $action) {
        $hooks[] = $action[0];
    }
    return $hooks;
}

mb_test('plugin registers its sync hooks', function () {
    $GLOBALS['mb_actions'] = array();
    MomoBirdWP_Plugin::register(MOMOBIRD_AI_TEST_FILE);
    foreach (array('transition_post_status', 'wp_trash_post', 'before_delete_post', 'shutdown', 'momobird_ai_retry', 'activate:momobird-ai.php', 'deactivate:momobird-ai.php') as $hook) {
        mb_assert(in_array($hook, mb_hooks(), true), $hook . ' not registered');
    }
});

mb_test('activation creates the table and schedules hourly retries', function () {
    $GLOBALS['mb_dbdelta'] = array();
    $GLOBALS['mb_scheduled'] = array();
    $GLOBALS['mb_multisite'] = false;
    $GLOBALS['wpdb'] = new MomoBirdWP_TestWpdb();
    MomoBirdWP_Plugin::activate(false);
    mb_assert(strpos($GLOBALS['mb_dbdelta'][0], 'CREATE TABLE wp_momobird_sync') === 0, 'table not created');
    mb_assert_same('hourly', $GLOBALS['mb_scheduled']['momobird_ai_retry']['recurrence'], 'retry not scheduled hourly');
});

mb_test('network activation is refused', function () {
    $GLOBALS['mb_dbdelta'] = array();
    $GLOBALS['mb_multisite'] = true;
    $GLOBALS['wpdb'] = new MomoBirdWP_TestWpdb();
    $error = mb_assert_throws(function () {
        MomoBirdWP_Plugin::activate(true);
    }, 'MomoBirdWP_TestDie');
    $GLOBALS['mb_multisite'] = false;
    mb_assert(strpos($error->getMessage(), '网络启用') !== false, 'refusal message missing');
    mb_assert_same(array(), $GLOBALS['mb_dbdelta'], 'table created on network activation');
    mb_assert_same('momobird-ai/' . basename(MOMOBIRD_AI_TEST_FILE), $GLOBALS['mb_deactivated'], 'plugin not deactivated');
});

mb_test('deactivation clears the retry schedule', function () {
    $GLOBALS['mb_scheduled'] = array('momobird_ai_retry' => array('time' => 1, 'recurrence' => 'hourly'));
    MomoBirdWP_Plugin::deactivate();
    mb_assert_same(false, wp_next_scheduled('momobird_ai_retry'), 'retry still scheduled');
});

mb_test('publishing queues an upsert and marks it pending', function () {
    list($plugin, $state) = mb_plugin();
    $plugin->onTransition('publish', 'draft', mb_wp_object(10, 'post', 'publish'));
    mb_assert_same(array(10 => 'upsert'), $plugin->queue->drain(), 'publish not queued');
    mb_assert_same('pending_upsert', $state->rows[10]['sync_status'], 'pending not recorded');
});

mb_test('scheduled post is pushed when cron publishes it', function () {
    list($plugin) = mb_plugin();
    $plugin->onTransition('publish', 'future', mb_wp_object(11, 'post', 'publish'));
    mb_assert_same(array(11 => 'upsert'), $plugin->queue->drain(), 'scheduled publish not queued');
});

mb_test('leaving publish queues a delete; draft edits queue nothing', function () {
    list($plugin) = mb_plugin();
    $plugin->onTransition('private', 'publish', mb_wp_object(12, 'post', 'private'));
    $plugin->onTransition('draft', 'draft', mb_wp_object(13, 'post', 'draft'));
    $plugin->onTransition('publish', 'publish', mb_wp_object(14, 'post', 'publish', 'pw'));
    mb_assert_same(array(12 => 'delete', 14 => 'delete'), $plugin->queue->drain(), 'wrong transitions queued');
});

mb_test('pages are ignored', function () {
    list($plugin) = mb_plugin();
    $plugin->onTransition('publish', 'draft', mb_wp_object(15, 'page', 'publish'));
    $plugin->onTrash(15);
    mb_assert_same(array(), $plugin->queue->drain(), 'page queued');
});

mb_test('trash and permanent delete queue a delete', function () {
    list($plugin) = mb_plugin();
    mb_wp_object(16, 'post', 'publish');
    mb_wp_object(17, 'post', 'trash');
    $plugin->onTrash(16);
    $plugin->onDelete(17);
    mb_assert_same(array(16 => 'delete', 17 => 'delete'), $plugin->queue->drain(), 'trash/delete not queued');
});

mb_test('nothing is queued when auto sync is off or the plugin is not set up', function () {
    list($plugin, $state) = mb_plugin(false);
    $plugin->onTransition('publish', 'draft', mb_wp_object(18, 'post', 'publish'));
    mb_assert_same(array(), $plugin->queue->drain(), 'queued without auto sync');
    mb_assert(!isset($state->rows[18]), 'pending recorded without auto sync');
});

mb_test('shutdown flushes the queue', function () {
    list($plugin, $state, $client) = mb_plugin();
    $plugin->onTransition('publish', 'draft', mb_wp_object(19, 'post', 'publish'));
    $plugin->onShutdown();
    mb_assert_same('19', $client->upserts[0][0]['external_id'], 'not pushed at shutdown');
    mb_assert_same('synced', $state->rows[19]['sync_status'], 'not synced after flush');
});

mb_test('cron retries failures within the attempt cap', function () {
    list($plugin, $state, $client) = mb_plugin();
    $state->markFailure(20, 'failed_upsert', 'timeout', 'x');
    $plugin->onRetryCron();
    mb_assert_same('20', $client->upserts[0][0]['external_id'], 'failure not retried by cron');
});
