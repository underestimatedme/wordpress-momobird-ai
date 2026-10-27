<?php

// Minimal stand-ins for the WordPress functions the plugin's classes call, so
// the pure logic runs without WordPress. Tests inject anything stateful.

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code = '', $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function is_wp_error($thing)
{
    return $thing instanceof WP_Error;
}

// Tests set $GLOBALS['mb_wp_remote'] to answer wp_remote_request.
function wp_remote_request($url, $args = array())
{
    return call_user_func($GLOBALS['mb_wp_remote'], $url, $args);
}

function wp_remote_retrieve_response_code($response)
{
    return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response)
{
    return isset($response['body']) ? (string) $response['body'] : '';
}

// Hook registration is recorded so tests can assert on it.
$GLOBALS['mb_actions'] = array();
$GLOBALS['mb_options'] = array();
$GLOBALS['mb_scheduled'] = array();
$GLOBALS['mb_posts'] = array();

function add_action($hook, $callback, $priority = 10, $args = 1)
{
    $GLOBALS['mb_actions'][] = array($hook, $callback, $priority, $args);
}

function add_filter($hook, $callback, $priority = 10, $args = 1)
{
    add_action($hook, $callback, $priority, $args);
}

function register_activation_hook($file, $callback)
{
    add_action('activate:' . basename($file), $callback);
}

function register_deactivation_hook($file, $callback)
{
    add_action('deactivate:' . basename($file), $callback);
}

function get_option($name, $default = false)
{
    return array_key_exists($name, $GLOBALS['mb_options']) ? $GLOBALS['mb_options'][$name] : $default;
}

function update_option($name, $value, $autoload = null)
{
    $GLOBALS['mb_options'][$name] = $value;
    return true;
}

function delete_option($name)
{
    unset($GLOBALS['mb_options'][$name]);
    return true;
}

function is_multisite()
{
    return !empty($GLOBALS['mb_multisite']);
}

function plugin_basename($file)
{
    return 'momobird-ai/' . basename($file);
}

class MomoBirdWP_TestDie extends RuntimeException
{
}

function wp_die($message = '')
{
    throw new MomoBirdWP_TestDie((string) $message);
}

function deactivate_plugins($plugins, $silent = false, $networkWide = null)
{
    $GLOBALS['mb_deactivated'] = $plugins;
}

function dbDelta($sql)
{
    $GLOBALS['mb_dbdelta'][] = $sql;
    return array();
}

function wp_next_scheduled($hook)
{
    return isset($GLOBALS['mb_scheduled'][$hook]) ? $GLOBALS['mb_scheduled'][$hook]['time'] : false;
}

function wp_schedule_event($time, $recurrence, $hook)
{
    $GLOBALS['mb_scheduled'][$hook] = array('time' => $time, 'recurrence' => $recurrence);
    return true;
}

function wp_clear_scheduled_hook($hook)
{
    unset($GLOBALS['mb_scheduled'][$hook]);
    return 1;
}

function get_post_type($post = null)
{
    $id = is_object($post) ? $post->ID : (int) $post;
    return isset($GLOBALS['mb_posts'][$id]) ? $GLOBALS['mb_posts'][$id]->post_type : false;
}

function home_url($path = '')
{
    return 'https://example.com' . $path;
}

function __($text, $domain = 'default')
{
    return $text;
}

function esc_html__($text, $domain = 'default')
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

class MomoBirdWP_TestWpdb
{
    public $prefix = 'wp_';

    public function get_charset_collate()
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }
}

function current_user_can($capability)
{
    return !empty($GLOBALS['mb_can'][$capability]);
}

function register_rest_route($namespace, $route, $args)
{
    $GLOBALS['mb_routes'][$namespace . $route] = $args;
    return true;
}

function add_settings_error($setting, $code, $message, $type = 'error')
{
    $GLOBALS['mb_settings_errors'][] = array($setting, $code, $message, $type);
}

$GLOBALS['mb_page'] = array('singular' => false, 'loop' => false, 'main' => false);

function is_singular($type = '')
{
    return !empty($GLOBALS['mb_page']['singular']);
}

function in_the_loop()
{
    return !empty($GLOBALS['mb_page']['loop']);
}

function is_main_query()
{
    return !empty($GLOBALS['mb_page']['main']);
}

function get_post($post = null)
{
    $id = $post === null ? (isset($GLOBALS['mb_current_post']) ? $GLOBALS['mb_current_post'] : 0) : (is_object($post) ? $post->ID : (int) $post);
    return isset($GLOBALS['mb_posts'][$id]) ? $GLOBALS['mb_posts'][$id] : null;
}

function selected($selected, $current = true, $echo = true)
{
    return (string) $selected === (string) $current ? ' selected="selected"' : '';
}
