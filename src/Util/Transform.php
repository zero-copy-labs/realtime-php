<?php

namespace Supabase\Util;

use Supabase\Util\PostgresTypes;

class Transform {

    public static function toBoolean($value) {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return $value === 'true' || $value === 't';
        }

        return false;
    }

    public static function toInteger($value) {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value)) {
            return intval($value);
        }

        return 0;
    }

    public static function transformCell($value, $type) {
        if ($type === Postgres->Types['bool']) {
            return self::toBoolean($value);
        }

        if ($type === Postgres->Types['oid']) {
            return self::toInteger($value);
        }

        return $value;
    }

    public static function transformColumn($columnName, $columns, $record, $skipTypes) {
        $column = $columns[$columnName];
        $type = $column['type'];
        $value = $record[$columnName];

        if ($skipTypes) {
            return $value;
        }

        return self::transformCell($value, $type);
    }

    public static function transformChangeData($columns, $record, $options) {
        $skipTypes = $options['skipTypes'] ?? false;
        $columns = $options['columns'] ?? $columns;

        $transformedRecord = [];

        foreach ($columns as $columnName => $column) {
            $transformedRecord[$columnName] = self::transformColumn($columnName, $columns, $record, $skipTypes);
        }

        return $transformedRecord;
    }


}