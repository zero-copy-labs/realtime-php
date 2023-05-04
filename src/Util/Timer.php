<?php

namespace Supabase\Realtime\Util;

use React\EventLoop\Loop;

class Timer
{
	private $timer;
	public $tries = 0;

	public function __construct()
	{
	}

	/**
	 * Cancel timer.
	 *
	 * @return void
	 */
	public function reset()
	{
		$this->tries = 0;
		if (isset($this->timer)) {
			Loop::cancelTimer($this->timer);
		}
	}

	/**
	 * Create timeout timer.
	 *
	 * @param $fn
	 * @param $timeoutFn
	 * @return void
	 */
	public function schedule($fn, $timeoutFn)
	{
		if (isset($this->timer)) {
			Loop::cancelTimer($this->timer);
		}

		$tries = $this->tries;
		$timeout = $timeoutFn($this->tries);

		Loop::addTimer($timeout, function () use ($tries, $fn) {
			$tries = $this->tries + 1;
			$fn();
		});
	}

	/**
	 * Create interval timer.
	 *
	 * @param $fn
	 * @param $timeoutFn
	 * @return void
	 */
	public function interval($fn, $timeoutFn)
	{
		$timeout = $timeoutFn() / 1000; // MS to Seconds
		$timer = Loop::addPeriodicTimer($timeout, function () use ($fn) {
			$fn();
		});
	}
}
