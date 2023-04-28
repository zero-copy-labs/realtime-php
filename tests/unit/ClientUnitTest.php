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

	// public function testAppendParams()
	// {
    //     $this->client = new RealtimeClient('123', []);
	// 	$url = 'https://example.com';
	// 	$params = ['one' => 'two', 'three' => 'four'];
	// 	$_url = $this->client->_appendParams($url, $params);

	// 	$this->assertEquals('https://example.com?one=two&three=four', $_url);
	// }
}
