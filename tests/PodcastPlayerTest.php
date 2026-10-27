<?php


function mb_podcast_player(array &$store)
{
    MomoBirdWP_PodcastPlayer::resetRequest();
    return new MomoBirdWP_PodcastPlayer(
        function () use (&$store) {
            return $store;
        },
        function (array $values) use (&$store) {
            $store = $values;
        }
    );
}

mb_test('podcast placeholder only on a single public post', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $player = mb_podcast_player($store);
    $html = $player->placeholder(42, true, true, true, 'https://api.valley.atlaspaces.com');
    mb_assert_same(
        '<div class="momobird-podcast" data-source="' . MB_TEST_SOURCE_ID . '" data-post="42" data-api="https://api.valley.atlaspaces.com"></div>',
        $html,
        'placeholder markup'
    );
    mb_assert_same('', $player->placeholder(42, false, true, true, 'https://api.valley.atlaspaces.com'), 'placeholder on a list page');
    mb_assert_same('', $player->placeholder(42, true, false, true, 'https://api.valley.atlaspaces.com'), 'placeholder on a private post');
    mb_assert_same('', $player->placeholder(0, true, true, true, 'https://api.valley.atlaspaces.com'), 'placeholder without a cid');
});

mb_test('no podcast placeholder without a source id', function () {
    $store = array();
    mb_assert_same('', mb_podcast_player($store)->placeholder(42, true, true, true, 'https://api.valley.atlaspaces.com'), 'placeholder without source');
});

mb_test('no podcast placeholder when disabled', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    mb_assert_same('', mb_podcast_player($store)->placeholder(42, true, true, false, 'https://api.valley.atlaspaces.com'), 'placeholder while disabled');
});

mb_test('podcast script only after a placeholder and only once', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $player = mb_podcast_player($store);
    mb_assert_same('', $player->script(true), 'script without placeholder');
    $player->placeholder(42, true, true, true, 'https://api.valley.atlaspaces.com');
    mb_assert_same('<script defer src="https://momobird.atlaspaces.com/widget/podcast.v1.js"></script>', $player->script(true), 'script after placeholder');
    mb_assert_same('', $player->script(true), 'script printed twice');
});

mb_test('remember status stores a valid source id', function () {
    $store = array();
    $player = mb_podcast_player($store);
    $player->rememberStatus(array('source' => array('id' => MB_TEST_SOURCE_ID)), 1760000000);
    mb_assert_same(MB_TEST_SOURCE_ID, $player->sourceId(), 'source id not remembered');
    mb_assert_same(1760000000, $store['checked_at'], 'checked_at not stored');
});

mb_test('remember status rejects a malformed source id', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1);
    $player = mb_podcast_player($store);
    $player->rememberStatus(array('source' => array('id' => '"><script>alert(1)</script>')), 1760000000);
    mb_assert_same(MB_TEST_SOURCE_ID, $player->sourceId(), 'malformed id replaced the stored one');
    $store = array('source_id' => '"><script>', 'checked_at' => 1);
    mb_assert_same('', mb_podcast_player($store)->sourceId(), 'malformed stored id used');
});

mb_test('podcast placeholder escapes the api base', function () {
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1760000000);
    $html = mb_podcast_player($store)->placeholder(42, true, true, true, 'https://x.example.com/"><script>');
    mb_assert(strpos($html, '<script>') === false, 'api base not escaped: ' . $html);
});

mb_test('podcast refresh is claimed only when the source id is missing and stale', function () {
    $store = array();
    $player = mb_podcast_player($store);
    mb_assert($player->claimRefresh(1760000000), 'missing source id not claimed');
    mb_assert(!$player->claimRefresh(1760000100), 'claimed twice within ten minutes');
    mb_assert($player->claimRefresh(1760000700), 'not reclaimed after ten minutes');
    $store = array('source_id' => MB_TEST_SOURCE_ID, 'checked_at' => 1);
    mb_assert(!mb_podcast_player($store)->claimRefresh(1760000000), 'claimed although source id is known');
});

