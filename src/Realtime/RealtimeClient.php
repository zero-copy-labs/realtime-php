<?php

namespace Supabase\Realtime;

use React\EventLoop\Loop;
use Supabase\Util\Constants;
use Supabase\Util\Timer;
use Wrench\Client;

class RealtimeClient
{
	public string $accessToken;
	public array $channels = [];
	public string $endpoint = '';
	public array $headers;
	public array $params = [];
	public int $timeout;
	//$transport = ;
	public int $heartbeatIntervalMs = 30000;
	public int $refreshRate = 1;
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

	public function __construct($endpoint, $options)
	{
		$this->client = new \SplObjectStorage;
		$this->endpoint = $endpoint.'/'.Constants::$TRANSPORTS['websocket'];
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

	public function reconnectAfterMs($tries)
	{
		return [1000, 2000, 5000, 10000][$tries - 1] ?? 10000;
	}

	public function connect()
	{
		if ($this->conn) {
			return;
		}

		$endpoint = $this->_endPointURL();

		if (preg_match('/wss:\/\//', $endpoint) == 1) {
			$origin = str_replace('https://', 'wss://', $endpoint);
		} elseif (preg_match('/ws:\/\//', $endpoint) == 1) {
			$origin = str_replace('http://', 'ws://', $endpoint);
		} else {
			throw new \Exception('Invalid endpoint');
		}

		$this->conn = new Client($endpoint, $origin);

		$this->conn->connect();

		if ($this->conn->isConnected()) {
			$this->_onConnOpen();
		}
	}

	public function disconnect($code, $reason)
	{
		if (! $this->conn) {
			return;
		}

		$this->conn->disconnect();

		$this->conn = null;
		$this->heartbeatTimer->reset();
		$this->reconnectTimer->reset();
	}

	public function getChannels()
	{
		return $this->channels;
	}

	public function removeChannel($channel)
	{
		$status = $channel->unsubscribe();
		if (count($this->channels) === 0) {
			$this->disconnect();
		}

		return $status;
	}

	public function removeAllChannels()
	{
		$channels = $this->channels;
		foreach ($channels as $channel) {
			$this->removeChannel($channel);
		}
	}

	public function log($kind, $message, $data = null)
	{
		echo $kind.' '.$message.' '.json_encode($data).PHP_EOL;
	}

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

	public function isConnected()
	{
		if (! isset($this->conn)) {
			return false;
		}

		return $this->conn->isConnected();
	}

	public function channel($topic, $params = [])
	{
		if (! $this->isConnected()) {
			$this->connect();
		}

		$_channel = new RealtimeChannel($topic, $params, $this);
		array_push($this->channels, $_channel);

		return $_channel;
	}

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

	public function _remove($channel)
	{
		$index = array_search($channel, $this->channels);
		if ($index !== false) {
			array_splice($this->channels, $index, 1);
		}
	}

	public function startReceiver()
	{
		$this->_startReceiver();
	}

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

	private function _onConnMessage($raw)
	{
		$msg = json_decode($raw);

		$topic = $msg->topic;
		$event = $msg->event;
		$payload = $msg->payload;
		$ref = $msg->ref;

		if ($ref && $ref == $this->pendingHeartbeatRef || (isset($payload->type) && $event == $payload->type)) {
			$this->pendingHeartbeatRef = null;
		}

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

	private function _onConnClose($event)
	{
		$this->log('transport', 'close', $event);
		if ($this->loop) {
			$this->_stopReceiver();
		}
		$this->triggerChanError();
		$this->heartbeatTimer->reset();
		$this->reconnectTimer->scheduleTimeout(
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

	private function _onConnError($error)
	{
		$this->log('transport', error);
		$this->triggerChanError();
		foreach ($this->stateChangeCallbacks['error'] as $callback) {
			$callback($error);
		}
	}

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

	private function _flushSendBuffer()
	{
		if ($this->isConnected() && count($this->sendBuffer) > 0) {
			$this->sendBuffer->forEach(function ($callback) {
				$callback();
			});
		}
	}

	private function _sendHeartbeat()
	{
		if (! $this->isConnected()) {
			return;
		}

		if ($this->pendingHeartbeatRef) {
			$this->pendingHeartbeatRef = null;
			$this->conn->close();

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
	}

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

	private function _startReceiver()
	{
		$this->loop = Loop::addPeriodicTimer($this->refreshRate, function () {
			$messages = $this->conn->receive();

			echo 'Checking...';

			if (! $messages) {
				return;
			}

			foreach ($messages as $message) {
				echo $message->getPayload();
				$this->_onConnMessage($message->getPayload());
			}
		});

		Loop::run();
	}

	private function _stopReceiver()
	{
		Loop::cancelTimer($this->$loop);
		$this->loop = null;
	}
}
