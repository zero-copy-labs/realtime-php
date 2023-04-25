<?php

namespace Supabase\Util;

use React\EventLoop\Loop;

class Timer
{
	private $timer;
	public $tries = 0;

	public function __construct()
	{
	}

	public function reset()
	{
		$this->tries = 0;
		if (isset($this->timer)) {
			Loop::cancelTimer($this->timer);
		}
	}

	public function schedule($fn, $timeoutFn)
	{
		if (isset($this->timer)) {
			Loop::cancelTimer($this->timer);
		}

		echo 'Timer Started';

		$tries = $this->tries;
		$timeout = $timeoutFn($this->tries);

		$timer = Loop::addTimer($timeout, function () use ($tries, $fn) {
			echo 'Timer expired'.PHP_EOL;
			$tries = $this->tries + 1;
			Loop::cancelTimer($timer);
			$fn();
		});
	}

	public function interval($fn, $timeoutFn)
	{
		$timeout = $timeoutFn() / 1000; // MS to Seconds
		$timer = Loop::addPeriodicTimer($timeout, function () use ($fn) {
			$fn();
		});
	}
}
