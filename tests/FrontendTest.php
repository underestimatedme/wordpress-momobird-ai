<?php

function mb_front(array $settings, array $page)
{
    $GLOBALS['mb_options'] = array(
        'momobird_ai_settings' => array_merge(array(
            'base_url' => 'https://api.valley.atlaspaces.com',
            'connection_key' => mb_key('k'),
            'timeout' => 10,
        ), $settings),
        'momobird_ai_widget' => array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok', 'checked_at' => time()),
        'momobird_ai_podcast' => array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => time()),
    );
    $GLOBALS['mb_page'] = array_merge(array('singular' => true, 'loop' => true, 'main' => true), $page);
    $GLOBALS['mb_posts'][42] = (object) array_merge(array('ID' => 42, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => ''), isset($page['post']) ? $page['post'] : array());
    $GLOBALS['mb_current_post'] = 42;
    MomoBirdWP_PodcastPlayer::resetRequest();
}

function mb_footer()
{
    ob_start();
    MomoBirdWP_Frontend::footer();
    return ob_get_clean();
}

mb_test('placeholder only on single public post', function () {
    mb_front(array(), array());
    $html = MomoBirdWP_Frontend::content('<p>正文</p>');
    mb_assert(strpos($html, '<div class="momobird-podcast" data-source="' . MB_TEST_SOURCE_ID . '" data-post="42"') === 0, 'player not prepended: ' . $html);
    mb_assert(substr($html, -strlen('<p>正文</p>')) === '<p>正文</p>', 'content changed');
    mb_assert(strpos(mb_footer(), 'podcast.v1.js') !== false, 'podcast script not loaded after a placeholder');

    mb_front(array(), array('singular' => false));
    mb_assert_same('<p>正文</p>', MomoBirdWP_Frontend::content('<p>正文</p>'), 'player on an archive page');
    mb_assert(strpos(mb_footer(), 'podcast.v1.js') === false, 'podcast script without a placeholder');

    mb_front(array(), array('post' => array('post_password' => 'secret')));
    mb_assert_same('<p>正文</p>', MomoBirdWP_Frontend::content('<p>正文</p>'), 'player on a protected post');

    mb_front(array(), array('loop' => false));
    mb_assert_same('<p>正文</p>', MomoBirdWP_Frontend::content('<p>正文</p>'), 'player outside the main loop');
});

mb_test('widget script follows its switch', function () {
    mb_front(array('widget_enabled' => '1'), array());
    mb_assert(strpos(mb_footer(), 'loader.v1.js" data-project="pub_1"') !== false, 'widget script missing');
    mb_front(array('widget_enabled' => '0'), array());
    mb_assert(strpos(mb_footer(), 'loader.v1.js') === false, 'widget script shown while switched off');
});

mb_test('no output when both switches are off', function () {
    mb_front(array('widget_enabled' => '0', 'podcast_enabled' => '0'), array());
    mb_assert_same('<p>正文</p>', MomoBirdWP_Frontend::content('<p>正文</p>'), 'player while switched off');
    mb_assert_same('', mb_footer(), 'footer output while switched off');
});

mb_test('front end stays quiet before a key is saved', function () {
    mb_front(array('connection_key' => ''), array());
    mb_assert_same('<p>正文</p>', MomoBirdWP_Frontend::content('<p>正文</p>'), 'player without settings');
    mb_assert_same('', mb_footer(), 'footer output without settings');
});

mb_test('hourly cron refreshes a stale assistant state where page views cannot', function () {
    mb_front(array('widget_enabled' => '1'), array());
    $GLOBALS['mb_options']['momobird_ai_widget'] = array('public_id' => 'pub_1', 'available' => true, 'reason' => 'ok', 'checked_at' => time() - 7200);
    $GLOBALS['mb_wp_remote'] = function ($url, $args) {
        $data = array('source' => array('id' => MB_TEST_SOURCE_ID), 'widget' => array('public_id' => 'pub_2', 'available' => true, 'reason' => 'ok'));
        return array('response' => array('code' => 200), 'body' => json_encode(array('code' => 'OK', 'data' => $data)));
    };
    MomoBirdWP_Frontend::refreshFromCron();
    mb_assert_same('pub_2', $GLOBALS['mb_options']['momobird_ai_widget']['public_id'], 'cron did not refresh the assistant');
    mb_assert(strpos(mb_footer(), 'data-project="pub_2"') !== false, 'refreshed state not used');
});

mb_test('front end passes the assistant position and transcript setting', function () {
    mb_front(array('widget_enabled' => '1', 'widget_position' => 'left', 'podcast_transcript' => '1'), array());
    mb_assert(strpos(MomoBirdWP_Frontend::content('<p>正文</p>'), ' data-transcript="1"') !== false, 'transcript setting not passed');
    mb_assert(strpos(mb_footer(), 'data-position="left"') !== false, 'left position not passed');
    mb_front(array('widget_enabled' => '1'), array());
    mb_assert(strpos(MomoBirdWP_Frontend::content('<p>正文</p>'), 'data-transcript') === false, 'transcript on by default');
    mb_assert(strpos(mb_footer(), 'data-position') === false, 'position written by default');
});
