<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Parses Server-Sent Events from a Cursor Cloud Agents run stream into structured {@see SseEvent} frames.
 */
final class RunStreamReader
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    /**
     * Streams SSE events as they arrive.
     *
     * @return \Generator<int, SseEvent>
     */
    public function events(ResponseInterface $response): \Generator
    {
        $buffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }
            $buffer .= $chunk->getContent();

            while (false !== ($pos = strpos($buffer, "\n\n"))) {
                $raw = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 2);
                $event = self::parseFrame($raw);
                if (null !== $event) {
                    yield $event;
                }
            }

            if ($chunk->isLast()) {
                break;
            }
        }

        if ('' !== trim($buffer)) {
            $event = self::parseFrame($buffer);
            if (null !== $event) {
                yield $event;
            }
        }
    }

    /**
     * Convenience for the non-streaming path: drains the SSE stream while aggregating
     * assistant text and capturing terminal-result metadata.
     *
     * @return array{
     *     text: string,
     *     runId: ?string,
     *     status: ?string,
     *     durationMs: ?int,
     *     git: ?array<string, mixed>,
     * }
     */
    public function collectFinalText(ResponseInterface $response): array
    {
        $text = '';
        $runId = null;
        $status = null;
        $durationMs = null;
        $git = null;

        foreach ($this->events($response) as $event) {
            switch ($event->event) {
                case 'assistant':
                    $text .= (string) ($event->data['text'] ?? '');
                    break;
                case 'status':
                    $status = (string) ($event->data['status'] ?? $status);
                    $runId = (string) ($event->data['runId'] ?? $runId);
                    break;
                case 'result':
                    $runId = (string) ($event->data['runId'] ?? $runId);
                    $status = (string) ($event->data['status'] ?? $status);
                    if (isset($event->data['text']) && \is_string($event->data['text'])) {
                        $text = $event->data['text'];
                    }
                    if (isset($event->data['durationMs'])) {
                        $durationMs = (int) $event->data['durationMs'];
                    }
                    if (isset($event->data['git']) && \is_array($event->data['git'])) {
                        $git = $event->data['git'];
                    }
                    break;
                case 'error':
                    throw new RuntimeException(\sprintf(
                        'Cursor Cloud Agents stream error [%s]: %s',
                        (string) ($event->data['code'] ?? 'unknown'),
                        (string) ($event->data['message'] ?? 'no message'),
                    ));
                case 'done':
                    break 2;
            }
        }

        return [
            'text' => $text,
            'runId' => $runId,
            'status' => $status,
            'durationMs' => $durationMs,
            'git' => $git,
        ];
    }

    private static function parseFrame(string $raw): ?SseEvent
    {
        $event = null;
        $id = null;
        $dataLines = [];

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if ('' === $line || ':' === $line[0]) {
                continue; // empty line or SSE comment
            }
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, 6));
            } elseif (str_starts_with($line, 'data:')) {
                $dataLines[] = ltrim(substr($line, 5), ' ');
            } elseif (str_starts_with($line, 'id:')) {
                $id = trim(substr($line, 3));
            }
        }

        if (null === $event || [] === $dataLines) {
            return null;
        }

        $payload = implode("\n", $dataLines);
        $decoded = json_decode($payload, true);

        return new SseEvent($event, \is_array($decoded) ? $decoded : [], $id);
    }
}
