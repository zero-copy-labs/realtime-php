<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Supabase\Realtime\RealtimeClient;
use Supabase\Realtime\Util\EnvSetup;

final class ChannelIntegrationTest extends TestCase
{
	private $client;
	private $channel;

	public function setup(): void
	{
		parent::setUp();

		$keys = EnvSetup::env(__DIR__.'/../../');
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
	}

	public function teardown(): void
	{
		parent::tearDown();

		$this->client->disconnect();
		$this->channel->unsubscribe();
		$this->channel = null;
	}

	public function _joinTestChannel()
	{
		$this->channel = $this->client->channel('topic', ['one' => 'two']);
	}

	public function testJoiningState()
	{
		$this->_joinTestChannel();

		$this->channel->subscribe();

		$this->assertEquals('joining', $this->channel->state);
	}

	public function testJoinedOnce()
	{
		$this->_joinTestChannel();

		$this->assertEquals(false, $this->channel->joinedOnce);

		$this->channel->subscribe();

		$this->assertEquals(true, $this->channel->joinedOnce);
	}

	public function testJoinPushAccessToken()
	{
		$this->client->accessToken = 'access_token_1234';
		$this->_joinTestChannel();

		$this->channel->subscribe();

		$joinPush = $this->channel->joinPush;

		$this->assertEquals('access_token_1234', $joinPush->payload['accessToken']);
	}
}
