<?php

declare(strict_types=1);

namespace DDD\Tests\Argus\Support;

use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecordingPlayedAnswerTranslatorInterface;

/** Replaces the recorded answer of a played call by a marked one and records which positions it translated. */
class PlayedAnswerMarkingTranslator implements ArgusTransportRecordingPlayedAnswerTranslatorInterface
{
    /** @var int[] The recorded positions whose answers it translated, in order */
    public array $translatedPositions = [];

    public function translatePlayedAnswerBody(string $responseBody, int $recordedPosition): string
    {
        $this->translatedPositions[] = $recordedPosition;
        return str_replace('"first"', '"translated"', $responseBody);
    }
}
