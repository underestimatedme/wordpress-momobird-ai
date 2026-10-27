<?php

mb_test('rejects unsafe remote base urls', function () {
    $urls = array(
        'http://example.com',
        'https://user:pass@example.com',
        'https://example.com/?x=1',
        'https://example.com/#x',
        'https://example.com/other/path'
    );
    foreach ($urls as $url) {
        mb_assert_throws(function () use ($url) {
            MomoBirdWP_Config::fromArray(array(
                'base_url' => $url,
                'connection_key' => mb_key(),
                'timeout' => 10
            ));
        }, 'InvalidArgumentException');
    }
});

mb_test('config accepts a connection key', function () {
    $config = MomoBirdWP_Config::fromArray(array(
        'base_url' => 'http://127.0.0.1:8080/',
        'connection_key' => mb_key('b'),
        'timeout' => 2
    ));
    mb_assert_same('http://127.0.0.1:8080', $config->baseUrl(), 'local base URL was not normalized');
    mb_assert_same(mb_key('b'), $config->connectionKey(), 'connection key was altered');
    mb_assert_same(2, $config->timeout(), 'timeout was not kept');
});

mb_test('config rejects malformed connection keys and limits', function () {
    foreach (array(
        array('base_url' => 'https://example.com', 'connection_key' => '', 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => 'mbk_short', 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => 'sk_' . str_repeat('A', 44), 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => 'mbk_' . str_repeat('+', 43), 'timeout' => 10),
        array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 31)
    ) as $values) {
        mb_assert_throws(function () use ($values) {
            MomoBirdWP_Config::fromArray($values);
        }, 'InvalidArgumentException');
    }
});

mb_test('config keeps the stored key when the field is blank', function () {
    $config = MomoBirdWP_Config::mergeForSave(
        array('connection_key' => mb_key('s')),
        array('base_url' => 'https://example.com', 'connection_key' => '  ', 'timeout' => 10)
    );
    mb_assert_same(mb_key('s'), $config->connectionKey(), 'stored key was overwritten');
});

mb_test('config hides the key in toArray', function () {
    $config = MomoBirdWP_Config::fromArray(array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 10));
    $public = $config->toArray();
    mb_assert_same('', $public['connection_key'], 'key leaked from toArray');
    $private = $config->toArray(true);
    mb_assert_same(mb_key(), $private['connection_key'], 'secret toArray lost the key');
});

mb_test('widget is disabled by default and auto sync on', function () {
    $config = MomoBirdWP_Config::fromArray(array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 10));
    mb_assert(!$config->widgetEnabled(), 'widget should default to off');
    mb_assert($config->autoSync(), 'auto sync should default to on');
    $on = MomoBirdWP_Config::fromArray(array('base_url' => 'https://example.com', 'connection_key' => mb_key(), 'timeout' => 10, 'enabled' => '0', 'widget_enabled' => '1'));
    mb_assert($on->widgetEnabled(), 'widget flag ignored');
    mb_assert(!$on->autoSync(), 'auto sync flag ignored');
});

mb_test('podcast player is on unless switched off', function () {
    $config = MomoBirdWP_Config::fromArray(mb_valid_config());
    mb_assert_same(true, $config->podcastEnabled(), 'podcast player should default to on');
    $off = mb_valid_config();
    $off['podcast_enabled'] = '0';
    mb_assert_same(false, MomoBirdWP_Config::fromArray($off)->podcastEnabled(), 'podcast switch ignored');
    mb_assert_same('0', MomoBirdWP_Config::fromArray($off)->toArray()['podcast_enabled'], 'podcast switch not saved');
});

mb_test('secret prefix shows only the start of the key', function () {
    $config = MomoBirdWP_Config::fromArray(mb_valid_config());
    mb_assert_same('mbk_kkkk', $config->secretPrefix(), 'secret prefix wrong');
});

mb_test('assistant position is right unless left is chosen', function () {
    mb_assert_same('right', MomoBirdWP_Config::fromArray(mb_valid_config())->widgetPosition(), 'default position');
    $left = mb_valid_config();
    $left['widget_position'] = 'left';
    mb_assert_same('left', MomoBirdWP_Config::fromArray($left)->widgetPosition(), 'left ignored');
    $odd = mb_valid_config();
    $odd['widget_position'] = '" onload="x';
    mb_assert_same('right', MomoBirdWP_Config::fromArray($odd)->widgetPosition(), 'unknown position kept');
    mb_assert_same('left', MomoBirdWP_Config::fromArray($left)->toArray()['widget_position'], 'position not saved');
});

mb_test('podcast transcript is off unless switched on', function () {
    mb_assert_same(false, MomoBirdWP_Config::fromArray(mb_valid_config())->podcastTranscript(), 'transcript on by default');
    $on = mb_valid_config();
    $on['podcast_transcript'] = '1';
    mb_assert_same(true, MomoBirdWP_Config::fromArray($on)->podcastTranscript(), 'transcript switch ignored');
    mb_assert_same('1', MomoBirdWP_Config::fromArray($on)->toArray()['podcast_transcript'], 'transcript switch not saved');
});
