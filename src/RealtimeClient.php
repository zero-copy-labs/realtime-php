<?php

namespace Supabase\Realtime;

use React\EventLoop\Loop;
use Supabase\Realtime\Util\Constants;
use Supabase\Realtime\Util\Timer;
use Wrench\Client;

class RealtimeClient
{
	public string $accessToken;
	public array $channels = [];
	public string $endpoint = '';
	public string $origin = '';
	public array $headers;
	public array $params = [];
	public int $timeout;
	public int $heartbeatIntervalMs = 30000;
	public int $refreshRate = 5;
	public $heartbeatTimer;
	public $pendingHeartbeatRef;
	public int $ref = 0;
	public $reconnectTimer;
	public $encode;
	public $reconnectAfterMs;
	public $conn;
	public array $sendBuffer = [];
	public array $stateChangeCallbacks = [
		'open' => [],
		'close' => [],
		'error' => [],
		'message' => [],
	];
	public int $eventsPerSecondLimitMs = 100;
	public bool $inThrottle = false;

	public array $events = ['broadcast', 'presence', 'postgres_changes'];

	private $loop;

	public function __construct($reference_id, $options = [])
	{
		$this->client = new \SplObjectStorage;
		$this->endpoint = "wss://{$reference_id}.supabase.co/realtime/v1/".Constants::$TRANSPORTS['websocket'];
		$this->origin = "https://{$reference_id}.supabase.co";
		$this->headers = Constants::getDefaultHeaders();
		$this->timeout = Constants::$DEFAULT_TIMEOUT;

		if (isset($options['params'])) {
			$this->params = $options['params'];
		}
		if (isset($options['headers'])) {
			$this->headers = array_merge($this->headers, $options['headers']);
		}
		if (isset($options['timeout'])) {
			$this->timeout = $options['timeout'];
		}
		if (isset($options['transport'])) {
			$this->transport = $options['transport'];
		}
		if (isset($options['heartbeatIntervalMs'])) {
			$this->heartbeatIntervalMs = $options['heartbeatIntervalMs'];
		}
		if (isset($options['logger'])) {
			$this->logger = $options['logger'];
		}

		if (isset($options->params['eventsPerSecond'])) {
			$this->eventsPerSecondLimitMs = 1000 / $options->params['eventsPerSecond'];
		}

		$this->heartbeatTimer = new Timer();

		$reconnectAfterMs = $this->reconnectAfterMs;

		$this->reconnectTimer = new Timer();
	}

	/**
	 * Get Reconnect timing based on tries.
	 *
	 * @param  int  $tries
	 * @return int
	 */
	public function reconnectAfterMs($tries)
	{
		$backoff = [1, 2, 5, 10];

		return  isset($backoff[$tries - 1]) ? $backoff[$tries - 1] : end($backoff);
	}

	/**
	 * Connect to websocket.
	 *
	 * @return void
	 */
	public function connect()
	{
		if ($this->isConnected()) {
			return;
		}

		$endpoint = $this->_endPointURL();

		$origin = $this->origin;

		$options = [
			'on_data_callback' => function ($data) {
				$this->_onConnMessage($data);
			},
		];

		$this->conn = new Client($endpoint, $origin, $options);

		$this->conn->connect();

		if ($this->conn->isConnected()) {
			$this->_onConnOpen();
		}
	}

	/**
	 * Disconnect from websocket.
	 *
	 * @return void
	 */
	public function disconnect()
	{
		if (! $this->conn || ! $this->isConnected()) {
			return;
		}

		$this->conn->disconnect();

		$this->conn = null;
		$this->heartbeatTimer->reset();
		$this->reconnectTimer->reset();
	}

	/**
	 * Get list of channels.
	 *
	 * @return array
	 */
	public function getChannels()
	{
		return $this->channels;
	}

	/**
	 * Remove channel from list of channels.
	 *
	 * @param  RealtimeChannel  $channel
	 * @return void
	 */
	public function removeChannel(RealtimeChannel $channel)
	{
		$status = $channel->unsubscribe();
		if (count($this->channels) === 0) {
			$this->disconnect();
		}

		return $status;
	}

	/**
	 * Remove all channels from list of channels.
	 *
	 * @return void
	 */
	public function removeAllChannels()
	{
		$channels = $this->channels;
		foreach ($channels as $channel) {
			$this->removeChannel($channel);
		}
	}

