<?php

namespace Supabase\Util;

class Constants
{
	public static $VERSION = '0.0.1';

	public static $VSN = '1.0.0';

	public static $DEFAULT_TIMEOUT = 10000;

	public static $SOCKET_CLOSE = 1000;

	public static $SOCKET_STATES = [
		'connecting' => 0,
		'open' => 1,
		'closing' => 2,
		'closed' => 3,
	];

	public static $CHANNEL_STATES = [
		'closed' => 'closed',
		'errored' => 'errored',
		'joined' => 'joined',
		'joining' => 'joining',
		'leaving' => 'leaving',
	];

	public static $CHANNEL_EVENTS = [
		'close' => 'phx_close',
		'error' => 'phx_error',
		'join' => 'phx_join',
		'reply' => 'phx_reply',
		'leave' => 'phx_leave',
		'access_token' => 'access_token',
	];

	public static $TRANSPORTS = [
		'websocket' => 'websocket',
	];

	public static $CONNECTION_STATES = [
		'connecting' => 'connecting',
		'open' => 'open',
		'closing' => 'closing',
		'closed' => 'closed',
	];

	public static function getDefaultHeaders()
	{
		return [
			'X-Client-Info' => 'storage-php/'.self::$VERSION,
		];
	}
}
