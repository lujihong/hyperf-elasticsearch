<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch\Model;

use Hyperf\Utils\Codec\Json;

/**
 * 属性
 */
trait HasAttributes
{
    /**
     * The model's attributes.
     * @var array
     */
    private array $attributes = [];

    /**
     * The model attribute's original state.
     * @var array
     */
    private array $original = [];

    /**
     * The attributes that should be cast.
     * @var array
     */
    protected array $casts = [];

    /**
     * @var array|string[]
     */
    private array $castTypes = [
        'int' => 'integer',
        'int_unsigned' => 'long',
        'varchar' => 'text',
        'decimal' => 'double',
        'decimal_unsigned' => 'double',
        'tinyint' => 'short',
        'tinyint_unsigned' => 'integer',
        'mediumint' => 'integer',
        'mediumint_unsigned' => 'integer',
        'smallint' => 'short',
        'smallint_unsigned' => 'integer',
        'bigint' => 'long',
        'bigint_unsigned' => 'long',
        'double' => 'double',
        'double_unsigned' => 'double',
        'float' => 'float',
        'float_unsigned' => 'float',
        'char' => 'text',
        'longtext' => 'text',
        'mediumtext' => 'text',
        'tinytext' => 'text',
        'date' => 'date',
        'datetime' => 'date',
        'timestamp' => 'date',
        'time' => 'date',
        'year' => 'date',
        'text' => 'text',
        'json' => 'object', //text
        'point' => 'geo_point', //mysql经纬度字段对应es的一个字段
        //不常用
        'blob' => 'binary',
        'binary' => 'binary',
        'bit' => 'long',
        'real' => 'double',
        'geometry' => 'geo_shape',
        'linestring' => 'geo_shape',
        'polygon' => 'geo_shape',
        'multipoint' => 'geo_shape',
        'multilinestring' => 'geo_shape',
        'multipolygon' => 'geo_shape',
        'geometrycollection' => 'geo_shape'
    ];

    /**
     * @return array
     */
    public function getCastTypes(): array
    {
        return $this->castTypes;
    }

    /**
     * @return void
     */
    protected function initData(): void
    {
        $this->setOriginal([]);
        $this->setAttributes([]);
    }

    /**
     * @return array
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }

    /**
     * @return array
     */
    public function getOriginal(): array
    {
        return $this->original;
    }

    /**
     * @param array $original
     */
    public function setOriginal(array $original): void
    {
        $this->original = $original;
    }

    /**
     * @return array
     */
    public function getCasts(): array
    {
        return $this->casts;
    }

    /**
     * @param array $casts
     */
    public function setCasts(array $casts): void
    {
        $this->casts = $casts;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return $this->getAttributes();
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Convert the object to its JSON representation.
     */
    public function toJson(int $options = 0): string
    {
        return Json::encode($this->jsonSerialize(), $options);
    }

    /**
     * Convert the model to its string representation.
     */
    public function __toString(): string
    {
        return $this->toJson();
    }

}
