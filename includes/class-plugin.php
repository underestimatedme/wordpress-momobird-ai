<?php

defined('ABSPATH') || exit;

// Wires the plugin into WordPress: post changes are marked pending and pushed
// at shutdown; cron retries what failed.
final class MomoBirdWP_Plugin
{
    const OPTION = 'momobird_ai_settings';
    const RETRY_HOOK = 'momobird_ai_retry';

    private static $instance;

    public $queue;
    private $state;
    private $source;
    private $syncFactory;
    private $sync = false;

    // $syncFactory returns the SyncService, or null when the plugin is not set
    // up or automatic sync is off.
    public function __construct($state, $source, $syncFactory)
    {
        $this->state = $state;
        $this->source = $source;
        $this->syncFactory = $syncFactory;
        $this->queue = new MomoBirdWP_PendingQueue();
    }

    public static function instance()
    {
        if (self::$instance === null) {
            global $wpdb;
            $state = new MomoBirdWP_SyncRepository($wpdb);
            self::$instance = new self($state, new MomoBirdWP_PostSource(), function () use ($state) {
                $config = self::config();
                if ($config === null || !$config->autoSync()) {
                    return null;
                }
                return new MomoBirdWP_SyncService(new MomoBirdWP_HttpClient($config), $state, home_url('/'));
            });
        }
        return self::$instance;
    }

    // config returns the saved settings, or null before a valid key is saved.
    public static function config()
    {
        $values = get_option(self::OPTION, array());
        try {
            return MomoBirdWP_Config::fromArray(is_array($values) ? $values : array());
        } catch (InvalidArgumentException $error) {
            return null;
        }
    }

    public static function register($file)
    {
        register_activation_hook($file, array(__CLASS__, 'activate'));
        register_deactivation_hook($file, array(__CLASS__, 'deactivate'));
        add_action('transition_post_status', array(__CLASS__, 'handleTransition'), 10, 3);
        add_action('wp_trash_post', array(__CLASS__, 'handleTrash'), 10, 1);
        add_action('before_delete_post', array(__CLASS__, 'handleDelete'), 10, 1);
        add_action('shutdown', array(__CLASS__, 'handleShutdown'), 0, 0);
        add_action(self::RETRY_HOOK, array(__CLASS__, 'handleRetryCron'), 10, 0);
    }

    public static function activate($networkWide = false)
    {
        if (is_multisite() && $networkWide) {
            deactivate_plugins(plugin_basename(MOMOBIRD_AI_FILE), true, true);
            wp_die(esc_html__('MomoBird AI 暂不支持网络启用，请在各站点单独启用。', 'momobird-ai'));
        }
        global $wpdb;
        if (!function_exists('dbDelta')) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        dbDelta(MomoBirdWP_SyncRepository::schemaSql($wpdb->prefix, $wpdb->get_charset_collate()));
        if (!wp_next_scheduled(self::RETRY_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::RETRY_HOOK);
        }
    }

    public static function deactivate()
    {
        // Knowledge in MomoBird and the local sync table are kept.
        wp_clear_scheduled_hook(self::RETRY_HOOK);
    }

    public static function handleTransition($new, $old, $post)
    {
        self::instance()->onTransition($new, $old, $post);
    }

    public static function handleTrash($postId)
    {
        self::instance()->onTrash($postId);
    }

    public static function handleDelete($postId)
    {
        self::instance()->onDelete($postId);
    }

    public static function handleShutdown()
    {
        self::instance()->onShutdown();
    }

    public static function handleRetryCron()
    {
        self::instance()->onRetryCron();
    }

    // onTransition fires on every save, including publish → publish; only a
    // post that is or was public matters to MomoBird.
    public function onTransition($new, $old, $post)
    {
        if (!is_object($post) || $post->post_type !== 'post') {
            return;
        }
        if ($new === 'publish') {
            $this->enqueue($post->ID, MomoBirdWP_PostSource::decide(array(
                'post_type' => $post->post_type,
                'post_status' => $new,
                'post_password' => (string) $post->post_password,
            )));
        } elseif ($old === 'publish') {
            $this->enqueue($post->ID, 'delete');
        }
    }

    public function onTrash($postId)
    {
        if (get_post_type($postId) === 'post') {
            $this->enqueue($postId, 'delete');
        }
    }

    public function onDelete($postId)
    {
        $this->onTrash($postId);
    }

    public function onShutdown()
    {
        if ($this->queue->isEmpty() || !($sync = $this->sync())) {
            return;
        }
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        $this->queue->flush($sync, array($this->source, 'load'));
    }

    public function onRetryCron()
    {
        if ($sync = $this->sync()) {
            $sync->retryFailures(array($this->source, 'load'), true);
        }
    }

    private function enqueue($postId, $action)
    {
        if ($action === 'skip' || !$this->sync()) {
            return;
        }
        $this->state->markPending($postId, $action);
        $this->queue->add($postId, $action);
    }

    private function sync()
    {
        if ($this->sync === false) {
            $this->sync = call_user_func($this->syncFactory);
        }
        return $this->sync;
    }
}
