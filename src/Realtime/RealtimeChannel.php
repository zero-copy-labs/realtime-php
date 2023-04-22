<?php

namespace Supabase\Realtime;

use Supabase\Util\Constants;
use Supabase\Util\Push;
use Supabase\Util\Timer;
use Supabase\Util\Transform;

class RealtimeChannel
{
	public $bindings = [];
	public $timeout;
	public $state;
	public $joinedOnce = false;
	public $joinPush;
	public $rejoinTimer;
	public $pushBuffer = [];
	public $presence;

	public function __construct($topic, $params = ['config' => []], $socket)
	{
		$this->state = Constants::$CHANNEL_STATES['closed'];
		$this->socket = $socket;
		$this->topic = $topic;

		$DEFAULT_CONFIG = [
			'broadcast' => [
				'ack' => false,
				'self' => false,
			],
			'presence' => ['key' => ''],
		];

		if (! isset($params['config']) || ! is_array($params['config'])) {
			$params['config'] = [];
		}

		$this->params = [
			'config' => array_merge($DEFAULT_CONFIG, $params['config']),
		];

		$this->timeout = $this->socket->timeout;
		$this->joinPush = new Push($this, Constants::$CHANNEL_EVENTS['join'], $this->params, $this->timeout);
		$closure = \Closure::fromCallable([$this, '_rejoinUntilConnected']);
		$this->rejoinTimer = new Timer($this->socket->reconnectAfterMs, \Closure::bind($closure, $this));
		$this->joinPush->receive('ok', function () {
			$this->state = Constants::$CHANNEL_STATES['joined'];
			$this->rejoinTimer->reset();
			foreach ($this->pushBuffer as $pushEvent) {
				$pushEvent->send();
			}
			$this->pushBuffer = [];
		});
		$this->_onClose(function ($reason) {
			$this->rejoinTimer->reset();
			$this->socket->log('channel', 'close', $this->topic, $reason);
			$this->state = Constants::$CHANNEL_STATES['closed'];
			$this->socket->remove($this);
		});
	}

	public function subscribe($cb = null, $timeout = null)
	{
		if (! $cb) {
			$cb = function () {
			};
		}

		if (! $timeout) {
			$timeout = $this->timeout;
		}

		if ($this->joinedOnce) {
			throw new Exception('tried to subscribe multiple times. \'subscribe\' can only be called a single time per channel instance');
		}

		$broadcast = $this->params['config']['broadcast'];
		$presence = $this->params['config']['presence'];

		$this->_onError(function ($reason) {
			if (! $cb) {
				return;
			}

			$cb('CHANNEL_ERROR', $reason);
		});

		$this->_onClose(function ($reason) {
			if (! $cb) {
				return;
			}

			$cb('CLOSED', $reason);
		});

		$postgresChanges = [];

		if (isset($this->bindings['postgres_changes'])) {
			foreach ($this->bindings['postgres_changes'] as $change) {
				array_push($postgresChanges, $change['filter']);
			}
		}

		$config = [
			'broadcast' => $broadcast,
			'presence' => $presence,
			'postgres_changes' => $postgresChanges,
		];

		$accessTokenPayload = [];

		if (isset($this->socket->accessToken)) {
			$accessTokenPayload['access_token'] = $this->socket->accessToken;
		}

		$this->updateJoinPayload(array_merge($config, $accessTokenPayload));

		$this->joinedOnce = true;

		$this->_rejoin($timeout);

		$this->joinPush->receive('ok', function ($serverPostgresFilters) {
			if ($this->socket->accessToken) {
				$this->socket->setAuth($this->socket->accessToken);
			}

			if ($serverPostgresFilters == null) {
				$cb && $cb('SUBSCRIBED');

				return;
			}

			$clientPostgresBindings = $this->bindings->postgres_changes;
			$bindingsLength = count($clientPostgresBindings);
			$newPostgresBindings = [];

			for ($i = 0; $i < $bindingsLength; $i++) {
				$clientPostgresBindings = $clientPostgresBindings[$i];
				$event = $clientPostgresBindings['filter']['event'];
				$schema = $clientPostgresBindings['filter']['schema'];
				$table = $clientPostgresBindings['filter']['table'];
				$filter = $clientPostgresBindings['filter']['filter'];

				$serverPostgresFilter = $serverPostgresFilters[$i];

				if (
					! $serverPostgresFilter
					|| $serverPostgresFilter['event'] != $event
					|| $serverPostgresFilter['schema'] != $schema
					|| $serverPostgresFilter['table'] != $table
					|| $serverPostgresFilter['filter'] != $filter
				) {
					$this->unsubscribe();
					$cb && $cb('CHANNEL_ERROR', 'server and client binding does not match for postgres changes');

					return;
				}

				array_push($newPostgresBindings, array_merge($clientPostgresBindings, ['id' => $serverPostgresFilter['id']]));
			}

			$this->bindings->postgres_changes = $newPostgresBindings;

			$cb && $cb('SUBSCRIBED');

		})->receive('error', function ($reason) {
			$cb && $cb('CHANNEL_ERROR', $reason);

		})->receive('timeout', function () {
			$cb && $cb('TIMED_OUT');

		});

		return $this;
	}

