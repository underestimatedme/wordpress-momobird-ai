<?php

function mb_queue_loader()
{
    return function ($id) {
        return mb_wp_post('<p>body ' . $id . '</p>', $id);
    };
}

mb_test('same post queued twice in one request is pushed once', function () {
    $client = new MomoBirdWP_TestClient();
    $state = new MomoBirdWP_TestStateRepository();
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(1, 'upsert');
    $queue->add(1, 'upsert');
    $queue->add(2, 'upsert');
    $queue->add(2, 'delete');
    $queue->flush(mb_sync_service($client, $state), mb_queue_loader());
    mb_assert_same(1, count($client->upserts), 'post 1 pushed more than once');
    mb_assert_same('1', $client->upserts[0][0]['external_id'], 'wrong post upserted');
    mb_assert_same(array(array('2')), $client->deletes, 'last action for post 2 was not the delete');
    mb_assert_same(array(), $queue->drain(), 'queue not empty after flush');
});

mb_test('a queued upsert whose post is gone is sent as a delete', function () {
    $client = new MomoBirdWP_TestClient();
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(3, 'upsert');
    $queue->flush(mb_sync_service($client, new MomoBirdWP_TestStateRepository()), function () {
        return null;
    });
    mb_assert_same(array(array('3')), $client->deletes, 'vanished post not deleted');
});

mb_test('network failure at shutdown leaves post failed and save unaffected', function () {
    $client = new MomoBirdWP_TestClient();
    $client->failUpsert = new MomoBirdWP_TransportException('down');
    $state = new MomoBirdWP_TestStateRepository();
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(5, 'upsert');
    $queue->flush(mb_sync_service($client, $state), mb_queue_loader());
    mb_assert_same('failed_upsert', $state->rows[5]['sync_status'], 'failure not recorded');
});

mb_test('an unexpected error while flushing does not escape', function () {
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(6, 'upsert');
    $queue->flush(mb_sync_service(new MomoBirdWP_TestClient(), new MomoBirdWP_TestStateRepository()), function () {
        throw new LogicException('boom');
    });
    mb_assert(true, 'unreachable when flush throws');
});

mb_test('a network failure stops the flush and leaves the rest pending', function () {
    $client = new MomoBirdWP_TestClient();
    $client->failUpsert = new MomoBirdWP_TransportException('down');
    $state = new MomoBirdWP_TestStateRepository();
    $queue = new MomoBirdWP_PendingQueue();
    foreach (array(51, 52, 53) as $id) {
        $state->markPending($id, 'upsert');
        $queue->add($id, 'upsert');
    }
    $queue->flush(mb_sync_service($client, $state), mb_queue_loader());
    mb_assert_same(1, count($client->upserts), 'kept pushing after a network failure');
    mb_assert_same('pending_upsert', $state->rows[52]['sync_status'], 'later post not left pending');
});

mb_test('a rejected post does not stop the flush', function () {
    $client = new MomoBirdWP_TestClient();
    $client->results['61'] = array('status' => 'rejected', 'error_code' => 'VALIDATION_FAILED');
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(61, 'upsert');
    $queue->add(62, 'upsert');
    $queue->flush(mb_sync_service($client, new MomoBirdWP_TestStateRepository()), mb_queue_loader());
    mb_assert_same(2, count($client->upserts), 'a per-post rejection stopped the flush');
});

mb_test('a post made private while its push was in flight is removed again', function () {
    $client = new MomoBirdWP_TestClient();
    $state = new MomoBirdWP_TestStateRepository();
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(71, 'upsert');
    $queue->flush(mb_sync_service($client, $state), mb_queue_loader(), function ($id) {
        return false;
    });
    mb_assert_same(1, count($client->upserts), 'post not pushed');
    mb_assert_same(array(array('71')), $client->deletes, 'post that is no longer public stayed in MomoBird');
    mb_assert(!isset($state->rows[71]), 'state still says synced');
});

mb_test('a post still public after its push is left alone', function () {
    $client = new MomoBirdWP_TestClient();
    $queue = new MomoBirdWP_PendingQueue();
    $queue->add(72, 'upsert');
    $queue->flush(mb_sync_service($client, new MomoBirdWP_TestStateRepository()), mb_queue_loader(), function ($id) {
        return true;
    });
    mb_assert_same(array(), $client->deletes, 'public post deleted');
});
