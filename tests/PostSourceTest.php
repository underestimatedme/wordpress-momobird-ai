<?php

function mb_wp_state($type, $status, $password = '')
{
    return array('post_type' => $type, 'post_status' => $status, 'post_password' => $password);
}

mb_test('public posts are upserted', function () {
    mb_assert_same('upsert', MomoBirdWP_PostSource::decide(mb_wp_state('post', 'publish')), 'public post not upserted');
});

mb_test('non-public posts are deleted', function () {
    foreach (array('private', 'draft', 'pending', 'future', 'trash') as $status) {
        mb_assert_same('delete', MomoBirdWP_PostSource::decide(mb_wp_state('post', $status)), $status . ' post not deleted');
    }
});

mb_test('password added to published post means delete', function () {
    mb_assert_same('delete', MomoBirdWP_PostSource::decide(mb_wp_state('post', 'publish', 'secret')), 'protected post kept');
});

mb_test('pages, revisions and auto drafts are skipped', function () {
    mb_assert_same('skip', MomoBirdWP_PostSource::decide(mb_wp_state('page', 'publish')), 'page synced');
    mb_assert_same('skip', MomoBirdWP_PostSource::decide(mb_wp_state('revision', 'inherit')), 'revision synced');
    mb_assert_same('skip', MomoBirdWP_PostSource::decide(mb_wp_state('post', 'auto-draft')), 'auto draft deleted');
});

mb_test('shortcodes that wrap text keep the text; self-closing ones are dropped', function () {
    $GLOBALS['shortcode_tags'] = array('et_pb_section' => true, 'et_pb_text' => true, 'code' => true, 'gallery' => true, 'caption' => true);
    $html = '[et_pb_section][et_pb_text]<p>正文</p>[/et_pb_text][/et_pb_section]'
        . '[gallery ids="1,2"]'
        . '[code lang="php"]echo 1;[/code]'
        . '[caption id="a"]<img src="x.png"> 图片说明[/caption]'
        . '[[gallery]]'
        . '[unknown]原样[/unknown]';
    mb_assert_same('<p>正文</p>echo 1;<img src="x.png"> 图片说明[gallery][unknown]原样[/unknown]', MomoBirdWP_PostSource::renderContent($html), 'shortcode handling');
    $GLOBALS['shortcode_tags'] = array();
    mb_assert_same('[gallery ids="1"]', MomoBirdWP_PostSource::renderContent('[gallery ids="1"]'), 'text changed without registered shortcodes');
});

mb_test('is public now reads the post fresh from the database', function () {
    $GLOBALS['mb_cleaned'] = array();
    $GLOBALS['mb_posts'][80] = (object) array('ID' => 80, 'post_type' => 'post', 'post_status' => 'private', 'post_password' => '');
    mb_assert_same(false, (new MomoBirdWP_PostSource())->isPublicNow(80), 'private post reported public');
    mb_assert_same(array(80), $GLOBALS['mb_cleaned'], 'post cache not cleared before reading');
    $GLOBALS['mb_posts'][80]->post_status = 'publish';
    mb_assert_same(true, (new MomoBirdWP_PostSource())->isPublicNow(80), 'public post reported private');
});
