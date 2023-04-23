<?php

namespace Supabase\Realtime;

use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;

class Realtime implements MessageComponentInterface
{
	private $log;
	private $stateChangeCallbacks;

	public function __construct($log, $stateChangeCallbacks)
	{
		$this->log = $log;
		$this->stateChangeCallbacks = $stateChangeCallbacks;
	}

	public function onOpen(ConnectionInterface $conn)
	{
		$this->log('transport', 'connected to '.$conn->remoteAddress);
		$this->stateChangeCallbacks['open']();
	}

	public function onMessage(ConnectionInterface $from, $msg)
	{
		$this->log('transport', 'message received: '.$msg);
		$this->stateChangeCallbacks['message']($msg, $from);
	}

	public function onClose(ConnectionInterface $conn)
	{
		$this->log('transport', 'close');
		$this->stateChangeCallbacks['close']();
	}

	public function onError(ConnectionInterface $conn, \Exception $e)
	{
		$this->log('transport', $e->getMessage());
		$this->stateChangeCallbacks['error']();
	}
}