	public function track($payload, $options)
	{
		return $this->send([
			'type' => 'presence',
			'event' => 'track',
			'payload' => $payload,
		], $options->timeout || $this->timeout);
	}

	public function untrack($options)
	{
		return $this->send([
			'type' => 'presence',
			'event' => 'untrack',
		], $options);
	}

	public function on($type, $filter, $cb)
	{
		return $this->_on($type, $filter, $cb);
	}

	public function send($payload, $options)
	{
		$push = $this->push($payload->type, $payload, $options->timeout || $this->timeout);

		if ($push->rateLimited) {
			return 'rate limited';
		}

		if ($payload->type == 'broadcast' && ! isset($this->params->config->broadcast->ack)) {
			return 'ok';
		}

		$push->receive('ok', function () {
			$res = 'ok';
		});
		$push->receive('timeout', function () {
			$res = 'timeout';
		});

		return $res;
	}

	public function unsubscribe($timeout)
	{
		if (! isset($timeout)) {
			$timeout = $this->timeout;
		}
		$this->state = Constants::$CHANNEL_STATES['leaving'];

		$onClose = function () {
			$this->socket->log('channel', 'leave '.$this->topic);
			$this->_trigger(Constants::$CHANNEL_EVENTS['close'], 'leave', $this->_joinRef());
		};

		$this->rejoinTimer->reset();
		$this->joinPush->destroy();

		$leavePush = new Push($this, Constants::$CHANNEL_EVENTS['leave'], [], $timeout);

		$leavePush->receive('ok', function () {
			$onClose();
			$res = 'ok';
		});

		$leavePush->receive('timeout', function () {
			$onClose();
			$res = 'timeout';
		});

		$leavePush->receive('error', function () {
			$res = 'error';
		});

		$leavePush->send();

		if (! $this->_canPush()) {
			$leavePush->trigger('ok', []);
		}
	}

	public function updateJoinPayload($payload)
	{
		$this->joinPush->updatePayload($payload);

		// $onClose = function() {
		//     $this->socket->log('channel', 'leave ' . $this->topic);
		//     $this->_trigger(Constants::$CHANNEL_EVENTS['close'], 'leave', $this->_joinRef());
		// };

		// $this->rejoinTimer->reset();

		// $this->joinPush->destroy();

		// $leavePush = new Push($this, Constants::$CHANNEL_EVENTS['leave'], [], $timeout);

		// $leavePush->receive('ok', function() {
		//     $onClose();
		//     $res = 'ok';
		// });

		// $leavePush->receive('timeout', function() {
		//     $onClose();
		//     $res = 'timeout';
		// });

		// $leavePush->receive('error', function() {
		//     $res = 'error';
		// });

		// $leavePush->send();

		// if(!$this->_canPush()) {
		//     $leavePush->trigger('ok', []);
		// }

		// return $res;
	}

	private function _rejoinUntilConnected()
	{
		$this->rejoinTimer->scheduleTimeout();

		if ($this->socket->isConnected()) {
			$this->_rejoin();
		}
	}

	private function _push($event, $payload, $timeout)
	{
		if (! $this->joinedOnce) {
			throw new Exception('tried to push \''.$event.'\' to \''.$this->topic.'\' before joining. Use channel.subscribe() before pushing events');
		}

		$pushEvent = new Push($this, $event, $payload, $timeout);

		if ($this->_canPush()) {
			$pushEvent->send();
		} else {
			$pushEvent->startTimeout();
			array_push($this->pushBuffer, $pushEvent);
		}

		return $pushEvent;
	}

	private function _onMessage($_event, $payload)
	{
		return $payload;
	}

	private function _isMember($topic)
	{
		return $this->topic === $topic;
	}

	public function _joinRef()
	{
		return $this->joinPush->ref;
	}

