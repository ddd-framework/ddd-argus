<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Tests\Argus\Support\ArgusConfigOverride;
use DDD\Tests\Argus\Support\ArgusProbeEntity;
use DDD\Tests\Argus\Support\TransportCapturingArgusApiOperationsWithDefaultBearer;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * An external call (`ArgusApiOperation::EXTERNAL_CALL`) to a foreign host carries its own headers only: of the
 * configured headers just Content-Type and Accept, no tracking header, no debug flags, no default bearer, no gateway
 * token. A normal call of the same batch keeps all of them.
 */
class ArgusExternalCallTest extends TestCase
{
    protected const string TOKEN_ENV_NAME = 'ARGUS_TEST_EXTERNAL_GATEWAY_TOKEN';

    protected const array ARGUS_CONFIG = [
        'microservices' => ['baseUrl' => 'https://services.example.test/'],
        'requestSettings' => ['headers' => ['x-api-key' => 'own-services-key', 'Accept' => 'application/json', 'Content-Type' => 'application/json']],
        'auth' => true,
        'appendDebugFlags' => true,
        'trackingHeader' => 'x-argus-tracking',
        'gateways' => [
            'google' => ['origin' => 'https://searchconsole.googleapis.test', 'tokenHeader' => 'X-Gateway-Token', 'tokenEnvName' => self::TOKEN_ENV_NAME],
        ],
    ];

    protected function setUp(): void
    {
        ArgusConfigOverride::apply(self::ARGUS_CONFIG);
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
        $_ENV[self::TOKEN_ENV_NAME] = 'gateway-secret';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::TOKEN_ENV_NAME]);
        ArgusConfigOverride::restore();
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
    }

    /** @return RequestInterface[] by URL without query */
    protected function sendAndCollect(array $operations): array
    {
        $argusApiOperations = new TransportCapturingArgusApiOperationsWithDefaultBearer(...array_map(static fn () => new Response(200, [], '{}'), $operations));
        foreach ($operations as [$id, $endpoint, $payload]) {
            $argusProbeEntity = new ArgusProbeEntity();
            $argusApiOperation = $argusProbeEntity->createOperation($id, $endpoint, $payload);
            $argusApiOperations->addOperation($argusApiOperation);
        }
        $argusApiOperations->execute();
        $sentRequests = [];
        foreach ($argusApiOperations->sentRequests as $sentRequestEntry) {
            $uri = $sentRequestEntry['request']->getUri();
            $sentRequests[$uri->getHost() . $uri->getPath()] = $sentRequestEntry['request'];
        }
        return $sentRequests;
    }

    public function testAnExternalCallCarriesNoneOfTheApplicationsHeadersFlagsOrTokens(): void
    {
        $sentRequests = $this->sendAndCollect([
            ['external', 'POST:https://searchconsole.googleapis.test/v1/query', ['body' => ['rowLimit' => 10], 'headers' => ['Authorization' => 'Bearer google-token'], ArgusApiOperation::EXTERNAL_CALL => true]],
            ['own', 'GET:/service/items', []],
        ]);

        $external = $sentRequests['searchconsole.googleapis.test/v1/query'];
        self::assertSame('Bearer google-token', $external->getHeaderLine('Authorization'));
        self::assertFalse($external->hasHeader('x-api-key'));
        self::assertFalse($external->hasHeader('x-argus-tracking'));
        self::assertFalse($external->hasHeader('X-Gateway-Token'));
        self::assertSame('application/json', $external->getHeaderLine('Accept'));
        self::assertSame('', $external->getUri()->getQuery(), 'no debug flags');

        $own = $sentRequests['services.example.test/service/items'];
        self::assertSame('own-services-key', $own->getHeaderLine('x-api-key'));
        self::assertTrue($own->hasHeader('x-argus-tracking'));
        self::assertSame('Bearer ' . TransportCapturingArgusApiOperationsWithDefaultBearer::DEFAULT_ACCOUNT_TOKEN, $own->getHeaderLine('Authorization'));
        self::assertStringContainsString('debug=1', $own->getUri()->getQuery());
    }

    public function testAnExternalCallWithoutItsOwnAuthorizationGetsNoDefaultBearer(): void
    {
        $sentRequests = $this->sendAndCollect([
            ['external', 'GET:https://searchconsole.googleapis.test/v1/sites', [ArgusApiOperation::EXTERNAL_CALL => true]],
        ]);

        self::assertFalse($sentRequests['searchconsole.googleapis.test/v1/sites']->hasHeader('Authorization'));
    }

    public function testAnExternalCallNeedsAnAbsoluteEndpointWithItsMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $argusProbeEntity = new ArgusProbeEntity();
        $argusProbeEntity->createOperation('relative', 'GET:/service/items', [ArgusApiOperation::EXTERNAL_CALL => true]);
    }
}
