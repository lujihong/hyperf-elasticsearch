<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch\Model;

/**
 * Author lujihong
 * Description
 */
class MySqlType
{
    public const int = 'int';
    public const int_unsigned = 'int_unsigned';
    public const varchar = 'varchar';
    public const decimal = 'decimal';
    public const decimal_unsigned = 'decimal_unsigned';
    public const tinyint = 'tinyint';
    public const tinyint_unsigned = 'tinyint_unsigned';
    public const mediumint = 'mediumint';
    public const mediumint_unsigned = 'mediumint_unsigned';
    public const smallint = 'smallint';
    public const smallint_unsigned = 'smallint_unsigned';
    public const bigint = 'bigint';
    public const bigint_unsigned = 'bigint_unsigned';
    public const double = 'double';
    public const double_unsigned = 'double_unsigned';
    public const float = 'float';
    public const float_unsigned = 'float_unsigned';
    public const char = 'char';
    public const longtext = 'longtext';
    public const mediumtext = 'mediumtext';
    public const tinytext = 'tinytext';
    public const date = 'date';
    public const datetime = 'datetime';
    public const timestamp = 'timestamp';
    public const time = 'time';
    public const year = 'year';
    public const text = 'text';
    public const json = 'object';
    /**
     * 保存经纬度
     */
    public const point = 'point';

    //不常用
    public const blob = 'blob';
    public const binary = 'binary';
    public const bit = 'bit';
    public const real = 'real';
    public const geometry = 'geometry';
    public const linestring = 'linestring';
    public const polygon = 'polygon';
    public const multipoint = 'multipoint';
    public const multilinestring = 'multilinestring';
    public const multipolygon = 'multipolygon';
    public const geometrycollection = 'geometrycollection';
}