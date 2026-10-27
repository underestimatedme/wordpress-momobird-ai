<?php

defined('ABSPATH') || exit;

// What readers see: the assistant loader in the footer and the podcast
// placeholder above single public posts. Page views read cached state only;
// refreshing it from MomoBird happens after the response is sent.
final class MomoBirdWP_Frontend
{
    const WIDGET_OPTION = 'momobird_ai_widget';
    const PODCAST_OPTION = 'momobird_ai_podcast';

    private static $refreshWidget = false;
    private static $refreshPodcast = false;

    public static function register()
    {
        add_filter('the_content', array(__CLASS__, 'content'), 20, 1);
        add_action('wp_footer', array(__CLASS__, 'footer'), 20, 0);
        add_action('shutdown', array(__CLASS__, 'refreshAfterResponse'), 1, 0);
        add_action(MomoBirdWP_Plugin::RETRY_HOOK, array(__CLASS__, 'refreshFromCron'), 20, 0);
    }

    // refreshFromCron keeps the cached state fresh on hosts where page views
    // cannot finish the response early (no PHP-FPM or LiteSpeed).
    public static function refreshFromCron()
    {
        try {
            $config = MomoBirdWP_Plugin::config();
            if ($config === null) {
                return;
            }
            $client = (new MomoBirdWP_HttpClient($config))->withoutRetry();
            $now = time();
            if ($config->widgetEnabled() && self::widgetState()->needsRefresh($now)) {
                self::widgetState()->ensureEnabled($client, $now);
            }
            if ($config->podcastEnabled() && self::podcastPlayer()->sourceId() === '') {
                self::podcastPlayer()->rememberStatus($client->status(), $now);
            }
        } catch (Throwable $error) {
            // Next hour.
        }
    }

    public static function widgetState()
    {
        return new MomoBirdWP_WidgetState(self::loader(self::WIDGET_OPTION), self::saver(self::WIDGET_OPTION));
    }

    public static function podcastPlayer()
    {
        return new MomoBirdWP_PodcastPlayer(self::loader(self::PODCAST_OPTION), self::saver(self::PODCAST_OPTION));
    }

    public static function content($content)
    {
        try {
            $config = MomoBirdWP_Plugin::config();
            if ($config === null || !$config->podcastEnabled() || !is_singular('post') || !in_the_loop() || !is_main_query()) {
                return $content;
            }
            $post = get_post();
            $public = $post && $post->post_type === 'post' && $post->post_status === 'publish' && (string) $post->post_password === '';
            return self::podcastPlayer()->placeholder($post ? (int) $post->ID : 0, true, $public, true, $config->baseUrl(), $config->podcastTranscript()) . $content;
        } catch (Throwable $error) {
            // The article must render even when the player cannot be placed.
            return $content;
        }
    }

    public static function footer()
    {
        try {
            $config = MomoBirdWP_Plugin::config();
            if ($config === null) {
                return;
            }
            $now = time();
            if ($config->widgetEnabled()) {
                $state = self::widgetState();
                echo $state->snippet($now, $config->widgetPosition()); // phpcs:ignore WordPress.Security.EscapeOutput -- built from a validated public id
                self::$refreshWidget = self::canFinishResponseEarly() && $state->claimRefresh($now);
            }
            $player = self::podcastPlayer();
            echo $player->script($config->podcastEnabled(), self::onSinglePublicPost()); // phpcs:ignore WordPress.Security.EscapeOutput -- constant markup
            self::$refreshPodcast = $config->podcastEnabled() && self::canFinishResponseEarly() && $player->claimRefresh($now);
        } catch (Throwable $error) {
            // The page must never break because of the assistant.
        }
    }

    public static function refreshAfterResponse()
    {
        if (!self::$refreshWidget && !self::$refreshPodcast) {
            return;
        }
        try {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $config = MomoBirdWP_Config::fromArray(array_merge(get_option(MomoBirdWP_Plugin::OPTION, array()), array('timeout' => 3)));
            $client = (new MomoBirdWP_HttpClient($config))->withoutRetry();
            if (self::$refreshWidget) {
                self::widgetState()->ensureEnabled($client, time());
            }
            if (self::$refreshPodcast) {
                self::podcastPlayer()->rememberStatus($client->status(), time());
            }
        } catch (Throwable $error) {
            // Best effort; the claimed timestamp spaces out the next attempt.
        }
    }

    // applySettings runs after the settings are saved: switching the assistant
    // off forgets its state, switching it on tries to publish it right away.
    public static function applySettings($old, $new)
    {
        try {
            $config = MomoBirdWP_Config::fromArray(is_array($new) ? $new : array());
        } catch (InvalidArgumentException $error) {
            return;
        }
        if (!$config->widgetEnabled()) {
            self::widgetState()->clear();
            return;
        }
        try {
            self::widgetState()->ensureEnabled(new MomoBirdWP_HttpClient($config), time());
        } catch (Throwable $error) {
            // Page views retry every ten minutes.
        }
    }

    private static function onSinglePublicPost()
    {
        if (!is_singular('post')) {
            return false;
        }
        $post = get_post();
        return $post && $post->post_status === 'publish' && (string) $post->post_password === '';
    }

    private static function canFinishResponseEarly()
    {
        return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
    }

    private static function loader($option)
    {
        return function () use ($option) {
            $value = get_option($option, array());
            return is_array($value) ? $value : array();
        };
    }

    private static function saver($option)
    {
        return function (array $values) use ($option) {
            update_option($option, $values, false);
        };
    }
}
