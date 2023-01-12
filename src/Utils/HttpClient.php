<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch\Utils;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ContainerInterface;
use Hyperf\Guzzle\PoolHandler;
use Swoole\Coroutine;

/**
 * Author lujihong
 * Description
 */
class HttpClient
{
    protected ContainerInterface $container;
    protected array $config;

    /**
     * @param ContainerInterface $container
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->config = $container->get(ConfigInterface::class)->get('elasticsearch', []);
    }

    /**
     * @param string $group
     * @return GuzzleClient
     */
    public function getHttpClient(string $group = 'default'): GuzzleClient
    {
        $handler = null;
        $config = $this->config[$group] ?? [];

        if (Coroutine::getCid() > 0) {
            $handler = make(PoolHandler::class, [
                'option' => [
                    'max_connections' => $config['max_connections'] ?? 50,
                    'timeout' => $config['timeout'] ?? 0,
                ]
            ]);
        }

        $stack = HandlerStack::create($handler);
        return make(GuzzleClient::class, [
            'config' => [
                'handler' => $stack,
            ]
        ]);
    }
}