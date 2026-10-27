<?php

error_reporting(E_ALL);

$GLOBALS['mb_tests'] = array();

function mb_test($name, $callback)
{
    $GLOBALS['mb_tests'][] = array($name, $callback);
}

function mb_assert($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function mb_assert_same($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
    }
}

function mb_assert_throws($callback, $className)
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $className) {
            return $error;
        }
        throw new RuntimeException('Expected ' . $className . ', got ' . get_class($error) . ': ' . $error->getMessage());
    }

    throw new RuntimeException('Expected ' . $className . ' to be thrown');
}


const MB_TEST_SOURCE_ID = '01a16017-e335-75b8-ae8c-bf4dbaba5f1d';

function mb_key($fill = 'A')
{
    return 'mbk_' . str_repeat($fill, 43);
}

function mb_wp_post($html, $id = 1)
{
    return array(
        'ID' => $id,
        'post_title' => 'Post ' . $id,
        'rendered_html' => $html,
        'permalink' => 'https://example.com/?p=' . $id,
        'post_modified_gmt' => '2026-10-01 08:00:00',
        'category' => '',
    );
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once __DIR__ . '/wp-stubs.php';
define('MOMOBIRD_AI_TEST_FILE', dirname(__DIR__) . '/momobird-ai.php');
define('MOMOBIRD_AI_FILE', MOMOBIRD_AI_TEST_FILE);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
