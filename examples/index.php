<?php
    include __DIR__.'/header.php';
    use Supabase\Realtime\RealtimeClient;
    use React\EventLoop\Loop;

    $options = [
        'headers' => [
            'apikey' => $apiKey
        ],
        'eventsPerSecond' => '10' 
    ];

    $socket = new RealtimeClient($endpoint, $options);

    $socket->connect();

    $channel = $socket->channel('realtime:public'); // Also tried realtime:db-messages

    $channel->on('postgres_changes', [
        'event' => 'INSERT',
        'schema' => 'public',
        'table' => 'auth_token',
    ], function($payload) {
        echo 'INSERT: ' . $payload['new']['id'] . PHP_EOL;
    });

    $channel->subscribe(function($payload) {
        echo $payload . PHP_EOL;
    });


    $loop = Loop::addPeriodicTimer(10, function() use ($socket) {
        $messages = $socket->conn->receive();

        if (!$messages) {
            echo 'No messages' . PHP_EOL;
            return;
        }
        
        foreach($messages as $message) {
            echo 'message: ' . $message->getPayload() . PHP_EOL;
        }

    });

    Loop::run();

    

    // $channel->on('UPDATE', null, function($payload) {
    //     echo 'UPDATE: ' . $payload['new']['id'] . PHP_EOL;
    // });

    // $channel->on('DELETE', null, function($payload) {
    //     echo 'DELETE: ' . $payload['old']['id'] . PHP_EOL;
    // });

    // $channel->subscribe();

    $channel->unsubscribe();

    Loop::cancelTimer($loop);