	public function _trigger($type, $payload, $ref = null)
	{
		$typeLower = strtolower($type);
		$close = Constants::$CHANNEL_EVENTS['close'];
		$error = Constants::$CHANNEL_EVENTS['error'];
		$leave = Constants::$CHANNEL_EVENTS['leave'];
		$join = Constants::$CHANNEL_EVENTS['join'];

		$events = [$close, $error, $leave, $join];

		if ($ref && in_array($typeLower, $events) && $ref != $this->_joinRef()) {
			return;
		}

		$handledPayload = $this->_onMessage($typeLower, $payload);

		if ($payload && ! $handledPayload) {
			throw new Exception('channel onMessage callbacks must return the payload, modified or unmodified');
		}

		$types = ['insert', 'update', 'delete'];

		if (in_array($typeLower, $types)) {
			$applicableBindings = array_filter($this->bindings->postgres_changes, function ($binding) {
				return $binding['filter']['event'] == $typeLower;
			});

			foreach ($applicableBindings as $binding) {
				$binding['callback']($handledPayload, $ref);
			}

			return;
		}

		$applicableBindings = array_filter($this->bindings[$typeLower], function ($binding) {
			if (in_array($typeLower, ['broadcast', 'presence', 'postgres_changes'])) {
				$bindEvent = strtolower($binding->filter->event);
				$payloadType = strtolower($payload->type);

				if ($binding['id']) {
					$bindId = $binding['id'];
					$bindEvent = $binding->filter->event;

					return $bindId && in_array($bindId, $payload->ids) && (
						$bindEvent == '*' || $bindEvent == $payloadType
					);
				}

				return $bindEvent == '*' || $bindEvent == $payloadType;
			}

			return strtolower($binding->type) == $typeLower;
		});

		foreach ($applicableBindings as $binding) {
			$postgresChanges = $handledPayload->data;
			$schema = $postgresChanges['schema'];
			$table = $postgresChanges['table'];
			$commit_timestamp = $postgresChanges['commit_timestamp'];
			$type = $postgresChanges['type'];
			$errors = $postgresChanges['errors'];

			$_payload = [
				'schema' => $schema,
				'table' => $table,
				'commit_timestamp' => $commit_timestamp,
				'type' => $type,
				'errors' => $errors,
				'new' => [],
				'old' => [],
			];

			$handledPayload = array_merge($_payload, $this._getPayloadRecords($postgresChanges));

			$binding['callback']($handledPayload, $ref);
		}
	}

	public function _isClosed()
	{
		return $this->state === Constants::$CHANNEL_STATES['closed'];
	}

	public function _isJoined()
	{
		return $this->state === Constants::$CHANNEL_STATES['joined'];
	}

	public function _isJoining()
	{
		return $this->state === Constants::$CHANNEL_STATES['joining'];
	}

	public function _isLeaving()
	{
		return $this->state === Constants::$CHANNEL_STATES['leaving'];
	}

	public function _replyEventName($ref)
	{
		return 'chan_reply_'.$ref;
	}

	private function _on($type, $filter, $cb)
	{
		$_type = strtolower($type);

		$binding = [
			'type' => $_type,
			'filter' => $filter,
			'callback' => $cb,
		];

		if (isset($this->bindings[$_type])) {
			array_push($this->bindings[$_type], $binding);
		} else {
			$this->bindings[$_type] = [$binding];
		}

		return $this;
	}

	private function _off($type, $filter)
	{
		$_type = strtolower($type);

		$this->bindings[$_type] = $this->bindings[$_type].filter(function ($bind) {
			return ! self::isEqual($bind['event'], $filter);
		});

		return $this;
	}

	private static function isEqual($a, $b)
	{
		return count(array_diff_assoc($a, $b)) == 0;
	}

	private function _onError($cb)
	{
		$this->on(Constants::$CHANNEL_EVENTS['error'], [], function ($reason) {
			$cb($reason);
		});
	}

	private function _onClose($cb)
	{
		$this->on(Constants::$CHANNEL_EVENTS['close'], [], function ($reason) {
			$cb($reason);
		});
	}

	private function _canPush()
	{
		return $this->socket->isConnected() && $this->_isJoined();
	}

	private function _rejoin($timeout)
	{
		if (! isset($timeout)) {
			$timeout = $this->timeout;
		}

		if ($this->_isLeaving()) {
			return;
		}

		$this->socket->_leaveOpenTopic($this->topic);
		$this->state = Constants::$CHANNEL_STATES['joining'];
		$this->joinPush->resend($timeout);
	}

	private function _getPayloadRecords($payload)
	{
		$records = [
			'new' => [],
			'old' => [],
		];

		if ($payload->type == 'INSERT' || $payload->type == 'UPDATE') {
			$records['new'] = Transform::tranformChangeData($payload->columns, $payload->record);
		}

		if ($payload->type == 'UPDATE' || $payload->type == 'DELETE') {
			$records['old'] = Transform::tranformChangeData($payload->columns, $payload->old_record);
		}

		return $records;
	}
}
