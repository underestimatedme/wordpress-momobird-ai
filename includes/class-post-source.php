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
            'rendered_html' => strip_shortcodes(do_blocks((string) $post->post_content)),
            'permalink' => (string) get_permalink($post),
            'post_modified_gmt' => (string) $post->post_modified_gmt,
            'category' => MomoBirdWP_PostMapper::primaryCategory(
                $categories,
                (int) get_post_meta($post->ID, '_yoast_wpseo_primary_category', true),
                (int) get_option('default_category')
            ),
        );
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
