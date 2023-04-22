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

	$socket->connect();

	// $channel = $socket->channel('realtime:public');

	// $channel->on('INSERT', null, function($payload) {
	//     echo 'INSERT: ' . $payload['new']['id'] . PHP_EOL;
	// });

	// $channel->on('UPDATE', null, function($payload) {
	//     echo 'UPDATE: ' . $payload['new']['id'] . PHP_EOL;
	// });

	// $channel->on('DELETE', null, function($payload) {
	//     echo 'DELETE: ' . $payload['old']['id'] . PHP_EOL;
	// });

	// $channel->subscribe();

	// sleep(120);
