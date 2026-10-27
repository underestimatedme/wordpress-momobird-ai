<?php

function mb_settings_input(array $extra = array())
{
    return array_merge(array(
        'base_url' => 'https://api.valley.atlaspaces.com',
        'connection_key' => mb_key('k'),
        'timeout' => '10',
    ), $extra);
}

mb_test('unchecked boxes are saved as off', function () {
    $GLOBALS['mb_options'] = array();
    $saved = MomoBirdWP_Admin::sanitize(mb_settings_input());
    mb_assert_same('0', $saved['enabled'], 'auto sync stayed on without its checkbox');
    mb_assert_same('0', $saved['widget_enabled'], 'widget on without its checkbox');
    mb_assert_same('0', $saved['podcast_enabled'], 'podcast on without its checkbox');
    mb_assert_same('0', $saved['podcast_transcript'], 'transcript on without its checkbox');
    $saved = MomoBirdWP_Admin::sanitize(mb_settings_input(array('enabled' => '1', 'widget_enabled' => '1', 'podcast_enabled' => '1')));
    mb_assert_same(array('1', '1', '1'), array($saved['enabled'], $saved['widget_enabled'], $saved['podcast_enabled']), 'checked boxes lost');
});

mb_test('a blank key keeps the saved key', function () {
    $GLOBALS['mb_options'] = array('momobird_ai_settings' => array('connection_key' => mb_key('s'), 'base_url' => 'https://api.valley.atlaspaces.com', 'timeout' => 10));
    $saved = MomoBirdWP_Admin::sanitize(mb_settings_input(array('connection_key' => '')));
    mb_assert_same(mb_key('s'), $saved['connection_key'], 'saved key dropped');
});

mb_test('invalid settings keep the previous ones and report an error', function () {
    $previous = array('connection_key' => mb_key('s'), 'base_url' => 'https://api.valley.atlaspaces.com', 'timeout' => 10);
    $GLOBALS['mb_options'] = array('momobird_ai_settings' => $previous);
    $GLOBALS['mb_settings_errors'] = array();
    $saved = MomoBirdWP_Admin::sanitize(mb_settings_input(array('base_url' => 'http://evil.example')));
    mb_assert_same($previous, $saved, 'invalid settings saved');
    mb_assert_same(1, count($GLOBALS['mb_settings_errors']), 'error not reported');
});
