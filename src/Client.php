<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch;

use Hyperf\Elasticsearch\Exception\InvalidConfigException;
use Hyperf\Elasticsearch\Utils\HttpClient;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\ContainerInterface;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Client as EsClient;
use Hyperf\Logger\LoggerFactory;

/**
 * ES客户端
 */
class Client
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
     * @return EsClient
     * @throws \Elastic\Elasticsearch\Exception\AuthenticationException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function create(string $group = 'default'): EsClient
    {
        $config = $this->config[$group] ?? [];
        if (empty($config)) {
            throw new InvalidConfigException('elasticsearch config empty!');
        }

        $builder = ClientBuilder::create();
        $logger = $this->container->get(LoggerFactory::class)->get('elasticsearch', 'default');
        $httpClient = $this->container->get(HttpClient::class)->getHttpClient($group);

        if($config['enable_ssl']) {
            $builder->setBasicAuthentication($config['username'], $config['password'])
                ->setCABundle($config['https_cert_path']);
        }

        return $builder->setHosts($config['hosts'])
            ->setHttpClient($httpClient)
            ->setLogger($logger)
            ->build();
    }

}
