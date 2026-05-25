<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Collects assistant text deltas from a Cloud Agents run SSE stream.
 */
final class RunStreamReader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function collectAssistantText(ResponseInterface $response): string
    {
        $buffer = '';
        $text = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }
            $buffer .= $chunk->getContent();
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $rawEvent = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $text .= self::parseAssistantDelta($rawEvent);
            }
            if ($chunk->isLast()) {
                break;
            }
        }

        if ('' !== $buffer) {
            $text .= self::parseAssistantDelta($buffer);
        }

        return $text;
    }

    private static function parseAssistantDelta(string $rawEvent): string
    {
        $event = null;
        $dataLines = [];
        foreach (preg_split("/\r\n|\n|\r/", $rawEvent) as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, \strlen('event:')));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = trim(substr($line, \strlen('data:')));
            }
        }

        if ('assistant' !== $event || [] === $dataLines) {
            return '';
        }

        $json = json_decode(implode("\n", $dataLines), true);
        if (!\is_array($json)) {
            return '';
        }

        return (string) ($json['text'] ?? '');
    }
}