	/**
	 * Log the message.
	 *
	 * @return void
	 */
	public function log($kind, $message = null, $data = null)
	{
		error_log($kind.' '.$message.' '.json_encode($data).PHP_EOL);
	}

	/**
	 * Get Connection State.
	 *
	 * @return string
	 */
	public function connectionState()
	{
		switch($this->conn->readyState) {
			case Constants::$SOCKET_STATES['connecting']:
				return Constants::$CONNECTION_STATES['connecting'];
			case Constants::$SOCKET_STATES['open']:
				return Constants::$CONNECTION_STATES['open'];
			case Constants::$SOCKET_STATES['closing']:
				return Constants::$CONNECTION_STATES['closing'];
			default:
				return Constants::$CONNECTION_STATES['closed'];
		}
	}

	/**
	 * Is websocket connected.
	 *
	 * @return bool
	 */
	public function isConnected()
	{
		if (! isset($this->conn)) {
			return false;
		}

		return $this->conn->isConnected();
	}

	/**
	 * Connect to new channel.
	 *
	 * @param  string  $topic
	 * @param  array  $params
	 * @return RealtimeChannel
	 */
	public function channel($topic, $params = [])
	{
		if (! $this->isConnected()) {
			$this->connect();
		}

		$_channel = new RealtimeChannel($topic, $params, $this);
		array_push($this->channels, $_channel);

		return $_channel;
	}

	/**
	 * Send data to websocket.
	 *
	 * @param  array  $data
	 * @return void
	 */
	public function push($data)
	{
		$topic = $data['topic'];
		$event = $data['event'];
		$payload = $data['payload'];
		$ref = $data['ref'];

		$callback = function () use ($data) {
			$result = json_encode($data);
			$this->conn->sendData($result);
		};

		$this->log('push', "{$topic} {$event} ({$ref})", $payload);

		if ($this->isConnected()) {
			if (in_array($event, $this->events)) {
				$isThrottled = $this._throttle($callback)();
				if ($isThrottled) {
					return 'rate limited';
				}
			} else {
				$callback();
			}
		} else {
			array_push($this->sendBuffer, $callback);
		}
	}

	/**
	 * Set Auth token.
	 *
	 * @param  string  $token
	 * @return void
	 */
	public function setAuth($token)
	{
		$this->accessToken = $token;

		foreach ($this->channels as $channel) {
			$channel->updateJoinPayload(['access_token' => $token]);

			if ($channel->joinedOnce() && $channel->_isJoined()) {
				$channel->_push(Constants::$CHANNEL_EVENTS['access_token'], ['access_token' => $token]);
			}
		}
	}

	/**
	 * Create update ref.
	 *
	 * @return string
	 */
	public function _makeRef()
	{
		$ref = $this->ref + 1;
		if ($ref === $this->ref) {
			$this->ref = 0;
		} else {
			$this->ref = $ref;
		}

		return strval($this->ref);
	}

	/**
	 * Leave Channel / Topic.
	 *
	 * @param  string  $topic
	 * @return void
	 */
	public function _leaveOpenTopic($topic)
	{
		foreach ($this->channels as $channel) {
			if ($channel->topic == $topic && ($channel->_isJoined() || $channel->_isJoining())) {
				$_channel = $channel;
				break;
			}
		}

		if (isset($_channel)) {
			$_channel->unsubscribe();
		}
	}

	/**
	 * Remove channel from list of channels.
	 *
	 * @param  string  $channel - The channel name to remove
	 * @return void
	 */
	public function _remove($channel)
	{
		$index = array_search($channel, array_column($this->channels, 'topic'));
		if ($index !== false) {
			array_splice($this->channels, $index, 1);
		}
	}

	/**
	 * Open message receiver event loop.
	 *
	 * @return void
	 */
	public function startReceiver()
	{
		$this->_startReceiver();
	}

	/**
	 * Get websocket endpoint url.
	 *
	 * @return string
	 */
	private function _endPointURL()
	{
		return $this->_appendParams(
			$this->endpoint,
			array_merge(
				$this->params,
				['vsn' => Constants::$VSN],
				$this->headers,
			)
		);
	}

