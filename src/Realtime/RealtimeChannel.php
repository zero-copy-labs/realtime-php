<?php

namespace Supabase\Realtime;

use Supabase\Util\Timer;
use Supabase\Util\Transform;
use Supabase\Util\PostgresTypes;
use Supabase\Util\Constants;
use Supabase\Util\Push;

$DEFAULT_CONFIG = [
    'broadcast' => [
        'ack' => false,
        'self' => false,
    ],
    'presence' => ['key' => '']
];

class RealtimeChannel
{
    public $bindings = [];
    public $timeout;
    public $state = Constants::CHANNEL_STATES['closed'];
    public $joinedOnce = false;
    public $joinPush;
    public $rejoinTimer;
    public $pushBuffer = [];
    public $presence;

    function __construct($topic, $params, $socket)
    {
        $this->socket = $socket;
        $this->topic = $topic;
        $this->params = [
            'config' => array_merge($DEFAULT_CONFIG, $params['config'])
        ];

        $this->timeout = $this->socket->timeout;
        $this->joinPush = new Push($this, Constants::CHANNEL_EVENTS['join'], $this->params, $this->timeout);
        $this->rejoinTimer = new Timer($this->socket->reconnectAfterMs, $this->rejoinUntilConnected.bind($this));
        $this->joinPush->receive('ok', function () {
            $this->state = Constants::CHANNEL_STATES['joined'];
            $this->rejoinTimer->reset();
            foreach($this->pushBuffer as $pushEvent) {
                $pushEvent->send();
            }
            $this->pushBuffer = [];
        });
        $this->onClose(function ($reason) {
            $this->rejoinTimer->reset();
            $this->socket->log('channel', 'close', $this->topic, $reason);
            $this->state = Constants::CHANNEL_STATES['closed'];
            $this->socket->remove($this);
        });
    }

    private _isClosed() {
        return $this->state === Constants::CHANNEL_STATES['closed'];
    }

    private _isJoined() {
        return $this->state === Constants::CHANNEL_STATES['joined'];
    }

    private function _isJoining() {
        return $this->state === Constants::CHANNEL_STATES['joining'];
    }

    private function _isLeaving() {
        return $this->state === Constants::CHANNEL_STATES['leaving'];
    }

    private function _replyEventName($ref) {
        return 'chan_reply_' . $ref;
    }

    private function _on($type, $filter, $cb) {
        $_type = strtolower($type);

        $binding = [
            'type' => $_type,
            'filter' => $filter,
            'callback' => $cb
        ]

        if ($this->bindings[$_type] == null) {
            array_push($this->bindings[$_type], $binding);
        } else {
            $this->bindings[$_type] = [$binding];
        }

        return $this;
    }

    private function _off($type, $filter) {
        $_type = strtolower($type);

        $this->bindings[$_type] = $this->bindings[$_type].filter(function ($bind) {
            return !self::isEqual($bind['event'], $filter);
        });

        return $this;
    }

    private static function isEqual($a, $b){
        return count(array_diff_assoc($a, $b)) == 0;
    }

    private function _onError($cb){
        $this->on(Constants::CHANNEL_EVENTS['error'], [], function ($reason) {
            $cb($reason);
        });
    }

    private function _onClose($cb){
        $this->on(Constants::CHANNEL_EVENTS['close'], [], function ($reason) {
            $cb($reason);
        });
    }

    private function _canPush(){
        return $this->socket->isConnected() && $this->_isJoined();
    }

    private function _rejoin($timeout = $this->timeout){
        if($this->_isLeaving()){
            return;
        }

        $this->socket->leaveOpenTopic($this->topic);
        $this->state = Constants::CHANNEL_STATES['joining'];
        $this->joinPush->resend($timeout);
    }

    private _getPayloadRecords($payload) {
        $records = [
            'new' => [],
            'old' => []
        ];

        if($payload->type == "INSERT" || $payload->type == "UPDATE") {
            $records['new'] = Transform::tranformChangeData($payload->columns, $payload->record);
        }

        if($payload->type == "UPDATE" || $payload->type == "DELETE") {
            $records['old'] = Transform::tranformChangeData($payload->columns, $payload->old_record);
        }

        return $records;
    }
}