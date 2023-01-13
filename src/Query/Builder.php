<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch\Query;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Elasticsearch\Exception\LogicException;
use Hyperf\Elasticsearch\Model\Model;
use Hyperf\Elasticsearch\Utils\Arr;
use Hyperf\Logger\LoggerFactory;
use Hyperf\Paginator\LengthAwarePaginator;
use Hyperf\Utils\ApplicationContext;
use Hyperf\Utils\Codec\Json;
use Hyperf\Utils\Collection;
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Hyperf\Utils\Str;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Psr\Container\ContainerInterface;
use Psr\SimpleCache\CacheInterface;

class Builder
{
    protected Client $client;
    protected LoggerInterface $logger;
    protected ContainerInterface $container;
    protected ConfigInterface $config;
    protected CacheInterface $cache;
    protected array $query;
    protected array $highlight = []; //高亮查询字段
    protected array $searchAfter = []; //searchAfter分页方式，上次最后一项sort数据
    protected array $sql;
    protected array $sort = [];
    protected Model $model;
    protected int $take = 0;
    protected array $operate = [
        '=', '>', '<', '>=', '<=', '!=', '<>', 'in', 'not_in',
        'between', 'not_between', 'should_match_phrase', 'not_match_phrase',
        'match_phrase', 'match', 'should_match', 'not_match', 'multi_match',
        'term', 'not_term', 'regex', 'prefix', 'not_prefix', 'wildcard',
        'not_exists', 'exists'
    ];

    public function __construct()
    {
        $this->container = ApplicationContext::getContainer();
        $this->config = $this->container->get(ConfigInterface::class);
        $this->cache = $this->container->get(CacheInterface::class);
        $this->logger = $this->container->get(LoggerFactory::class)->get('elasticsearch', 'default');
    }

    /**
     * 分页查询数据
     * @param int $page
     * @param int $size
     * @param array $fields
     * @param bool $deep 深度分页 searchAfter 方式，page为1会返回第一页
     * @return LengthAwarePaginator
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws \Psr\SimpleCache\InvalidArgumentException
     */
    public function page(int $page = 1, int $size = 50, array $fields = ['*'], bool $deep = false): LengthAwarePaginator
    {
        $from = 0;

        if ($deep) {
            if (empty($this->sort)) {
                throw new LogicException('page method deep attribute must be used in conjunction with orderBy, which needs to be used in conjunction with a set of sorted values from the previous page.', 400);
            }

            $cacheKey = $this->getCacheKey($size);
            $lastSorted = $this->cache->get($cacheKey);
            if ($lastSorted && $page > 1) {
                $this->searchAfter = unserialize($lastSorted, ['allowed_classes' => false]);
            }
        } else {
            $from = floor($page - 1) * $size;
        }

        if (empty($this->query)) {
            $this->sql = [
                'index' => $this->model->getIndex(),
                'version' => true,
                'seq_no_primary_term' => true,
                'from' => $from,
                'size' => $size,
                'body' => [
                    '_source' => [
                        'includes' => $fields
                    ],
                    'query' => [
                        'match_all' => new \stdClass()
                    ],
                    'search_after' => $this->searchAfter,
                    'highlight' => $this->highlight,
                    'sort' => $this->sort
                ]
            ];
        } else {
            $this->sql = [
                'index' => $this->model->getIndex(),
                'version' => true,
                'seq_no_primary_term' => true,
                'from' => $from,
                'size' => $size,
                'body' => [
                    '_source' => [
                        'includes' => $fields
                    ],
                    'query' => $this->query,
                    'search_after' => $this->searchAfter,
                    'highlight' => $this->highlight,
                    'sort' => $this->sort
                ]
            ];
        }

        $this->sql['body'] = array_filter($this->sql['body']);

        try {
            $result = $this->run('search', $this->sql);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw new LogicException($e->getMessage(), $e->getCode());
            }
        }
        $original = $result['hits']['hits'] ?? [];
        $total = $result['hits']['total']['value'] ?? 0;

        //after_search分页方式
        if ($deep) {
            $lastItem = end($original);
            $lastSorted = $lastItem['sort'] ?? [];
            if ($lastSorted) {
                $this->cache->set($cacheKey, serialize($lastSorted));
            }
        }

