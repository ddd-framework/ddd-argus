<?php

declare(strict_types=1);

namespace DDD\Tests\Argus;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Tests\Argus\Support\ArgusConfigOverride;
use DDD\Tests\Argus\Support\ArgusProbeEntity;
use DDD\Tests\Argus\Support\TransportCapturingArgusApiOperations;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Two entities of one batch asking the same question (the same operation id): the call is sent once and both receive
 * its answer. The same entity added twice is one operation.
 */
class ArgusDuplicateQuestionTest extends TestCase
{
    protected function setUp(): void
    {
        ArgusConfigOverride::apply(['microservices' => ['baseUrl' => 'https://services.example.test/'], 'auth' => false, 'appendDebugFlags' => false, 'trackingHeader' => '']);
        ArgusApiOperations::resetForNewJob();
        ArgusLoad::$logArgusCalls = false;
    }

    protected function tearDown(): void
    {
        ArgusConfigOverride::restore();
        ArgusApiOperations::resetForNewJob();
    }

    public function testASecondEntityWithTheSameQuestionReceivesTheAnswerOfTheOneCall(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{"clicks":5}'));
        $firstEntity = new ArgusProbeEntity();
        $secondEntity = new ArgusProbeEntity();
        $firstOperation = $firstEntity->createOperation('same-question', 'GET:/service/totals');
        $secondOperation = $secondEntity->createOperation('same-question', 'GET:/service/totals');
        $argusApiOperations->addOperation($firstOperation);
        $argusApiOperations->addOperation($secondOperation);
        $argusApiOperations->addOperation($firstOperation);

        $argusApiOperations->execute();

        self::assertCount(1, $argusApiOperations->sentRequests);
        self::assertTrue($firstEntity->responseHandled);
        self::assertTrue($secondEntity->responseHandled, 'before: dropped and never loaded');
        self::assertEquals($firstEntity->receivedResults, $secondEntity->receivedResults);
        self::assertCount(1, $argusApiOperations->duplicateOperationsById['same-question'], 'the same entity twice is one operation');
    }

    public function testEachCallGetsTheTimeoutOfItsOwnOperationElseTheBatchs(): void
    {
        $argusApiOperations = new TransportCapturingArgusApiOperations(new Response(200, [], '{}'), new Response(200, [], '{}'));
        $ownTimeoutEntity = new ArgusProbeEntity();
        $batchTimeoutEntity = new ArgusProbeEntity();
        $ownTimeoutOperation = $ownTimeoutEntity->createOperation('own', 'GET:/service/slow', [], 25.0);
        $batchTimeoutOperation = $batchTimeoutEntity->createOperation('batch', 'GET:/service/fast');
        $argusApiOperations->addOperation($ownTimeoutOperation);
        $argusApiOperations->addOperation($batchTimeoutOperation);

        $argusApiOperations->execute(timeout: 4.0);

        $timeoutsByPath = [];
        foreach ($argusApiOperations->sentRequests as $sentRequestEntry) {
            $timeoutsByPath[$sentRequestEntry['request']->getUri()->getPath()] = $sentRequestEntry['options']['timeout'] ?? null;
        }
        self::assertEquals(25.0, $timeoutsByPath['/service/slow']);
        self::assertEquals(4.0, $timeoutsByPath['/service/fast']);
    }
}
