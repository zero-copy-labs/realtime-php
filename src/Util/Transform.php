<?php

namespace Supabase\Realtime\Util;

use Supabase\Realtime\Util\Postgres;

class Transform
{
	public static function toBoolean($value)
	{
		if (is_bool($value)) {
			return $value;
		}

		if (is_string($value)) {
			return $value === 'true' || $value === 't';
		}

		return false;
	}

	public static function toInteger($value)
	{
		if (is_int($value)) {
			return $value;
		}

		if (is_string($value)) {
			return intval($value);
		}

		return 0;
	}

	public static function transformCell($value, $type)
	{
		if ($type === Postgres::$TYPES['bool']) {
			return self::toBoolean($value);
		}

		if ($type === Postgres::$TYPES['oid']) {
			return self::toInteger($value);
		}

		return $value;
	}

	public static function transformColumn($columnName, $column, $record, $skipTypes)
	{

		$type = isset($column->type) ? $column->type : null;
		$value = $record->$columnName;

		if ($skipTypes) {
			return $value;
		}

		$transformedValue = self::transformCell($value, $type);

		return $transformedValue;
	}

	public static function transformChangeData($columns, $record, $options = [])
	{
		$skipTypes = isset($options['skipTypes']) ? $options['skipTypes'] : false;

		$transformedRecord = [];

		foreach ($columns as $column) {

			$columnName = $column->name;

			$transformedRecord[$columnName] = self::transformColumn($columnName, $column, $record, $skipTypes);
		}

		return $transformedRecord;
	}
}
