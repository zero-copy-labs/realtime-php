<?php
    include __DIR__.'/header.php';
    use Supabase\Realtime\RealtimeClient;

	$options = [
		'headers' => [
			'apikey' => $apiKey,
		],
		'eventsPerSecond' => '10',
	];

	$socket = new RealtimeClient($endpoint, $options);

    $channel = $socket->channel('realtime:db-messages'); // Also tried realtime:db-messages

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

	$socket->startReceiver();

    // $socket->startReceiver();

	// $channel->on('INSERT', null, function($payload) {
	//     echo 'INSERT: ' . $payload['new']['id'] . PHP_EOL;
	// });

	// $channel->on('UPDATE', null, function($payload) {
	//     echo 'UPDATE: ' . $payload['new']['id'] . PHP_EOL;
	// });

	// $channel->on('DELETE', null, function($payload) {
	//     echo 'DELETE: ' . $payload['old']['id'] . PHP_EOL;
	// });

    // $channel->unsubscribe();
