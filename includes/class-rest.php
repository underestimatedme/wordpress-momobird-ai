<?php

defined('ABSPATH') || exit;

// REST routes behind the admin page (wp-json/momobird/v1/...). Admins only;
// the WordPress REST cookie check validates the X-WP-Nonce header. Responses
// carry counts, states and names, never the connection key or post bodies.
final class MomoBirdWP_Rest
{
    const NAMESPACE_V1 = 'momobird/v1';

    private static $operations = array(
        '/status' => 'status',
        '/test' => 'test-connection',
        '/full-sync/start' => 'start-sync',
        '/full-sync/page' => 'sync-page',
        '/full-sync/cleanup' => 'cleanup-page',
        '/retry' => 'retry-failures',
        '/widget/enable' => 'enable-widget',
    );

    public static function canManage()
    {
        return current_user_can('manage_options');
    }

    public static function registerRoutes()
    {
        foreach (self::$operations as $route => $operation) {
            register_rest_route(self::NAMESPACE_V1, $route, array(
                'methods' => 'POST',
                'permission_callback' => array(__CLASS__, 'canManage'),
                'callback' => function ($request) use ($operation) {
                    return MomoBirdWP_Rest::handle($operation, (array) $request->get_json_params());
                },
            ));
        }
    }

    public static function handle($operation, array $input)
    {
        $config = MomoBirdWP_Plugin::config();
        if ($config === null && $operation !== 'status') {
            return new WP_Error('momobird_unconfigured', __('请先在设置里填写连接密钥并保存。', 'momobird-ai'), array('status' => 409));
        }
        try {
            return rest_ensure_response(MomoBirdWP_Admin::controller($config)->dispatch($operation, $input));
        } catch (MomoBirdWP_HttpException $error) {
            return new WP_Error($error->apiCode(), $error->getMessage(), array('status' => 502, 'upstream_status' => $error->status()));
        } catch (InvalidArgumentException $error) {
            return new WP_Error('momobird_invalid_request', $error->getMessage(), array('status' => 400));
        } catch (RuntimeException $error) {
            return new WP_Error('momobird_sync_state', $error->getMessage(), array('status' => 409));
        }
    }
}
