<?php

mb_test('document keeps the title and section headings', function () {
    $document = MomoBirdWP_ContentTransformer::document('标题', '<h2>小节</h2><p>正文</p>');
    mb_assert_same("# 标题\n\n## 小节\n\n正文", $document, 'document shape changed');
});

mb_test('document drops gutenberg block comments', function () {
    $document = MomoBirdWP_ContentTransformer::document('T', "<!-- wp:paragraph -->\n<p>你好</p>\n<!-- /wp:paragraph -->");
    mb_assert_same("# T\n\n你好", $document, 'block comments leaked into the document');
});

mb_test('document strips scripts and unsafe html', function () {
    $document = MomoBirdWP_ContentTransformer::document('T', '<p>a</p><script>alert(1)</script><style>x{}</style>');
    mb_assert(strpos($document, 'alert(1)') === false, 'script content remains');
    mb_assert(strpos($document, '<') === false, 'html tags remain');
});

mb_test('document of an empty body is empty', function () {
    mb_assert_same('', MomoBirdWP_ContentTransformer::document('T', '<!-- wp:paragraph --><p> </p><!-- /wp:paragraph -->'), 'empty body should produce no document');
});
