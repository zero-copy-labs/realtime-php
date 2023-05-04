<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Supabase\Realtime\RealtimeChannel;
use Supabase\Realtime\RealtimeClient;

final class ChannelUnitTest extends TestCase
{
	private $client;
	private $channel;

	public function setup(): void
	{
		parent::setUp();

		$options = [
			'headers' => [
				'apikey' => 'theapikey',
			],
			'eventsPerSecond' => '10',
			'timeout' => 1234,
		];

		$this->client = new RealtimeClient('12312343', $options);
	}

	public function teardown(): void
	{
		parent::tearDown();

		$this->channel = null;
	}

	/**
	 * Tests channel contstructor.
	 *
	 * @return void
	 */
	public function testChannelConstructor()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$expectedParams = json_encode([
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
		]);

		$this->assertEquals('topic', $this->channel->topic);
		$this->assertEquals('closed', $this->channel->state);
		$this->assertEquals($expectedParams, json_encode($this->channel->params));
		$this->assertEquals(1234, $this->channel->timeout);
		$this->assertEquals(false, $this->channel->joinedOnce);
		$this->assertEquals([], $this->channel->pushBuffer);
	}

	public function testChannelJoinPush()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$expectedPayload = json_encode([
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
		]);

		$joinPush = $this->channel->joinPush;

		$this->assertEquals($expectedPayload, json_encode($joinPush->payload));
		$this->assertEquals('phx_join', $joinPush->event);
		$this->assertEquals('1234', $joinPush->timeout);
	}

	public function testChannelJoinedState()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$this->channel->state = 'joined';

		$this->assertEquals(true, $this->channel->_isJoined());
	}

	public function testChannelJoiningState()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$this->channel->state = 'joining';

		$this->assertEquals(true, $this->channel->_isJoining());
	}

	public function testChannelClosedState()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$this->channel->state = 'closed';

		$this->assertEquals(true, $this->channel->_isClosed());
	}

	public function testChannelLeavingState()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$this->channel->state = 'leaving';

		$this->assertEquals(true, $this->channel->_isLeaving());
	}

	public function testChannelReplyEventName()
	{
		$this->channel = new RealtimeChannel('topic', ['one' => 'two'], $this->client);

		$event = 'chan_reply_1234';

		$this->assertEquals($event, $this->channel->_replyEventName('1234'));
	}
}
