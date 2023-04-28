<?php
    include __DIR__.'/header.php';
    use Supabase\Realtime\RealtimeClient;

	$options = [
		'headers' => [
			'apikey' => $apiKey,
		],
		'eventsPerSecond' => '10',
	];

	$socket = new RealtimeClient($reference_id, $options);

    $channel = $socket->channel('realtime:db-messages'); // Also tried realtime:db-messages

    $channel->on('postgres_changes', [
        'event' => 'INSERT',
        'schema' => 'public',
        'table' => 'auth_token',
    ], function($payload) {
        echo 'INSERT: ' . json_encode($payload['new']) . PHP_EOL;
    });

    $channel->subscribe(function($payload) {
        echo $payload . PHP_EOL;
    });
