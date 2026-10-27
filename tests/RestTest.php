<?php

mb_test('rest routes require manage_options', function () {
    $GLOBALS['mb_can'] = array();
    mb_assert_same(false, MomoBirdWP_Rest::canManage(), 'non-admin allowed');
    $GLOBALS['mb_can'] = array('manage_options' => true);
    mb_assert_same(true, MomoBirdWP_Rest::canManage(), 'admin refused');
});

mb_test('rest registers one route per admin operation', function () {
    $GLOBALS['mb_routes'] = array();
    MomoBirdWP_Rest::registerRoutes();
    $paths = array_keys($GLOBALS['mb_routes']);
    sort($paths);
    mb_assert_same(array(
        'momobird/v1/full-sync/cleanup',
        'momobird/v1/full-sync/page',
        'momobird/v1/full-sync/start',
        'momobird/v1/retry',
        'momobird/v1/status',
        'momobird/v1/test',
        'momobird/v1/widget/enable',
    ), $paths, 'routes differ');
    foreach ($GLOBALS['mb_routes'] as $path => $args) {
        mb_assert_same('POST', $args['methods'], $path . ' is not POST');
        mb_assert_same(array('MomoBirdWP_Rest', 'canManage'), $args['permission_callback'], $path . ' is not admin-only');
    }
});
