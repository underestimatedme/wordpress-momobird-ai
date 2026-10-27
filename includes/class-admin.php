<?php

defined('ABSPATH') || exit;

// 设置 → MomoBird AI: the settings form plus the sync status and actions,
// which call the REST routes in MomoBirdWP_Rest.
final class MomoBirdWP_Admin
{
    const PAGE = 'momobird-ai';
    const GROUP = 'momobird_ai';
    const CHECKBOXES = array('enabled', 'widget_enabled', 'podcast_enabled', 'podcast_transcript');

    public static function register()
    {
        add_action('admin_menu', array(__CLASS__, 'addPage'));
        add_action('admin_init', array(__CLASS__, 'registerSettings'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
        add_action('rest_api_init', array('MomoBirdWP_Rest', 'registerRoutes'));
        add_action('update_option_' . MomoBirdWP_Plugin::OPTION, array('MomoBirdWP_Frontend', 'applySettings'), 10, 2);
        add_action('add_option_' . MomoBirdWP_Plugin::OPTION, function ($name, $value) {
            MomoBirdWP_Frontend::applySettings(array(), $value);
        }, 10, 2);
    }

    public static function addPage()
    {
        add_options_page('MomoBird AI', 'MomoBird AI', 'manage_options', self::PAGE, array(__CLASS__, 'render'));
    }

    public static function registerSettings()
    {
        register_setting(self::GROUP, MomoBirdWP_Plugin::OPTION, array(
            'type' => 'array',
            'sanitize_callback' => array(__CLASS__, 'sanitize'),
            'default' => array(),
        ));
    }

    // sanitize validates the submitted form. A blank key keeps the saved one;
    // an unchecked box is absent from the form, so it means off. Invalid input
    // keeps the previous settings.
    public static function sanitize($input)
    {
        $stored = get_option(MomoBirdWP_Plugin::OPTION, array());
        $stored = is_array($stored) ? $stored : array();
        $input = is_array($input) ? $input : array();
        foreach (self::CHECKBOXES as $box) {
            $input[$box] = !empty($input[$box]) ? '1' : '0';
        }
        try {
            return MomoBirdWP_Config::mergeForSave($stored, $input)->toArray(true);
        } catch (InvalidArgumentException $error) {
            add_settings_error(MomoBirdWP_Plugin::OPTION, 'momobird_invalid', $error->getMessage());
            return $stored;
        }
    }

    public static function enqueue($hook)
    {
        if ($hook !== 'settings_page_' . self::PAGE) {
            return;
        }
        $base = plugins_url('assets/', MOMOBIRD_AI_FILE);
        wp_enqueue_style('momobird-ai-admin', $base . 'admin.css', array(), MOMOBIRD_AI_VERSION);
        wp_enqueue_script('momobird-ai-admin', $base . 'admin.js', array(), MOMOBIRD_AI_VERSION, true);
    }

    // controller builds the admin operations for the saved settings; $config
    // may be null, in which case only 'status' can be answered.
    public static function controller($config)
    {
        global $wpdb;
        $state = new MomoBirdWP_SyncRepository($wpdb);
        $posts = new MomoBirdWP_PostSource();
        $client = $config ? new MomoBirdWP_HttpClient($config) : null;
        $sync = $client ? new MomoBirdWP_SyncService($client, $state, home_url('/')) : null;
        $fullRun = $sync ? new MomoBirdWP_FullSyncRun($posts, $sync, $state, $client) : null;
        $now = time();
        $context = array(
            'configured' => $config !== null,
            'auto_sync_enabled' => $config !== null && $config->autoSync(),
            'site_origin' => home_url('/'),
            // Testing the connection also tells the podcast player its source id.
            'on_status' => function ($status) use ($now) {
                MomoBirdWP_Frontend::podcastPlayer()->rememberStatus($status, $now);
            },
        );
        if ($client !== null) {
            $context['enable_widget'] = function () use ($client, $now) {
                return MomoBirdWP_Frontend::widgetState()->enable($client, $now);
            };
        }
        $afterFullSync = null;
        if ($client !== null && $config->widgetEnabled()) {
            $afterFullSync = function () use ($client, $now) {
                return MomoBirdWP_Frontend::widgetState()->ensureEnabled($client, $now);
            };
        }
        return new MomoBirdWP_AdminController($client, $posts, $state, $sync, $fullRun, $context, $afterFullSync);
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $config = MomoBirdWP_Plugin::config();
        $values = $config ? $config->toArray() : MomoBirdWP_Config::defaults();
        $name = MomoBirdWP_Plugin::OPTION;
        ?>
        <div class="wrap" id="momobird-admin"
             data-rest-url="<?php echo esc_url(rest_url('momobird/v1/')); ?>"
             data-nonce="<?php echo esc_attr(wp_create_nonce('wp_rest')); ?>">
            <h1>MomoBird AI</h1>
            <p><?php esc_html_e('同步范围：仅公开文章。页面、草稿、私密和带密码的文章不会上传。', 'momobird-ai'); ?></p>

            <form method="post" action="options.php" class="momobird-card">
                <h2><?php esc_html_e('连接设置', 'momobird-ai'); ?></h2>
                <?php settings_fields(self::GROUP); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="momobird-key"><?php esc_html_e('连接密钥', 'momobird-ai'); ?></label></th>
                        <td>
                            <input id="momobird-key" class="regular-text code" type="password" autocomplete="off"
                                   name="<?php echo esc_attr($name); ?>[connection_key]" value=""
                                   placeholder="<?php echo esc_attr($config ? $config->secretPrefix() . '…' : 'mbk_…'); ?>">
                            <p class="description"><?php esc_html_e('在 MomoBird 控制台「知识 → 新增来源 → 博客 / 论坛 → WordPress」生成。留空保存会保留已保存的密钥。', 'momobird-ai'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="momobird-base"><?php esc_html_e('MomoBird Base URL', 'momobird-ai'); ?></label></th>
                        <td><input id="momobird-base" class="regular-text code" type="url" name="<?php echo esc_attr($name); ?>[base_url]" value="<?php echo esc_attr($values['base_url']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="momobird-timeout"><?php esc_html_e('请求超时（秒）', 'momobird-ai'); ?></label></th>
                        <td><input id="momobird-timeout" class="small-text" type="number" min="2" max="30" name="<?php echo esc_attr($name); ?>[timeout]" value="<?php echo esc_attr($values['timeout']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e('选项', 'momobird-ai'); ?></th>
                        <td>
                            <?php self::checkbox($name, 'enabled', $values, __('文章发布、修改、删除时自动同步', 'momobird-ai')); ?>
                            <?php self::checkbox($name, 'widget_enabled', $values, __('在前台显示问答助手', 'momobird-ai')); ?>
                            <?php self::checkbox($name, 'podcast_enabled', $values, __('在文章页显示播客播放器', 'momobird-ai')); ?>
                            <?php self::checkbox($name, 'podcast_transcript', $values, __('播放器里显示播客文字稿', 'momobird-ai')); ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="momobird-position"><?php esc_html_e('问答助手位置', 'momobird-ai'); ?></label></th>
                        <td>
                            <select id="momobird-position" name="<?php echo esc_attr($name); ?>[widget_position]">
                                <option value="right" <?php selected($values['widget_position'], 'right'); ?>><?php esc_html_e('右下角', 'momobird-ai'); ?></option>
                                <option value="left" <?php selected($values['widget_position'], 'left'); ?>><?php esc_html_e('左下角', 'momobird-ai'); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <section class="momobird-card" aria-labelledby="momobird-status-title">
                <h2 id="momobird-status-title"><?php esc_html_e('同步状态', 'momobird-ai'); ?></h2>
                <div class="momobird-stats">
                    <span>配置 <strong id="momobird-config-state">—</strong></span>
                    <span>连接 <strong id="momobird-connection-state">—</strong></span>
                    <span>自动同步 <strong id="momobird-auto-sync">—</strong></span>
                    <span>已同步文章 <strong id="momobird-synced-posts">—</strong></span>
                    <span>权益 <strong id="momobird-plan">—</strong></span>
                    <span>线上进度 <strong id="momobird-serving">—</strong></span>
                    <span>待开通 <strong id="momobird-plan-required">—</strong></span>
                    <span>上传失败 <strong id="momobird-failed-upsert">—</strong></span>
                    <span>空文章已跳过 <strong id="momobird-skipped">—</strong></span>
                    <span>删除失败 <strong id="momobird-failed-delete">—</strong></span>
                    <span>最近全量同步 <strong id="momobird-last-sync">—</strong></span>
                </div>
                <p id="momobird-origin-warning" class="momobird-warning" hidden></p>
                <p id="momobird-recent-error" hidden></p>
                <p id="momobird-message" role="status"><?php esc_html_e('正在读取状态…', 'momobird-ai'); ?></p>
            </section>

            <section class="momobird-card" aria-labelledby="momobird-actions-title">
                <h2 id="momobird-actions-title"><?php esc_html_e('操作', 'momobird-ai'); ?></h2>
                <div class="momobird-actions">
                    <button type="button" class="button" data-momobird-action="test"><?php esc_html_e('测试连接', 'momobird-ai'); ?></button>
                    <button type="button" class="button button-primary" data-momobird-action="sync"><?php esc_html_e('一键全量同步', 'momobird-ai'); ?></button>
                    <button type="button" class="button" data-momobird-action="retry"><?php esc_html_e('重试失败项', 'momobird-ai'); ?></button>
                    <button type="button" class="button" data-momobird-action="widget"><?php esc_html_e('开启前台助手', 'momobird-ai'); ?></button>
                </div>
                <progress id="momobird-progress" value="0" max="1" hidden></progress>
            </section>
        </div>
        <?php
    }

    private static function checkbox($name, $key, array $values, $label)
    {
        printf(
            '<p><label><input type="checkbox" name="%s[%s]" value="1" %s> %s</label></p>',
            esc_attr($name),
            esc_attr($key),
            checked(isset($values[$key]) && $values[$key] === '1', true, false),
            esc_html($label)
        );
    }
}
