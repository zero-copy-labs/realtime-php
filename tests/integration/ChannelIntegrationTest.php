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
		$this->channel = $this->client->channel('realtime:public', ['one' => 'two']);
	}

	public function testJoinedState()
	{
		$this->_joinTestChannel();

		$this->assertEquals('closed', $this->channel->state);

		$this->channel->subscribe();

		$this->assertEquals('joined', $this->channel->state);
	}

	public function testJoinedOnce()
	{
		$this->_joinTestChannel();

		$this->assertEquals(false, $this->channel->joinedOnce);

		$this->channel->subscribe();

		$this->assertEquals(true, $this->channel->joinedOnce);
	}

	public function testJoinPushAccessToken(): void
	{
		$mockToken = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIiwibmFtZSI6IkpvaG4gRG9lIiwiaWF0IjoxNTE2MjM5MDIyfQ.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
		$this->client->accessToken = $mockToken;
		$this->_joinTestChannel();

		$this->channel->subscribe();

		$joinPush = $this->channel->joinPush;

		$this->assertEquals($mockToken, $joinPush->payload['access_token']);
	}
}
