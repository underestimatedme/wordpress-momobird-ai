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
