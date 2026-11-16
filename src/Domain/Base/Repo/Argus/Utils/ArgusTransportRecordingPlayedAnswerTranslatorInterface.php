<?php

declare(strict_types=1);

namespace DDD\Domain\Base\Repo\Argus\Utils;

/**
 * Rewrites a recorded answer before an {@see ArgusTransportRecording} player hands it over — e.g. to translate the ids
 * the recording run created into the ids the replay created. Set on
 * {@see ArgusTransportRecording::$playedAnswerTranslator}; applied to the answers of calls made OUTSIDE a tool only.
 */
interface ArgusTransportRecordingPlayedAnswerTranslatorInterface
{
    /**
     * The answer body as the replay receives it.
     *
     * @param string $responseBody The recorded answer body
     * @param int $recordedPosition The recorded call's position in the recording
     * @return string
     */
    public function translatePlayedAnswerBody(string $responseBody, int $recordedPosition): string;
}
