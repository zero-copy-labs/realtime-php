<?php

namespace Supabase\Util;

class Postgres 
{

    public static $TYPES = [
        'abstime' => 'abstime',
        'bool' => 'bool',
        'date' => 'date',
        'daterange' => 'daterange',
        'float4' => 'float4',
        'float8' => 'float8',
        'int2' => 'int2',
        'int4' => 'int4',
        'int4range' => 'int4range',
        'int8' => 'int8',
        'int8range' => 'int8range',
        'json' => 'json',
        'jsonb' => 'jsonb',
        'money' => 'money',
        'numeric' => 'numeric',
        'oid' => 'oid',
        'reltime' => 'reltime',
        'text' => 'text',
        'time' => 'time',
        'timestamp' => 'timestamp',
        'timestamptz' => 'timestamptz',
        'timetz' => 'timetz',
        'tsrange' => 'tsrange',
        'tstzrange' => 'tstzrange',
    ]
}