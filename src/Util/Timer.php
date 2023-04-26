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

	/**
	 * Cancel timer
	 * @return void
	 */

	public function reset()
	{
		echo PHP_EOL . 'Timer Reset' . PHP_EOL;
		$this->tries = 0;
		if (isset($this->timer)) {
			Loop::cancelTimer($this->timer);
		}
	}

	/**
	 * Create timeout timer
	 * @param $fn
	 * @param $timeoutFn
	 * @return void
	 */

	public function schedule($fn, $timeoutFn)
	{
		if (isset($this->timer)) {
			Loop::cancelTimer($this->timer);
		}

		echo 'Timer Started' . PHP_EOL;

		$tries = $this->tries;
		$timeout = $timeoutFn($this->tries);

		echo 'Timeout:' . $timeout . 'Seconds' . PHP_EOL;

		$timer;

		Loop::addTimer($timeout, function () use ($tries, $fn) {
			echo 'Timer expired'.PHP_EOL;
			$tries = $this->tries + 1;
			$fn();
		});
	}

	/**
	 * Create interval timer
	 * @param $fn
	 * @param $timeoutFn
	 * @return void
	 */

	public function interval($fn, $timeoutFn)
	{

		
		$timeout = $timeoutFn() / 1000; // MS to Seconds
		echo 'Interval Started with time:' . $timeout . 'Seconds' . PHP_EOL;
		$timer = Loop::addPeriodicTimer($timeout, function () use ($fn) {
			echo 'Interval expired' . PHP_EOL;
			$fn();
		});
	}
}
