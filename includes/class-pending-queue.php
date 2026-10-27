<?php

defined('ABSPATH') || exit;

// Posts changed during this request, pushed once at shutdown after the
// response is sent. The last action for a post wins.
final class MomoBirdWP_PendingQueue
{
    private $items = array();

    public function add($postId, $action)
    {
        $postId = (int) $postId;
        unset($this->items[$postId]);
        $this->items[$postId] = $action === 'delete' ? 'delete' : 'upsert';
    }

    public function isEmpty()
    {
        return !$this->items;
    }

    public function drain()
    {
        $items = $this->items;
        $this->items = array();
        return $items;
    }

    // flush never throws: a save in WordPress must not fail because MomoBird
    // did. Failures stay in the sync state for cron and the admin to retry.
    public function flush($sync, $load)
    {
        foreach ($this->drain() as $postId => $action) {
            try {
                $post = $action === 'upsert' ? call_user_func($load, $postId) : null;
                if ($post) {
                    $sync->syncPost($post);
                } else {
                    $sync->deletePost($postId);
                }
            } catch (Throwable $error) {
                // SyncService records its own failures; anything else is left
                // pending for cron to pick up.
            }
        }
    }
}