        $collection = Collection::make($original)->map(function ($value) use ($fields) {
            $attributes = $value['_source'] ?? [];
            if ($attributes) {
                if ($fields === ['*'] || in_array('id', $fields, true)) {
                    $attributes['id'] = is_numeric($value['_id']) ? (int)$value['_id'] : $value['_id'];
                }
            }
            $model = $this->model->newInstance();
            //处理高亮结果
            if (isset($value['highlight'])) {
                foreach ($value['highlight'] as $name => $val) {
                    if (Str::contains($name, '.')) {
                        $name = explode('.', $name)[0];
                    }
                    $attributes[$name] = $val[0];
                }
            }
            $model->setAttributes($attributes);
            $model->setOriginal($value);
            return $model;
        });

        return make(LengthAwarePaginator::class, ['items' => $collection, 'total' => $total, 'perPage' => $size, 'currentPage' => $page]);
    }

    /**
     * @param array $fields
     * @param int $size
     * @return Collection|null
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function get(array $fields = ['*'], int $size = 50): Collection|null
    {
        if (empty($this->query)) {
            $this->sql = [
                'index' => $this->model->getIndex(),
                'version' => true,
                'seq_no_primary_term' => true,
                'from' => 0,
                'size' => $this->take > 0 ? $this->take : $size,
                'body' => [
                    '_source' => [
                        'includes' => $fields
                    ],
                    'query' => [
                        'match_all' => new \stdClass()
                    ],
                    'highlight' => $this->highlight,
                    'sort' => $this->sort
                ]
            ];
        } else {
            $this->sql = [
                'index' => $this->model->getIndex(),
                'version' => true,
                'seq_no_primary_term' => true,
                'from' => 0,
                'size' => $this->take > 0 ? $this->take : $size,
                'body' => [
                    '_source' => [
                        'includes' => $fields
                    ],
                    'query' => $this->query,
                    'highlight' => $this->highlight,
                    'sort' => $this->sort
                ]
            ];
        }

        $this->sql['body'] = array_filter($this->sql['body']);
        try {
            $result = $this->run('search', $this->sql);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw new LogicException($e->getMessage(), $e->getCode());
        }
        $original = $result['hits']['hits'] ?? [];
        return Collection::make($original)->map(function ($value) use ($fields) {
            $attributes = $value['_source'] ?? [];
            if ($attributes) {
                if ($fields === ['*'] || in_array('id', $fields, true)) {
                    $attributes['id'] = is_numeric($value['_id']) ? (int)$value['_id'] : $value['_id'];
                }
            }
            $model = $this->model->newInstance();
            //处理高亮结果
            if (isset($value['highlight'])) {
                foreach ($value['highlight'] as $name => $val) {
                    if (Str::contains($name, '.')) {
                        $name = explode('.', $name)[0];
                    }
                    $attributes[$name] = $val[0];
                }
            }
            $model->setAttributes($attributes);
            $model->setOriginal($value);
            return $model;
        });
    }

    /**
     * @param array $fields
     * @return Model|null
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function first(array $fields = ['*']): Model|null
    {
        return $this->take(1)->get($fields)?->first();
    }

    /**
     * 查找单条文档
     * @param string|int $id
     * @return Model|null
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function find(string|int $id): Model|null
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'id' => $id
        ];
        try {
            $result = $this->run('get', $this->sql);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw new LogicException($e->getMessage(), $e->getCode());
        }
        $attributes = $result['_source'] ?? [];
        $id = $result['_id'] ?? 0;
        if ($attributes && $id) {
            $attributes['id'] = is_numeric($id) ? (int)$id : $id;
        }
        $this->model->setAttributes($attributes);
        $this->model->setOriginal($result);
        return $this->model;
    }

    /**
     * 匹配项数
     * @return int
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function count(): int
    {
        if (empty($this->query)) {
            $this->sql = [
                'index' => $this->model->getIndex(),
                'body' => [
                    'query' => [
                        'match_all' => new \stdClass()
                    ]
                ]
            ];
        } else {
            $this->sql = [
                'index' => $this->model->getIndex(),
                'body' => [
                    'query' => $this->query
                ]
            ];
        }
        $this->sql['body'] = array_filter($this->sql['body']);
        try {
            $result = $this->run('count', $this->sql);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw new LogicException($e->getMessage(), $e->getCode());
            }
        }
        return (int)($result['count'] ?? 0);
    }

    /**
     * 递减字段值
     * @param string $field
     * @param int $count
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function increment(string $field, int $count = 1): bool
    {
        $result = $this->updateByQueryScript("ctx._source.$field += params.count", [
            'count' => $count
        ]);
        return $result['updated'] > 0;
    }

    /**
     * 递减字段值
     * @param string $field
     * @param int $count
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function decrement(string $field, int $count = 1): bool
    {
        $result = $this->updateByQueryScript("ctx._source.$field -= params.count", [
            'count' => $count
        ]);
        return $result['updated'] > 0;
    }

    /**
     * 根据查询条件检查是否存在数据
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function exists(): bool
    {
        if (empty($this->query)) {
            throw new LogicException('Missing query criteria.');
        }

        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                'query' => $this->query
            ]
        ];

        $this->sql['body'] = array_filter($this->sql['body']);
        try {
            $result = $this->run('count', $this->sql);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw new LogicException($e->getMessage(), $e->getCode());
            }
        }
        return (bool)($result['count'] ?? 0);
    }

    /**
     * 按查询条件删除文档
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function delete(): bool
    {
        if (empty($this->query)) {
            throw new LogicException('Missing query criteria.');
        }

        $this->sql = [
            'index' => $this->model->getIndex(),
            'conflicts' => 'proceed', //如果按查询删除命中版本冲突，默认值为abort
            'refresh' => true, //Elasticsearch 会刷新 请求完成后通过查询删除
            'slices' => 5, //此任务应划分为的切片数。 默认值为 1，表示任务未切片为子任务
            'body' => [
                'query' => $this->query
            ]
        ];
        try {
            $result = $this->run('deleteByQuery', $this->sql);
        } catch (ClientResponseException|\Elastic\Elasticsearch\Exception\ClientResponseException $e) {
            if ($e->getCode() !== 404 && !Str::contains($e->getMessage(), 'but no document was found')) {
                throw new LogicException($e->getMessage(), $e->getCode());
            }
        }
        return isset($result['deleted']) && $result['deleted'] > 0;
    }

    /**
     * 按查询条件更新数据
     * @param array $value
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function update(array $value): bool
    {
        if (empty($this->query)) {
            throw new LogicException('Missing query criteria.', 400);
        }

        if (empty($value) || is_numeric(array_key_first($value))) {
            throw new LogicException('Data cannot be empty and can only be non-numeric subscripts.', 400);
        }

        $params = [];
        $script = '';
        foreach ($value as $field => $val) {
            $script = "ctx._source.$field = params.$field;" . $script;
            $params[$field] = $val;
        }

        try {
            $result = $this->updateByQueryScript($script, $params);
        } catch (ClientResponseException $e) {
            if ($e->getCode() !== 404) {
                throw new LogicException($e->getMessage(), $e->getCode());
            }
        }
        return isset($result['updated']) && $result['updated'] > 0;
    }

    /**
     * @param string $script
     * @param array $params
     * @return array|Elasticsearch
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function updateByQueryScript(string $script, array $params = []): Elasticsearch|array
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                "script" => [
                    "source" => $script,
                    'lang' => 'painless',
                    "params" => $params
                ],
                'query' => $this->query
            ]
        ];

        return $this->run('updateByQuery', $this->sql);
    }

    /**
     * 拿多少条数据
     * @param int $take
     * @return $this
     */
    public function take(int $take): Builder
    {
        $this->take = $take;
        return $this;
    }

    /**
     * 获取完整查询的唯一缓存键。
     * @param int $size
     * @return string
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function getCacheKey(int $size): string
    {
        return $this->config->get('cache.default.prefix') . ':' . $this->generateCacheKey($size);
    }

    /**
     * 为查询生成唯一的缓存key
     * @param int $size
     * @return string
     */
    public function generateCacheKey(int $size): string
    {
        $query = empty($this->query) ? ['match_all' => new \stdClass()] : $this->query;
        return md5(Json::encode($query) . $this->model->getIndex() . $size);
    }

    /**
     * 批量插入文档，注意如果包含id字段，多次执行相同数据插入则只会执行更新操作，而非新增数据
     * @param array $values 二维数组
     * @return Collection
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function insert(array $values): Collection
    {
        $body = [];
        foreach ($values as $value) {
            $index = array_filter([
                '_index' => $this->model->getIndex(),
                '_id' => $value['id'] ?? null
            ]);
            $body['body'][] = [
                'index' => $index
            ];
            $body['body'][] = $value;
        }
        $this->sql = $body;
        $result = $this->run('bulk', $this->sql);
        return collect($result['items'])->map(function ($value, $key) use ($values) {
            $items = Arr::mergeArray($values[$key], ['id' => $value['index']['_id'] ?? null]);
            $model = $this->model->newInstance();
            if ($value['index']['result'] === 'created' || $value['index']['result'] === 'updated') {
                $model->setAttributes($items);
                $model->setOriginal($value);
                return $model;
            }
            return false;
        });
    }

    /**
     * 创建文档
     * @param array $value 注意如果存在id字段，数据又是一样的多次调用只会执行更新操作
     * @return Model
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function create(array $value): Model
    {
        $body = Arr::except($value, ['routing', 'timestamp']);
        $except = Arr::only($value, ['id', 'routing', 'timestamp']);
        $this->sql = Arr::mergeArray($except, [
            'index' => $this->model->getIndex(),
            'body' => $body
        ]);

        try {
            $result = $this->run('index', $this->sql);
            if (!empty($result['result']) && $result['result'] === 'created') {
                $this->model->setOriginal((array)$result);
                $this->model->setAttributes(Arr::mergeArray($body, ['id' => $result['_id'] ?? '']));
            }
        } catch (ClientResponseException $e) {
            // manage the 4xx error
            $this->logger->error('Elasticsearch create operation, client response exception, ' . $e->getMessage() . ', index:' . $this->model->getIndex());
            if ($e->getCode() !== 404) {
                throw new LogicException($e->getMessage(), $e->getCode());
            }
        } catch (ServerResponseException $e) {
            // manage the 5xx error
            $this->logger->error('Elasticsearch create operation, server response exception, ' . $e->getMessage() . ', index:' . $this->model->getIndex());
            throw new LogicException($e->getMessage(), $e->getCode());
        } catch (\Exception $e) {
            // eg. network error like NoNodeAvailableException
            $this->logger->error('Elasticsearch create operation exception, ' . $e->getMessage() . ', index:' . $this->model->getIndex());
            throw new LogicException($e->getMessage(), $e->getCode());
        }
        return $this->model;
    }

    /**
     * 按id更新文档
     * @param array $value
     * @param string|int $id
     * @return int|string|false
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function updateById(array $value, string|int $id): int|string|false
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'id' => $id,
            'body' => [
                'doc' => $value,
            ]
        ];
        try {
            $result = $this->run('update', $this->sql);
            if (!empty($result['result']) && ($result['result'] === 'updated' || $result['result'] === 'noop')) {
                return $result['_id'] ?? false;
            }
        } catch (ClientResponseException $e) {
        }
        return false;
    }

    /**
     * 按id删除文档
     * @param string|int $id
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function deleteById(string|int $id): bool
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'id' => $id,
        ];
        try {
            $result = $this->run('delete', $this->sql);
        } catch (ClientResponseException $e) {
            if ($e->getCode() === 404) {
                return false;
            }
        }
        return !empty($result['result']) && $result['result'] === 'deleted';
    }

    /**
     * 更新映射
     * @param array $mappings
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function updateIndexMapping(array $mappings): bool
    {
        $mappings = collect($mappings)->map(function ($value, $key) {
            $valued = [];
            if (is_string($value)) {
                $valued['type'] = $value;
            }
            if (is_array($value)) {
                $valued = $value;
            }
            return $valued;
        })->toArray();

        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                'properties' => array_filter($mappings)
            ]
        ];

        $result = $this->run('indices.putMapping', $this->sql);
        return $result['acknowledged'] ?? false;
    }

    /**
     * 更新索引设置
     * @param array $settings
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function updateIndexSetting(array $settings): bool
    {
        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                'settings' => $settings
            ]
        ];
        $result = $this->run('indices.putSettings', $this->sql);
        return $result['acknowledged'] ?? false;
    }

    /**
     * 检查索引是否存在
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function existsIndex(): bool
    {
        $this->sql = ['index' => $this->model->getIndex()];
        /** @var Elasticsearch $result */
        $result = $this->run('indices.exists', $this->sql);
        return $result->getStatusCode() === 200;
    }

    /**
     * 创建索引
     * @param array $mappings
     * @param array $settings
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function createIndex(array $mappings = [], array $settings = []): bool
    {
        $mappings = Arr::mergeArray(
            Collection::make($this->model->getCasts())->map(function ($value, $key) {
                return $this->convertFieldType($key, $value);
            })->toArray(),
            Collection::make($mappings)->map(function ($value, $key) {
                return $this->convertFieldType($key, $value);
            })->toArray()
        );

        if ($this->existsIndex()) {
            return false; //索引已经存在
        }

        $this->sql = [
            'index' => $this->model->getIndex(),
            'body' => [
                'settings' => ['number_of_shards' => 3, ...$settings],
                'mappings' => [
                    '_source' => [
                        'enabled' => true
                    ],
                    'properties' => $mappings
                ]
            ]
        ];

        $this->sql['body'] = array_filter($this->sql['body']);
        try {
            $result = $this->run('indices.create', $this->sql);
        } catch (ClientResponseException $e) {
            return false;
        }
        return $result['acknowledged'] ?? false;
    }

    /**
     * 删除索引
     * @return bool
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function deleteIndex(): bool
    {
        $this->sql = [
            'index' => $this->model->getIndex()
        ];
        $result = $this->run('indices.delete', $this->sql);
        return $result['acknowledged'] ?? false;
    }

    /**
     * 条件查询
     * @param string $field
     * @param mixed $operate
     * @param mixed|null $value
     * @return $this
     */
    public function where(string $field, mixed $operate, mixed $value = null): Builder
    {
        if (is_null($value)) {
            $value = $operate;
            $operate = '=';
        }
        if (in_array($operate, $this->operate, true)) {
            $this->parseQuery($field, $operate, $value);
        } else {
            $this->logger->error('where query condition operate [' . $operate . '] illegally, Supported only [' . implode(',', $this->operate) . ']');
        }
        return $this;
    }

    /**
     * 字段存在索引
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-exists-query.html#exists-query-top-level-params
     * @param string $field
     * @return $this
     */
    public function whereExistsField(string $field): Builder
    {
        return $this->where($field, 'exists', '');
    }

    /**
     * 字段不存在索引
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-exists-query.html#exists-query-top-level-params
     * @param string $field
     * @return $this
     */
    public function whereNotExistsField(string $field): Builder
    {
        return $this->where($field, 'not_exists', '');
    }

    /**
     * 多个确切的条件满足
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-terms-query.html
     * @param string $field
     * @param array $value
     * @return $this
     */
    public function whereIn(string $field, array $value): Builder
    {
        return $this->where($field, 'in', $value);
    }

    /**
     * 其中不能有多个确切的条件满足
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-terms-query.html
     * @param string $field
     * @param array $value
     * @return $this
     */
    public function whereNotIn(string $field, array $value): Builder
    {
        return $this->where($field, 'not_in', $value);
    }

    /**
     * 不得与短语匹配
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-match-query-phrase.html
     * @param string $field
     * @param mixed $value
     * @param int $slop
     * @return $this
     */
    public function whereNotMatchPhrase(string $field, mixed $value, int $slop = 100): Builder
    {
        return $this->parseQuery($field, 'not_match_phrase', $value, ['slop' => $slop]);
    }

    /**
     * 或匹配的短语查询
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-match-query-phrase.html
     * @param string $field
     * @param mixed $value
     * @param int $slop
     * @return $this
     */
    public function whereShouldMatchPhrase(string $field, mixed $value, int $slop = 100): Builder
    {
        return $this->parseQuery($field, 'should_match_phrase', $value, ['slop' => $slop]);
    }

    /**
     * 其中必须匹配短语
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-match-query-phrase.html
     * @param string $field
     * @param mixed $value
     * @param int $slop
     * @return $this
     */
    public function whereMatchPhrase(string $field, mixed $value, int $slop = 100): Builder
    {
        return $this->parseQuery($field, 'match_phrase', $value, ['slop' => $slop]);
    }

    /**
     * 范围查询
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-range-query.html
     * @param string $field
     * @param array $value
     * @return $this
     */
    public function whereBetween(string $field, array $value): Builder
    {
        return $this->parseQuery($field, 'between', $value);
    }

    /**
     * 不包含范围
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-range-query.html
     * @param string $field
     * @param array $value
     * @return $this
     */
    public function whereNotBetween(string $field, array $value): Builder
    {
        return $this->parseQuery($field, 'not_between', $value);
    }

    /**
     * 查询指定前缀
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-prefix-query.html
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function wherePrefix(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'prefix', $value);
    }

    /**
     * 不能是指定前缀
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-prefix-query.html
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function whereNotPrefix(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'not_prefix', $value);
    }

    /**
     * 通配符*号匹配
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-wildcard-query.html
     * @param string $field
     * @param string $value
     * @return $this
     */
    public function whereWildcard(string $field, string $value): Builder
    {
        return $this->parseQuery($field, 'wildcard', $value);
    }

    /**
     * 在提供的字段中包含确切术语的文档，等同于某个字段必须要等于
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-term-query.html
     * @param string $field 如果为text则需要以：field.raw 格式
     * @param mixed $value
     * @return $this
     */
    public function whereTerm(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'term', $value);
    }

    /**
     * 在提供的字段中不包含确切术语的文档，等同于某个字段必须要等于
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-term-query.html
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function whereNotTerm(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'not_term', $value);
    }

    /**
     * 必须匹配
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-match-query.html
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function whereMatch(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'match', $value);
    }

    /**
     * 多字段匹配查询
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-multi-match-query.html
     * @param array $fields
     * @param mixed $value
     * @return $this
     */
    public function whereMultiMatch(array $fields, mixed $value): Builder
    {
        return $this->parseQuery($fields, 'multi_match', $value);
    }

    /**
     * 应该匹配
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-match-query.html
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function whereShouldMatch(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'should_match', $value);
    }

    /**
     * 不能匹配
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-match-query.html
     * @param string $field
     * @param mixed $value
     * @return $this
     */
    public function whereNotMatch(string $field, mixed $value): Builder
    {
        return $this->parseQuery($field, 'not_match', $value);
    }

    /**
     * 地理距离查询，过滤在一定距离范围
     * https://www.elastic.co/guide/en/elasticsearch/reference/8.5/query-dsl-geo-distance-query.html
     * @param string $field
     * @param float $longitude
     * @param float $latitude
     * @param string $distance
     * @return $this
     */
    public function whereFilterDistance(string $field, float $longitude, float $latitude, string $distance = '50km'): Builder
    {
        $this->query['bool']['filter'][] = [
            'geo_distance' => [
                'distance' => $distance,//附近 km 范围内
                $field => [
                    'lat' => $latitude,
                    'lon' => $longitude
                ]
            ]
        ];
        return $this;
    }

    /**
     * parseWhere
     * @param string|array $field
     * @param string $operate
     * @param mixed $value
     * @param array $options
     * @return $this
     */
    protected function parseQuery(string|array $field, string $operate, mixed $value, array $options = []): Builder
    {
        switch ($operate) {
            //必须匹配，match用于执行全文查询（包括模糊匹配）的标准查询 以及短语或邻近查询。
            case 'match':
                $type = 'must';
                $result = ['match' => [$field => $value]];
                break;
            //应该匹配
            case 'should_match':
                $type = 'should';
                $result = ['match' => [$field => $value]];
                break;
            //不匹配
            case 'not_match':
                $type = 'must_not';
                $result = ['match' => [$field => $value]];
                break;
            //多字段匹配
            case 'multi_match':
                $type = 'must';
                $result = ['multi_match' => ['query' => $value, 'fields' => $field]];
                break;
            //必须等于 返回在提供的字段中包含确切术语的文档。您可以使用查询根据精确值查找文档，例如 价格、产品 ID 或用户名。
            case '=':
            case 'term':
                $type = 'must';
                $result = ['term' => [$field => $value]];
                break;
            //必须不等于 返回在提供的字段中包含确切术语的文档。您可以使用查询根据精确值查找文档，例如 价格、产品 ID 或用户名。
            case '<>':
            case '!=':
            case 'not_term':
                $type = 'must_not';
                $result = ['term' => [$field => $value]];
                break;
            //必须等于短语匹配，必须出现在匹配的文档中，与match查询类似，但用于匹配确切的短语或单词邻近匹配
            case 'match_phrase':
                $type = 'must';
                $result = ['match_phrase' => [$field => array_merge(['query' => $value, 'slop' => 100], $options)]];
                break;
            //不等于匹配确切的短语或单词邻近匹配
            case 'not_match_phrase':
                $type = 'must_not';
                $result = ['match_phrase' => [$field => array_merge(['query' => $value, 'slop' => 100], $options)]];
                break;
            //短语匹配应出现在匹配文档
            case 'should_match_phrase':
                $type = 'should';
                $result = ['match_phrase' => [$field => array_merge(['query' => $value, 'slop' => 100], $options)]];
                break;
            //大于
            case '>':
                $type = 'must';
                $result = ['range' => [$field => ['gt' => $value]]];
                break;
            //小于
            case '<':
                $type = 'must';
                $result = ['range' => [$field => ['lt' => $value]]];
                break;
            //大于等于
            case '>=':
                $type = 'must';
                $result = ['range' => [$field => ['gte' => $value]]];
                break;
            //小于等于
            case '<=':
                $type = 'must';
                $result = ['range' => [$field => ['lte' => $value]]];
                break;
            //范围
            case 'between':
                if (!isset($value[0], $value[1])) {
                    throw new LogicException('The between query value should contain start and end.', 400);
                }
                $type = 'must';
                $result = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];
                break;
            //不包含范围
            case 'not_between':
                if (!isset($value[0], $value[1])) {
                    throw new LogicException('The not_between query value should contain start and end.', 400);
                }
                $type = 'must_not';
                $result = ['range' => [$field => ['gte' => $value[0], 'lte' => $value[1]]]];
                break;
            //类似whereIn
            case 'in':
                $type = 'must';
                $result = ['terms' => [$field => $value]];
                break;
            //类似whereNotIn
            case 'not_in':
                $type = 'must_not';
                $result = ['terms' => [$field => $value]];
                break;
            //正则匹配
            case 'regex':
                $type = 'must';
                $result = ['regexp' => [$field => $value]];
                break;
            //前缀匹配
            case 'prefix':
                $type = 'must';
                $result = ['prefix' => [$field => $value]];
                break;
            //不匹配的前缀
            case 'not_prefix':
                $type = 'must_not';
                $result = ['prefix' => [$field => $value]];
                break;
            //通配符
            case 'wildcard':
                $type = 'must';
                $result = ['wildcard' => [$field => $value]];
                break;
            case 'exists':
                $type = 'must';
                $result = ['exists' => ['field' => $field]];
                break;
            case 'not_exists':
                $type = 'must_not';
                $result = ['exists' => ['field' => $field]];
                break;
        }
        if (isset($type, $result)) {
            $this->query['bool'][$type][] = $result;
        }
        return $this;
    }

    /**
     * 统一执行ES入口
     * @param $method
     * @param ...$parameters
     * @return Elasticsearch|array
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function run($method, ...$parameters): Elasticsearch|array
    {
        $client = $this->client;
        $sql = $this->sql;

        if (strpos($method, '.')) {
            $methods = explode('.', $method);
            $method = $methods[1];
            $client = $client->{$methods[0]}();
        }

        $this->logger->alert(Json::encode(compact('method', 'parameters', 'sql')));

        /** @var Elasticsearch $response */
        $response = call([$client, $method], $parameters);
        if ($response->getBody()->getSize() > 0) {
            return $response->asArray();
        }
        return $response;
    }

    /**
     * 搜索结果高亮显示
     * @param array $fields
     * @param array $preTags
     * @param array $postTag
     * @return $this
     */
    public function selectHighlight(array $fields, array $preTags = ["<em>"], array $postTag = ["</em>"]): Builder
    {
        if (empty($fields)) {
            return $this;
        }

        $fields = Collection::make($fields)
            ->map(function ($item) {
                return [
                    $item => new \stdClass()
                ];
            })->toArray();

        $this->highlight = [
            "pre_tags" => $preTags,
            "post_tags" => $postTag,
            'fields' => $fields
        ];
        return $this;
    }

    /**
     * 排序
     * @param string $field 对于text类型的字段可以用：field.raw 方式
     * @param string $direction
     * @param string $mode
     * min 选择最低值。
     * max 选择最高值。
     * sum 使用所有值的总和作为排序值。仅适用于 基于数字的数组字段。
     * avg 使用所有值的平均值作为排序值。仅适用于 对于基于数字的数组字段。
     * median 使用所有值的中位数作为排序值。仅适用于 对于基于数字的数组字段。
     * @return $this
     */
    public function orderBy(string $field, string $direction = 'asc', string $mode = 'min'): Builder
    {
        $this->sort[] = [$field => [
            'order' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
            'mode' => $mode
        ]];
        return $this;
    }

    /**
     * 按用户当前经纬度距离排序
     * @param string $field
     * @param float $longitude
     * @param float $latitude
     * @param string $direction
     * @param string $unit m 或 km
     * @param string $mode
     * min 选择最低值。
     * max 选择最高值。
     * sum 使用所有值的总和作为排序值。仅适用于 基于数字的数组字段。
     * avg 使用所有值的平均值作为排序值。仅适用于 对于基于数字的数组字段。
     * median 使用所有值的中位数作为排序值。仅适用于 对于基于数字的数组字段。
     * @return $this
     */
    public function orderByDistance(string $field, float $longitude, float $latitude, string $direction = 'asc', string $unit = 'km', string $mode = 'min'): Builder
    {
        $this->sort[] = [
            '_geo_distance' => [
                $field => [
                    $latitude, //纬度
                    $longitude //经度
                ],
                'order' => strtolower($direction) === 'asc' ? 'asc' : 'desc',
                'unit' => $unit,
                'mode' => $mode,
                'distance_type' => 'arc',
                'ignore_unmapped' => true //未映射字段导致搜索失败
            ]
        ];
        return $this;
    }

    /**
     * 转换字段类型
     * @param string $key
     * @param string|array $value
     * @return array
     */
    protected function convertFieldType(string $key, string|array $value): array
    {
        $valued = [];
        $types = $this->model->getCastTypes();//映射后的字段类型
        if (is_string($value)) {
            $type = $types[$value];
            $valued['type'] = $type;

            //文本类型，做中文分词处理
            if ($type === 'text') {
                $valued['analyzer'] = 'ik_max_word';
                $valued['search_analyzer'] = 'ik_smart';
                $valued['fields'] = [
                    'raw' => [
                        'type' => 'keyword'
                    ],
                    'keyword' => [
                        'type' => 'text',
                        'analyzer' => 'keyword'
                    ],
                    'english' => [
                        'type' => 'text',
                        'analyzer' => 'english'
                    ],
                    'standard' => [
                        'type' => 'text',
                        'analyzer' => 'standard'
                    ],
                    'smart' => [
                        'type' => 'text',
                        'analyzer' => 'ik_smart'
                    ]
                ];
            }

            //日期格式
            if ($type === 'date') {
                $valued['format'] = 'yyyy-MM-dd HH:mm:ss||yyyy-MM-dd||yyyy/MM/dd HH:mm:ss||yyyy/MM/dd||epoch_millis||epoch_second';
            }
            return $valued;
        }

        if (is_array($value)) {
            $valued = $value;
        }

        return $valued;
    }

    /**
     * 设置初始化模型
     * @param Model $model
     * @return $this
     */
    public function setModel(Model $model): Builder
    {
        $this->model = $model;
        $this->client = $model->getClient();
        $this->highlight = [];
        $this->sort = [];
        return $this;
    }
}
