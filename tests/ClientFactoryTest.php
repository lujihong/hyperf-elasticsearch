<?php
declare(strict_types=1);

namespace Hyperf\Elasticsearch;

use Hyperf\Elasticsearch\Client;
use Hyperf\Utils\ApplicationContext;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ClientFactoryTest extends TestCase
{
    public function testClientBuilderFactoryCreate()
    {
        $clientFactory = ApplicationContext::getContainer()->get(Client::class);

        $client = $clientFactory->create();

        $this->assertInstanceOf(\Elasticsearch\Client::class, $client);
    }
}
