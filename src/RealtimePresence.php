<?php

namespace Supabase\Realtime;

$defaultEvents = [
    'state' => 'presence_state',
    'diff' => 'presence_diff',
];

class RealtimePresence
{
    $state;
    $pendingDiffs;
    $joinRef;
    $caller = [
        'onJoin' => function() {},
        'onLeave' => function() {},
        'onSync' => function() {},
    ];
    $channel;


    public function __construct($channel, $options)
    {
        $events = $options['events'] || $defaultEvents;

        $this->channel = $channel;

        $channel->_on($events['state'], [], function($newState) {
            $onJoin = $this->caller['onJoin'];
            $onLeave = $this->caller['onLeave'];
            $onSync = $this->caller['onSync'];

            $this->state = RealtimePresence::syncState($this->state, $newState, $onJoin, $onLeave);

            foreach($this->pendingDiffs as $diff) {
                $this->state = RealtimePresence::syncDiff($this->state, $diff, $onJoin, $onLeave);
            }
        });
    }
}
