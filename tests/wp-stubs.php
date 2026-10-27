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
