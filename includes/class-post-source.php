<?php

defined('ABSPATH') || exit;

// Reads posts out of WordPress in the shape MomoBirdWP_PostMapper expects and
// decides what MomoBird should hold for a post.
final class MomoBirdWP_PostSource
{
    // decide returns 'upsert' for a public post, 'delete' for a post MomoBird
    // must not keep, and 'skip' for anything that is not a post at all.
    public static function decide(array $post)
    {
        if ((string) $post['post_type'] !== 'post' || (string) $post['post_status'] === 'auto-draft') {
            return 'skip';
        }
        if ((string) $post['post_status'] === 'publish' && (string) $post['post_password'] === '') {
            return 'upsert';
        }
        return 'delete';
    }

    // load returns the post ready for the mapper, or null when it is gone or
    // not public. Shortcodes are dropped and the_content filters are not run,
    // so other plugins' ads and share buttons stay out of the knowledge base.
    public function load($postId)
    {
        $post = get_post((int) $postId);
        if (!$post || self::decide(array(
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_password' => $post->post_password,
        )) !== 'upsert') {
            return null;
        }
        $categories = array();
        foreach (get_the_category($post->ID) as $term) {
            $categories[] = array('id' => (int) $term->term_id, 'name' => (string) $term->name);
        }
        return array(
            'ID' => (int) $post->ID,
            'post_title' => (string) $post->post_title,
            'rendered_html' => self::renderContent((string) $post->post_content),
            'permalink' => (string) get_permalink($post),
            'post_modified_gmt' => (string) $post->post_modified_gmt,
            'category' => MomoBirdWP_PostMapper::primaryCategory(
                $categories,
                (int) get_post_meta($post->ID, '_yoast_wpseo_primary_category', true),
                (int) get_option('default_category')
            ),
        );
    }

    // renderContent renders blocks and unwraps shortcodes: a shortcode that
    // wraps text (page builders, [code], [caption]) keeps that text, one that
    // stands alone (a gallery, an embed) is dropped. strip_shortcodes() would
    // drop the wrapped text too, emptying page-builder posts.
    public static function renderContent($content)
    {
        return self::unwrapShortcodes(do_blocks($content));
    }

    private static function unwrapShortcodes($content)
    {
        if (empty($GLOBALS['shortcode_tags']) || strpos($content, '[') === false) {
            return $content;
        }
        return preg_replace_callback('/' . get_shortcode_regex() . '/s', function ($m) {
            if ($m[1] === '[' && $m[6] === ']') {
                return substr($m[0], 1, -1);
            }
            return isset($m[5]) && $m[5] !== '' ? self::unwrapShortcodes($m[5]) : '';
        }, $content);
    }

    // find is load under the name MomoBirdWP_FullSyncRun and retries use.
    public function find($postId)
    {
        return $this->load($postId);
    }

    // page returns up to $limit public posts with ids above $afterId, in id order.
    public function page($afterId, $limit)
    {
        $posts = array();
        foreach ($this->publicIdsAfter($afterId, $limit) as $postId) {
            $post = $this->load($postId);
            if ($post !== null) {
                $posts[] = $post;
            }
        }
        return $posts;
    }

    // publicIdsAfter pages through public post ids in ascending order.
    public function publicIdsAfter($afterId, $limit)
    {
        global $wpdb;
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type = 'post' AND post_status = 'publish' AND post_password = '' ORDER BY ID ASC LIMIT %d",
            (int) $afterId,
            (int) $limit
        )));
    }
}
