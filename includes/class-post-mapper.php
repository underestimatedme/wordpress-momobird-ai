<?php

defined('ABSPATH') || exit;

// Maps a WordPress post (already loaded into a plain array by
// MomoBirdWP_PostSource) to one connect API post.
final class MomoBirdWP_PostMapper
{
    const MAX_TITLE = 255;
    const MAX_CATEGORY = 64;

    public static function mapPost(array $post, $siteOrigin)
    {
        $title = self::plainTitle((string) $post['post_title']);
        $content = MomoBirdWP_ContentTransformer::document($title, $post['rendered_html']);
        $url = trim((string) $post['permalink']);
        if ($content === '' || $url === '') {
            return null;
        }
        $mapped = array(
            'external_id' => (string) (int) $post['ID'],
            'title' => $title === '' ? 'Untitled' : mb_substr($title, 0, self::MAX_TITLE, 'UTF-8'),
            'content' => $content,
            'url' => $url,
            'modified_at' => gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $post['post_modified_gmt'] . ' UTC')),
        );
        $category = isset($post['category']) ? (string) $post['category'] : '';
        if ($category !== '') {
            $mapped['category'] = $category;
        }
        return $mapped;
    }

    // primaryCategory picks the one category MomoBird keeps: Yoast's primary
    // category when it is one of the post's, else the first that is not the
    // site's default ("Uncategorized"). $categories is in term order.
    public static function primaryCategory(array $categories, $yoastPrimaryId, $defaultCategoryId)
    {
        $chosen = null;
        foreach ($categories as $category) {
            if ((int) $category['id'] === (int) $yoastPrimaryId && (int) $yoastPrimaryId > 0) {
                $chosen = $category;
                break;
            }
        }
        if ($chosen === null) {
            foreach ($categories as $category) {
                if ((int) $category['id'] !== (int) $defaultCategoryId) {
                    $chosen = $category;
                    break;
                }
            }
        }
        if ($chosen === null) {
            return '';
        }
        return mb_substr(trim((string) $chosen['name']), 0, self::MAX_CATEGORY, 'UTF-8');
    }

    private static function plainTitle($title)
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $title));
    }
}