	/**
	 * On incoming message from websocket trigger corresponding event hooks.
	 *
	 * @param  string  $raw
	 * @return void
	 */
	private function _onConnMessage($raw)
	{
		$msg = json_decode($raw);

		$topic = $msg->topic;
		$event = $msg->event;
		$payload = $msg->payload;
		$ref = $msg->ref;

		$this->pendingHeartbeatRef = null;

		$this->log('receive', "{$topic} {$event} {$ref}", $payload);

		foreach ($this->channels as $channel) {
			if ($channel->_isMember($topic)) {
				$channel->_trigger($event, $payload, $ref, $raw);
			}
		}

		foreach ($this->stateChangeCallbacks['message'] as $callback) {
			$callback($msg);
		}
	}

	/**
	 * On websocket connection open trigger corresponding event hooks.
	 *
	 * @return void
	 */
	private function _onConnOpen()
	{
		$this->log('transport', 'connected to '.$this->_endPointURL());

		$this->_flushSendBuffer();
		$this->reconnectTimer->reset();

		if (! $this->isConnected()) {
			$this->conn->close();

			return;
		}
		$this->heartbeatTimer->reset();

		$this->heartbeatTimer->interval(function () {
			$this->_sendHeartbeat();
		}, function () {
			return $this->heartbeatIntervalMs;
		});
		foreach ($this->stateChangeCallbacks['open'] as $callback) {
			$callback();
		}
	}

	private function _triggerChanError()
	{
		foreach ($this->channels as $channel) {
			$channel->_trigger(Constants::$CHANNEL_EVENTS['error']);
		}
	}

	/**
	 * On websocket connection close trigger corresponding event hooks.
	 *
	 * @param  string  $event
	 * @return void
	 */
	private function _onConnClose($event)
	{
		$this->log('transport', 'close', $event);
		$this->_triggerChanError();
		$this->heartbeatTimer->reset();
		$this->reconnectTimer->schedule(
			function () {
				$this->disconnect();
				$this->connect();
			}, function ($tries) {
				return $this->reconnectAfterMs($tries);
			}
		);
		foreach ($this->stateChangeCallbacks['close'] as $callback) {
			$callback($event);
		}
	}

	/**
	 * On websocket connection error trigger corresponding event hooks.
	 *
	 * @param  string  $error
	 */
	private function _onConnError($error)
	{
		$this->log('transport', $error);
		$this->_triggerChanError();
		foreach ($this->stateChangeCallbacks['error'] as $callback) {
			$callback($error);
		}
	}

	/**
	 * Append params to url.
	 *
	 * @param  string  $url
	 * @param  array  $params
	 * @return string
	 */
	private function _appendParams($url, $params)
	{
		if (count($params) === 0) {
			return $url;
		}

		$query = http_build_query($params);
		if (strpos($url, '?') !== false) {
			return $url.'&'.$query;
		} else {
			return $url.'?'.$query;
		}
	}

	/**
	 * Flush send buffer.
	 *
	 * @return void
	 */
	private function _flushSendBuffer()
	{
		if ($this->isConnected() && count($this->sendBuffer) > 0) {
			$this->sendBuffer->forEach(function ($callback) {
				$callback();
			});
		}
	}

	/**
	 * Send heartbeat message.
	 *
	 * @return void
	 */
	private function _sendHeartbeat()
	{
		if (! $this->isConnected()) {
			return;
		}

		if ($this->pendingHeartbeatRef) {
			$this->pendingHeartbeatRef = null;
			$this->disconnect();
			$this->_onConnClose('heartbeat timeout');

			return;
		}

		$this->pendingHeartbeatRef = $this->_makeRef();
		$this->push([
			'topic' => 'phoenix',
			'event' => 'heartbeat',
			'payload' => [],
			'ref' => $this->pendingHeartbeatRef,
		]);

		if (isset($this->accessToken)) {
			$this->setAuth($this->accessToken);
		}

		$this->receiveMessages();
	}

	/**
	 * Throttle Events.
	 *
	 * @param  function  $callback
	 * @param  int  $eventsPerSecondLimitMs
	 */
	private function _throttle($callback, $eventsPerSecondLimitMs)
	{
		return function () {
			if ($this->inThrottle) {
				return true;
			}

			$callback();

			if ($eventsPerSecondLimitMs > 0) {
				$this->inThrottle = true;
				sleep($eventsPerSecondLimitMs);
				$this->inThrottle = false;
			}

			return false;
		};
	}

	public function receiveMessages()
	{
		if (! $this->isConnected()) {
			return;
		}

		$this->conn->receive();
	}
}
