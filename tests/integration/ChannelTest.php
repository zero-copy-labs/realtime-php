<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Supabase\Realtime\Util\EnvSetup;

final class ChannelTest extends TestCase
{
	private $client;
	private $channel;

	public function setup(): void
	{
		parent::setUp();

		$keys = EnvSetup::env(__DIR__.'/../');
		$api_key = $keys['API_KEY'];
		$reference_id = $keys['REFERENCE_ID'];

		$options = [
			'headers' => [
				'apikey' => $api_key,
			],
			'eventsPerSecond' => '10',
			'timeout' => 1234,
		];

		$this->client = new RealtimeClient($reference_id, $options);

		// Override the endpoint to use the mock server
		$this->client->endpoint = 'ws://example.com/socket';
	}

	public function teardown(): void
	{
		parent::tearDown();

		$this->client->disconnect();
		$this->channel->unsubscribe();
		$this->channel = null;
	}

	/**
	 * Tests channel contstructor.
	 *
	 * @return void
	 */
	public function testChannelConstructor()
	{
		$this->channel = new Channel('topic', ['one' => 'two'], $this->client);
		$this->assertEquals('topic', $this->channel->topic);
		$this->assertEquals('closed', $this->channel->state);
		$this->assertEquals([
			'config' => [
				'broadcast' => [
					'ack' => false,
					'self' => false,
				],
				'presence' => [
					'key' => '',
				],
				'one' => 'two',
			],
		], $this->channel->params);
		$this->assertEquals(1234, $this->channel->timeout);
		$this->assertEquals(false, $this->channel->joinedOnce);
		$this->assertEquals([], $this->channel->pushBuffer);
	}
}
