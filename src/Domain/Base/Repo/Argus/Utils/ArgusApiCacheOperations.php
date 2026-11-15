<?php

declare (strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

/**
 * Multiple ArgusCache Calls are combined into a single Call e.g. to Redis cluster
 * and by this latency is reduced.
 * All ArgusCache Calls are encapsuled into Atomic ArgusCache operations which are then sent at once
 *
 */
class ArgusApiCacheOperations
{
    /** @var ArgusApiCacheOperation[]|null */
    public ?array $operations;

    /** @var ArgusApiCacheOperation[]|null */
    public ?array $operationsByUniqueKey;

    /** @var array<string, ArgusApiCacheOperation[]> Further operations of other entities with a key already looked up */
    public array $duplicateOperationsByUniqueKey = [];

    public function __construct()
    {
        $this->operations = [];
        $this->operationsByUniqueKey = [];
    }

    /**
     * @param ArgusApiCacheOperation $operation
     */
    public function addOperation(ArgusApiCacheOperation &$operation)
    {
        $uniqueKey = $operation->uniqueKey();
        if (isset($this->operationsByUniqueKey[$uniqueKey])) {
            // one lookup per key; another entity with the same key receives the same cached answer (before, a later
            // operation replaced the earlier one and the earlier entity never received its cached answer)
            if ($this->operationsByUniqueKey[$uniqueKey]->entity !== $operation->entity) {
                $this->duplicateOperationsByUniqueKey[$uniqueKey][] = $operation;
            }
            return;
        }
        $this->operationsByUniqueKey[$uniqueKey] = $operation;
        $this->operations[] = $operation;
    }

    /**
     * Loads all cache operation at once and sets results to Argus Objects
     * @return void
     */
    public function execute(): void
    {
        if (!count($this->operations)) {
            return;
        }
        $keys = [];
        $memoryCopyTtlsByKey = [];
        foreach ($this->operationsByUniqueKey as $key => $cacheOperation) {
            $keys[] = $key;
            $argusSettings = $cacheOperation->entity?->getArgusSettings();
            if ($argusSettings !== null && $argusSettings->getCacheLevel() === ArgusCache::CACHELEVEL_MEMORY_AND_DB) {
                $memoryCopyTtlsByKey[$key] = $argusSettings->getCacheTTL();
            }
        }

        $multiResult = ArgusCache::getMulti($keys, $memoryCopyTtlsByKey);
        if (!$multiResult) {
            return;
        }
        foreach ($multiResult as $key => $result) {
            if (isset($this->operationsByUniqueKey[$key])) {
                foreach ([$this->operationsByUniqueKey[$key], ...($this->duplicateOperationsByUniqueKey[$key] ?? [])] as $cacheOperation) {
                    $cachedResult = $result;
                    $cacheOperation->handleResponse($cachedResult);
                }
            }
        }
    }
}
