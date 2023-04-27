<?php

namespace Supabase\Util;

class Push
{
	public $sent = false;
	public $timeoutTimer;
	public $ref;
	public $result;
	public $hooks = [];
	public $refEvent;
	public $rateLimited = false;

	public $channel;
	public $event;
	public $payload;
	public $timeout;

	public function __construct($channel, $event, $payload, $timeout)
	{
		$this->channel = $channel;
		$this->event = $event;
		$this->payload = $payload;
		$this->timeout = $timeout;
	}

	public function resend($timeout)
	{
		$this->timeout = $timeout;
		$this::_cancelRefEvent();
		$this->ref = '';
		$this->refEvent = null;
		$this->result = null;
		$this->sent = false;
		$this::send();
	}

	public function send()
	{
		if ($this->_hasReceived('timeout')) {
			return;
		}

		$this->startTimeout();
		$this->sent = true;

		$status = $this->channel->socket->push([
			'topic' => $this->channel->topic,
			'event' => $this->event,
			'payload' => $this->payload,
			'ref' => $this->ref,
			'join_ref' => $this->channel->_joinRef(),
		]);

		if ($status = 'rate limited') {
			$this->rateLimited = true;
		}
	}

	public function updatePayload($payload)
	{
		$this->payload = array_merge($this->payload, $payload);
	}

	public function receive($status, $response)
	{
		if ($this->_hasReceived('timeout')) {
			return;
		}

		array_push($this->hooks, ['status' => $status, 'response' => $response]);

		return $this;
	}

	public function startTimeout()
	{
		if ($this->timeoutTimer) {
			return;
		}

		$this->ref = $this->channel->socket->_makeRef();
		$this->refEvent = $this->channel->_replyEventName($this->ref);

		$fn = function ($payload) {
			$this->destroy();
			$this->result = $payload;
			$this->_matchResult($payload);
		};

		$refEvent = $this->refEvent;

		$this->channel->on($refEvent, [], $fn);

		$this->timeoutTimer = new Timer();
		$this->timeoutTimer->schedule(function () {
			$this->trigger('timeout', []);
		}, function () {
			return $this->timeout;
		});
	}

	public function destroy()
	{
		$this->_cancelRefEvent();
		$this->_cancelTimeout();
	}

	public function trigger($status, $response)
	{
		if ($this->refEvent) {
			$this->channel->_trigger($this->refEvent, ['status' => $status, 'response' => $response]);
		}
	}

	private function _cancelRefEvent()
	{
		if (! $this->refEvent) {
			return;
		}

		$this->channel->_off($this->refEvent, []);
	}

	private function _cancelTimeout()
	{
		// Doesnt exist for php... may have to look at set_time_limit instead?
	}

	private function _matchResult($result)
	{
		if(!isset($result->status)) {
			return;
		}

		$status = $result->status;
		$response = $result->response;

		foreach ($this->hooks as $hook) {
			$hookStatus = $hook['status'];
			$hookResponse = $hook['response'];

			if ($hookStatus === $status) {
				$hookResponse($response);
			}
		}
	}

	private function _hasReceived($status)
	{
		return $this->result && $this->result['status'] === $status;
	}
}
