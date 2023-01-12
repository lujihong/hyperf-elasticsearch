<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch\Model;

use Hyperf\Elasticsearch\Query\Builder;
use Hyperf\Elasticsearch\Client;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Collection;
use Hyperf\Utils\Contracts\Arrayable;
use Hyperf\Utils\Contracts\Jsonable;
use Elastic\Elasticsearch\Client as EsClient;
use JsonSerializable;

abstract class Model implements Arrayable, Jsonable, JsonSerializable
{
    use HasAttributes;

    protected string $index; //索引
    protected Client $client;
    protected string $connection = 'default';

    public function __construct()
    {
        $this->client = ApplicationContext::getContainer()->get(Client::class);
    }

    /**
     * @return Builder
     */
    public static function query(): Builder
    {
        return (new static())->newQuery();
    }

    /**
     * @return Builder
     */
    public function newQuery(): Builder
    {
        return $this->newModelBuilder()->setModel($this);
    }

    /**
     * @return EsClient
     */
    public function getClient(): EsClient
    {
        return $this->client->create($this->connection);
    }

    /**
     * Create a new Model Collection instance.
     * @param array $models
     * @return Collection
     */
    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }

    /**
     * @return $this
     */
    public function newInstance(): self
    {
        return new static();
    }

    /**
     * Create a new Model query builder
     * @return Builder
     */
    public function newModelBuilder(): Builder
    {
        return new Builder();
    }

    /**
     * @return string
     */
    public function getIndex(): string
    {
        return $this->index;
    }

    /**
     * @param string $index
     */
    public function setIndex(string $index): void
    {
        $this->index = $index;
    }

    /**
     * Handle dynamic method calls into the model.
     * @param string $method
     * @param array $parameters
     * @return mixed|null
     */
    public function __call(string $method, array $parameters)
    {
        return call([$this->newQuery(), $method], $parameters);
    }

    /**
     * Handle dynamic static method calls into the method.
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return (new static())->{$method}(...$parameters);
    }
}
