<?php

namespace Supabase\Util;

class Timer 
{
    private $timer;
    private $tries = 0;

    public function __construct($timerFn, $fn)
    {
        $this->timer = $timerFn;
        $this->fn = $fn;
    }

    public function reset()
    {
        $this->tries = 0;
    }

    public function schedule()
    {
        $timelimit = $this->timer();
        $this->tries++;
        sleep($timelimit);
        $this->fn();
    }
}