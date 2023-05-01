<?php

namespace Supabase\Realtime;

use Supabase\Realtime\Util\Constants;
use Supabase\Realtime\Util\Push;
use Supabase\Realtime\Util\Timer;
use Supabase\Realtime\Util\Transform;

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
	public $topic;
	public $params;
	public $socket;

	public function __construct($topic, $params, $socket)
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

		$this->params = [
			'config' => array_merge($DEFAULT_CONFIG, $params ?? []),
		];

		$this->timeout = $this->socket->timeout;
		$this->joinPush = new Push($this, Constants::$CHANNEL_EVENTS['join'], $this->params, $this->timeout);
		$this->rejoinTimer = new Timer();
		$this->joinPush->receive('ok', function () {
			$this->state = Constants::$CHANNEL_STATES['joined'];
			$this->rejoinTimer->reset();
			foreach ($this->pushBuffer as $pushEvent) {
				$pushEvent->send();
			}
			$this->pushBuffer = [];
		});
		$this->_onError(function ($reason) {
			if ($this->_isLeaving() || $this->_isClosed()) {
				return;
			}

			$this->socket->log('channel', 'error', $this->topic, $reason);
			$this->state = Constants::$CHANNEL_STATES['errored'];
			$this->rejoinTimer->schedule(function () {
				$this->_rejoinUntilConnected();
			}, function ($tries) {
				return $this->socket->reconnectAfterMs($tries);
			});
		});
		$this->_onClose(function ($reason) {
			$this->rejoinTimer->reset();
			$this->socket->log('channel', 'close', $this->topic, $reason);
			$this->state = Constants::$CHANNEL_STATES['closed'];
			$this->socket->_remove($this);
		});

		$this->joinPush->receive('timeout', function () {
			if (! $this->isJoining()) {
				return;
			}

			$this->socket->log('channel', 'timeout'.$this->topic, $this->joinPush->timeout);
			$this->state = Constants::$CHANNEL_STATES['errored'];
			$this->rejoinTimer->schedule(function () {
				$this->_rejoinUntilConnected();
			}, function ($tries) {
				return $this->socket->reconnectAfterMs($tries);
			});
		});

		// $replyEventName = function ($ref) {
		// 	return $this->_replyEventName($ref);
		// };

		$context = $this;

		$this->_on(Constants::$CHANNEL_EVENTS['reply'], [], function ($payload, $ref) use ($context) {
			$context->_trigger($context->_replyEventName($ref), $payload);
		});
	}

	/**
	 * Subscribe to a channel (topic).
	 *
	 * @param  callable  $cb
	 * @param  int  $timeout
	 * @return
	 */
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

		$this->_onError(function ($reason) use ($cb) {
			if (! $cb) {
				return;
			}

			$cb('CHANNEL_ERROR', $reason);
		});

		$this->_onClose(function ($reason) use ($cb) {
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

		$payload = [
			'config' => [
				'broadcast' => $this->params['config']['broadcast'],
				'postgres_changes' => $postgresChanges,
				'presence' => $this->params['config']['presence'],
			],
		];

		$accessTokenPayload = [];

		if (isset($this->socket->accessToken)) {
			$accessTokenPayload['access_token'] = $this->socket->accessToken;
		}

		$this->updateJoinPayload(array_merge($payload, $accessTokenPayload));

		$this->joinedOnce = true;

		$this->_rejoin($timeout);

		$this->joinPush->receive('ok', function ($response) use ($cb) {
			if (isset($this->socket->accessToken)) {
				$this->socket->setAuth($this->socket->accessToken);
			}

			$serverPostgresFilters = $response->postgres_changes;

			if ($serverPostgresFilters == null || count($serverPostgresFilters) == 0) {
				$cb && $cb('SUBSCRIBED');

				return;
			}

			$clientPostgresBindings = $this->bindings['postgres_changes'];
			$bindingsLength = count($clientPostgresBindings);
			$newPostgresBindings = [];

			for ($i = 0; $i < $bindingsLength; $i++) {
				$clientPostgresBindings = $clientPostgresBindings[$i];
				$event = $clientPostgresBindings['filter']['event'];
				$schema = $clientPostgresBindings['filter']['schema'];
				$table = $clientPostgresBindings['filter']['table'];
				$filter = isset($clientPostgresBindings['filter']['filter']) ? $clientPostgresBindings['filter']['filter'] : null;

				$serverPostgresFilter = $serverPostgresFilters[$i];
				$serverFilter = isset($serverPostgresFilter->filter) ? $serverPostgresFilter->filter : null;

				if (
					! $serverPostgresFilter
					|| $serverPostgresFilter->event != $event
					|| $serverPostgresFilter->schema != $schema
					|| $serverPostgresFilter->table != $table
					|| $serverFilter != $filter
				) {
					$this->unsubscribe();
					$cb && $cb('CHANNEL_ERROR', 'server and client binding does not match for postgres changes');

					return;
				}

				array_push($newPostgresBindings, array_merge($clientPostgresBindings, ['id' => $serverPostgresFilter->id]));
			}

			$this->bindings['postgres_changes'] = $newPostgresBindings;

			$cb('SUBSCRIBED');
		});

		$this->joinPush->receive('error', function ($err) {
			$cb('CHANNEL_ERROR', $err);
		});

		$this->joinPush->receive('timeout', function () {
			$cb('TIMED_OUT');
		});

		$this->socket->receiveMessages();

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

	public function _off($type, $filter)
	{
		$typeLower = strtolower($type);

		$this->bindings[$typeLower] = array_filter($this->bindings[$typeLower], function ($binding) use ($filter, $typeLower) {
			return strtolower($binding['type']) == $typeLower && $this->isEqual($binding['filter'], $filter);
		});
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

	/** Unsubscribe From Channel.
	 *  @param {integer} timeout
	 */
	public function unsubscribe($timeout = null)
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

		$leavePush->receive('ok', function () use ($onClose) {
			$onClose();
			$res = 'ok';
		});

		$leavePush->receive('timeout', function () use ($onClose) {
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

	/**
	 * Update Channel Join Payload.
	 *
	 * @param {object} payload
	 * returns {void}
	 */
	public function updateJoinPayload($payload)
	{
		$this->joinPush->updatePayload($payload);
	}

	/**
	 * Rejoin Channel Until Connected
	 * returns {void}.
	 */
	private function _rejoinUntilConnected()
	{
		$this->socket->log('rejoin until connected');
		$this->rejoinTimer->schedule(function () {
			$this->_rejoinUntilConnected();
		}, function ($tries) {
			return $this->socket->reconnectAfterMs($tries);
		});

		$this->socket->log('rejoin is connected? '.$this->socket->isConnected());

		if ($this->socket->isConnected()) {
			$this->_rejoin();
		}
	}

	private function _push($event, $payload, $timeout)
	{
		if (! $this->joinedOnce) {
			throw new Exception('tried to push \''.$event.'\' to \''.$this->topic.'\' before joining. Use channel->subscribe() before pushing events');
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

	public function _isMember($topic)
	{
		return $this->topic === $topic;
	}

	public function _joinRef()
	{
		return $this->joinPush->ref;
	}

	public function _getPayloadRecords($payload)
	{
		$records = [
			'new' => [],
			'old' => [],
		];

		if ($payload->type == 'INSERT' || $payload->type == 'UPDATE') {
			$records['new'] = Transform::transformChangeData($payload->columns, $payload->record);
		}

		if ($payload->type == 'UPDATE' || $payload->type == 'DELETE') {
			$records['old'] = Transform::transformChangeData($payload->columns, $payload->old_record);
		}

		return $records;
	}

	public function _trigger($type, $payload = null, $ref = null)
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
			$applicableBindings = array_filter($this->bindings->postgres_changes, function ($binding) use ($typeLower) {
				return $binding['filter']['event'] == $typeLower;
			});

			foreach ($applicableBindings as $binding) {
				$binding['callback']($handledPayload, $ref);
			}

			return;
		}

		$applicableBindings = [];

		if (isset($this->bindings[$typeLower]) && count($this->bindings[$typeLower]) > 0) {
			$applicableBindings = array_filter($this->bindings[$typeLower], function ($binding) use ($typeLower, $payload) {
				if (in_array($typeLower, ['broadcast', 'presence', 'postgres_changes'])) {
					$bindEvent = strtolower($binding['filter']['event']);
					$payloadType = strtolower($payload->data->type);

					if (isset($binding['id'])) {
						$bindId = $binding['id'];
						$bindEvent = strtolower($binding['filter']['event']);

						return $bindId && in_array($bindId, $payload->ids) && (
							$bindEvent == '*' || $bindEvent == $payloadType
						);
					}

					return $bindEvent == '*' || $bindEvent == $payloadType;
				}

				return strtolower($binding['type']) == $typeLower;
			});
		}

		foreach ($applicableBindings as $binding) {
			if (isset($handledPayload->ids)) {
				$postgresChanges = $handledPayload->data;
				$schema = $postgresChanges->schema;
				$table = $postgresChanges->table;
				$commit_timestamp = $postgresChanges->commit_timestamp;
				$type = $postgresChanges->type;
				$errors = $postgresChanges->errors;

				$_payload = [
					'schema' => $schema,
					'table' => $table,
					'commit_timestamp' => $commit_timestamp,
					'type' => $type,
					'errors' => $errors,
					'new' => [],
					'old' => [],
				];

				$records = $this->_getPayloadRecords($postgresChanges);

				$handledPayload = array_merge($_payload, $records);
			}

			$binding['callback']($handledPayload, $ref);
		}
	}

	/**
	 * Channel is Closed.
	 *
	 * @return bool
	 */
	public function _isClosed()
	{
		return $this->state === Constants::$CHANNEL_STATES['closed'];
	}

	/**
	 * Channel is Joined.
	 *
	 * @return bool
	 */
	public function _isJoined()
	{
		return $this->state === Constants::$CHANNEL_STATES['joined'];
	}

	/**
	 * Channel is Joining.
	 *
	 * @return bool
	 */
	public function _isJoining()
	{
		return $this->state === Constants::$CHANNEL_STATES['joining'];
	}

	/**
	 * Channel is Leaving.
	 *
	 * @return bool
	 */
	public function _isLeaving()
	{
		return $this->state === Constants::$CHANNEL_STATES['leaving'];
	}

	/**
	 * Channel Event Hook Ref.
	 */
	public function _replyEventName($ref)
	{
		return 'chan_reply_'.$ref;
	}

	/**
	 * Bind Hook to Channel.
	 *
	 * @param  string  $type
	 * @param  array  $filter
	 * @param  function  $cb
	 * @return $this
	 */
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

	/** Check if associative arrays match.
	 * @param  array  $a
	 * @param  array  $b
	 * @return bool
	 */
	private static function isEqual($a, $b)
	{
		return count(array_diff_assoc($a, $b)) == 0;
	}

	/**
	 * On Channel Error.
	 *
	 * @param  function  $cb
	 * @return void
	 */
	private function _onError($cb)
	{
		$this->on(Constants::$CHANNEL_EVENTS['error'], [], function ($reason) use ($cb) {
			$cb($reason);
		});
	}

	/**
	 * On Channel Close.
	 *
	 * @param  function  $cb
	 * @return void
	 */
	private function _onClose($cb)
	{
		$this->on(Constants::$CHANNEL_EVENTS['close'], [], function ($reason) use ($cb) {
			$cb($reason);
		});
	}

	/**
	 * Can push channel messages.
	 *
	 * @return bool
	 */
	private function _canPush()
	{
		return $this->socket->isConnected() && $this->_isJoined();
	}

	/**
	 * Rejoin Channel.
	 *
	 * @param  int  $timeout
	 * @return void
	 */
	private function _rejoin($timeout = null)
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

		$this->socket->receiveMessages();
	}
}
