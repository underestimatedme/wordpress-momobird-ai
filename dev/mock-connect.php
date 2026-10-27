<?php
// Stand-in for MomoBird's connect API (php -S router script) for local
// end-to-end checks. Keeps posts in /tmp/momobird-mock.json and logs every
// request to /tmp/momobird-mock.log. Not shipped in the plugin zip.

const PREFIX = '/momobird/api/public/connect/v1';
const KEY = 'mbk_LOCALTESTKEYLOCALTESTKEYLOCALTESTKEYLOCALTE';
const SITE = 'http://localhost:8088';
const STORE = '/tmp/momobird-mock.json';

function out($status, $body)
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok($data)
{
    out(200, array('code' => 'OK', 'data' => $data));
}

$state = is_file(STORE) ? json_decode(file_get_contents(STORE), true) : array('posts' => array(), 'widget' => array('public_id' => 'pub_local', 'available' => false, 'reason' => 'not_published'));
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
file_put_contents('/tmp/momobird-mock.log', json_encode(array('method' => $method, 'path' => $path, 'body' => $body), JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . KEY) {
    out(401, array('code' => 'UNAUTHORIZED', 'message' => 'bad key'));
}

$save = function () use (&$state) {
    file_put_contents(STORE, json_encode($state, JSON_UNESCAPED_UNICODE));
};

switch ($method . ' ' . substr($path, strlen(PREFIX))) {
    case 'GET /status':
        ok(array(
            'project' => array('id' => 'p-local', 'name' => '本地测试项目'),
            'source' => array('id' => '01a16017-e335-75b8-ae8c-bf4dbaba5f1d', 'kind' => 'wordpress', 'name' => 'Local WP', 'site_url' => SITE,
                'document_count' => count($state['posts']), 'ready_document_count' => count($state['posts'])),
            'entitlement' => array('status' => 'trial', 'active' => true, 'trial_ends_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 6 * 86400), 'pro_ends_at' => null),
            'widget' => $state['widget'],
        ));
    case 'PUT /posts':
        $results = array();
        foreach ($body['posts'] as $post) {
            $id = $post['external_id'];
            if (strpos($post['url'], SITE . '/') !== 0) {
                $results[] = array('external_id' => $id, 'document_id' => null, 'status' => 'rejected', 'error_code' => 'VALIDATION_FAILED');
                continue;
            }
            $status = !isset($state['posts'][$id]) ? 'created' : ($state['posts'][$id] == $post ? 'unchanged' : 'updated');
            $state['posts'][$id] = $post;
            $results[] = array('external_id' => $id, 'document_id' => 'doc-' . $id, 'status' => $status, 'error_code' => null);
        }
        $save();
        ok(array('results' => $results));
    case 'POST /posts/delete':
        $results = array();
        foreach ($body['external_ids'] as $id) {
            $results[] = array('external_id' => $id, 'status' => isset($state['posts'][$id]) ? 'deleted' : 'not_found');
            unset($state['posts'][$id]);
        }
        $save();
        ok(array('results' => $results));
    case 'GET /posts':
        $items = array();
        foreach (array_keys($state['posts']) as $id) {
            $items[] = array('external_id' => (string) $id);
        }
        ok(array('items' => $items, 'next_cursor' => null));
    case 'POST /widget/enable':
        $state['widget'] = array('public_id' => 'pub_local', 'available' => true, 'reason' => 'ok');
        $save();
        ok($state['widget']);
}
out(404, array('code' => 'NOT_FOUND', 'message' => $path));
