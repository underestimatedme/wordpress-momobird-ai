<?php

function mb_wp_post($html, $id = 1)
{
    return array(
        'ID' => $id,
        'post_title' => 'Post ' . $id,
        'rendered_html' => $html,
        'permalink' => 'https://example.com/?p=' . $id,
        'post_modified_gmt' => '2026-10-01 08:00:00',
        'category' => '',
    );
}

mb_test('mapper uses the post id as external id', function () {
    $mapped = MomoBirdWP_PostMapper::mapPost(mb_wp_post('<p>正文</p>', 42), 'https://example.com');
    mb_assert_same('42', $mapped['external_id'], 'external id is not the post id');
    mb_assert_same('Post 42', $mapped['title'], 'title not mapped');
    mb_assert_same("# Post 42\n\n正文", $mapped['content'], 'content is not the whole document');
    mb_assert_same('https://example.com/?p=42', $mapped['url'], 'permalink altered');
    mb_assert_same('2026-10-01T08:00:00Z', $mapped['modified_at'], 'modified_at not RFC3339 UTC');
    mb_assert(!array_key_exists('category', $mapped), 'empty category should be omitted');
});

mb_test('mapper sends the category when there is one', function () {
    $post = mb_wp_post('<p>正文</p>', 5);
    $post['category'] = '教程';
    $mapped = MomoBirdWP_PostMapper::mapPost($post, 'https://example.com');
    mb_assert_same('教程', $mapped['category'], 'category not mapped');
});

mb_test('mapper truncates long titles to 255 characters', function () {
    $post = mb_wp_post('<p>正文</p>', 6);
    $post['post_title'] = str_repeat('长', 300);
    $mapped = MomoBirdWP_PostMapper::mapPost($post, 'https://example.com');
    mb_assert_same(255, mb_strlen($mapped['title'], 'UTF-8'), 'title not truncated');
});

mb_test('mapper strips html from titles', function () {
    $post = mb_wp_post('<p>正文</p>', 7);
    $post['post_title'] = 'A <em>B</em> &amp; C';
    $mapped = MomoBirdWP_PostMapper::mapPost($post, 'https://example.com');
    mb_assert_same('A B & C', $mapped['title'], 'title html not stripped');
});

mb_test('mapper skips posts without content or url', function () {
    mb_assert_same(null, MomoBirdWP_PostMapper::mapPost(mb_wp_post('  <p> </p> ', 3), 'https://example.com'), 'empty post mapped');
    $post = mb_wp_post('<p>正文</p>', 4);
    $post['permalink'] = '';
    mb_assert_same(null, MomoBirdWP_PostMapper::mapPost($post, ''), 'post without url mapped');
});

mb_test('primary category prefers the yoast primary category', function () {
    $cats = array(array('id' => 3, 'name' => '新闻'), array('id' => 9, 'name' => '教程'));
    mb_assert_same('教程', MomoBirdWP_PostMapper::primaryCategory($cats, 9, 1), 'yoast primary ignored');
});

mb_test('primary category falls back to the first non-default category', function () {
    $cats = array(array('id' => 1, 'name' => '未分类'), array('id' => 3, 'name' => '新闻'));
    mb_assert_same('新闻', MomoBirdWP_PostMapper::primaryCategory($cats, 0, 1), 'default category not skipped');
    mb_assert_same('新闻', MomoBirdWP_PostMapper::primaryCategory($cats, 77, 1), 'unknown yoast id should fall back');
});

mb_test('primary category is empty when only the default category is set', function () {
    mb_assert_same('', MomoBirdWP_PostMapper::primaryCategory(array(array('id' => 1, 'name' => '未分类')), 0, 1), 'default category sent');
    mb_assert_same('', MomoBirdWP_PostMapper::primaryCategory(array(), 0, 1), 'no category should be empty');
});

mb_test('primary category is cut to 64 characters', function () {
    $cats = array(array('id' => 3, 'name' => '  ' . str_repeat('类', 65) . ' '));
    mb_assert_same(str_repeat('类', 64), MomoBirdWP_PostMapper::primaryCategory($cats, 0, 1), 'category not trimmed and cut');
});

mb_test('mapper keeps a title with invalid utf-8 bytes', function () {
    $post = mb_wp_post('<p>正文</p>', 8);
    $post['post_title'] = "bad \xC3\x28 title";
    $mapped = MomoBirdWP_PostMapper::mapPost($post, 'https://example.com');
    mb_assert(mb_check_encoding($mapped['title'], 'UTF-8'), 'title is not valid utf-8');
    mb_assert(strpos($mapped['title'], 'bad') === 0 && strpos($mapped['title'], 'title') !== false, 'title text lost: ' . $mapped['title']);
});
