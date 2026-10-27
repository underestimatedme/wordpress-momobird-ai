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
