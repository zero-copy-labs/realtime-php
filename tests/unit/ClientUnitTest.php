<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Supabase\Realtime\RealtimeClient;

final class ClientUnitTest extends TestCase
{
	private $client;

	public function testRemoveChannel()
	{
		$this->client = new RealtimeClient('123', []);

		$this->client->channels = [
			[
				'topic' => 'channel1',
			],
			[
				'topic' => 'channel2',
			],
		];

		$this->assertEquals(2, count($this->client->channels));
		$this->client->_remove('channel1');
		$this->assertEquals(1, count($this->client->channels));
	}
}
