# Changelog

## 1.6.0

- `ArgusTransportRecording::$playedAnswerTranslator` takes an `ArgusTransportRecordingPlayedAnswerTranslatorInterface`
  (method `translatePlayedAnswerBody(string $responseBody, int $recordedPosition): string`). Applied as before: to the
  recorded answers of calls made outside a tool, before the id counterparts. A Closure
  `(answer body, recorded position) → body` is still accepted and deprecated; it is removed in the next major version.

## 1.5.2

- `ArgusCache::get()` returns a Redis hit as it is. Before, it re-wrote the hit without the entry's lifetime: into APCu
  at `CACHELEVEL_MEMORY_AND_DB`, into Redis itself at `CACHELEVEL_DB` — so an entry read through
  `ArgusCacheInFlightComputation::hasCacheEntry()` was served or kept far beyond its TTL.

## 1.5.1

- **A call's own timeout applies:** the timeout of a call's Argus class (`ArgusLoad::$timeout`) reaches its HTTP call;
  the operation's constructor dropped it, so every call of a batch ran with the root load's timeout. A merged call
  takes the longest timeout of its operations; a call without one keeps the batch's.
- **Redis hits enter the memory cache only for memory levels:** a Redis hit is copied into APCu only for an entity of
  `CACHELEVEL_MEMORY_AND_DB`, with that entity's TTL. Before, every hit was copied with the cache group's default
  lifetime: `CACHELEVEL_DB` entries entered APCu, and an entry was served from memory far beyond its TTL.
- **Duplicate cache lookups:** one lookup per cache key (the duplicate check read an undefined property); another
  entity with the same key receives the same cached answer (before, the later operation replaced the earlier one and
  the earlier entity never got its answer).

## 1.5.0

- **External calls:** `ArgusApiOperation::EXTERNAL_CALL => true` in a load payload sends a call to a foreign host
  with its own headers only — of the configured `requestSettings.headers` just `Content-Type` and `Accept` — and
  without the tracking header, the debug flags, the default account's bearer token and a gateway token. The endpoint
  carries its method and an absolute `http(s)` URL; such a call cannot be merged. Combines with the private transport.
- **The same question twice:** a second entity of one batch whose operation has the id of one already added receives
  the answer of the one call; before, it was dropped silently and stayed unloaded. The same entity added twice stays
  one operation.
- The bearer token of the default account is read through `static::` (a subclass can replace it).

## 1.4.0

- **Gateway tokens:** `Base.Repo.argus.gateways` names gateways by `origin`, `tokenHeader` and `tokenEnvName`. The
  token header goes only to calls whose final URL has that origin (scheme, host, port), never to a third-party URL;
  a call's own header wins. Calls to a gateway origin follow redirects only within the origin and write no debug
  output. An empty token variable sends nothing and is logged once per process by name. An invalid entry rejects the
  batch before any request is sent. Without `gateways` nothing changes.

## 1.3.3

- The repository moved to the ddd-framework organisation: github.com/ddd-framework. Links updated; no code changes.

## 1.3.2

- Fix: `rejectionReasonForNon2xxAnswer()` failed with "Object of class stdClass could not be converted to string" for
  an error body of the shape `{"error": {"message": …}}` (OpenRouter's); it now reads the nested message.

## 1.3.1

- Fix: since 1.3.0 passes the TTL to the cache, the empty entry of a failed load (`postProcessLoadResponse($data, false)`)
  lived as long as a successful answer — a failed geocode stuck for the day or month the class caches. An empty entry
  now lives at most `ArgusCache::FAILED_LOAD_CACHE_TTL_MAX` (one hour, the effective lifetime before 1.3.0). Transport
  failures that must not be cached at all still pass `cacheFailure: false`.
- Note for installations without a Redis Sentinel cache group: that group falls back to APCu, so since 1.3.0 a
  successful answer stays in APCu for the class's full `cacheTtl` (a month for `CACHE_TTL_ONE_MONTH`), per host.

## 1.3.0

Requires `mgamadeus/ddd` ^3.14.3.

- **Configuration first:** `Base.Repo.argus` (`microservices.baseUrl`, `requestSettings`, `auth`, `appendDebugFlags`,
  `trackingHeader`) in the application config; `ARGUS_API_ENDPOINT` / `ARGUS_REQUEST_SETTINGS` / `APP_DEBUG` only where
  the config has no value. Defaults keep the 1.2 behaviour.
- **Header order changed:** a call's own headers now win over the configured headers (1.2: configured headers
  overwrote them), and the default account's bearer token is added only to calls without their own `Authorization`.
- **Endpoints without request method are rejected:** an endpoint without `GET:` / `POST:` / … prefix throws an
  `InvalidArgumentException` before any request of the batch is sent (1.2 dropped such a call silently).
- **Timeouts:** `#[ArgusLoad(timeout:)]` reaches the HTTP client (1.2 ignored it); without it the class constant
  `DEFAULT_ARGUS_TIMEOUT` applies. Every instance works on its own copy of the attribute.
- **Cache:** `ArgusCache::set()` passes the TTL to both levels (1.2 used the cache group's default lifetime); cache
  writes substitute invalid UTF-8 and log a failed encoding; `CACHE_TTL_FIFTEEN_MINUTES`; typed constants.
- **Failures:** settle mode (`ArgusSettings::$keepFulfilledCallsWhenOneIsRejected`); per-operation
  `responseStatusCode`, `responseHeaders`, `rejectionReason`; `rejectionReasonForNon2xxAnswer()` and
  `postProcessLoadResponse(cacheFailure: false)` keep a failed answer out of the cache.
- **Response envelopes:** `getResponseDataFromArgusResponse()` also reads `status: result`, OpenAI Responses and Chat
  Completions payloads.
- **Private transport** (`ArgusApiOperation::PRIVATE_TRANSPORT`): no redirects, bounded streamed read, never logged.
- **Split execution:** `sendArgusLoadRequests()` / `advanceArgusLoadRequests()` / `advanceArgusLoadRequestsUntilWritten()`
  / `receiveArgusLoadResponses()` (`ArgusApiCallsInFlight`, `ArgusApiCallRequestWriteProgress`).
- **Call journal:** `ArgusApiOperations::$journalRequests`, `getCallJournal()` (`ArgusSentCallJournalEntries`),
  `getSentCallCountsByEndpoint()`.
- **`ArgusCacheInFlightComputation`:** a second reader joins a running computation of one cached entity.
- **Recorded runs:** `ArgusTransportRecording` (record / play / play tool calls / play with live tools; typed entries,
  stored format `toStorageArray()`), `RecordedRunIdCounterparts`; optional entity method `getArgusCallKind()`.
- **Low-speed abort:** optional entity method `buildTransferStallCurlOptions()`.
- **Worker reset:** `ArgusWorkerStateResetter` clears the executor statics, `ArgusLoad::$logArgusCalls` and the
  per-class `setPropertiesToLoadAlways()` selections before every message a Messenger worker consumes.
- Tests (`vendor/bin/phpunit`).
