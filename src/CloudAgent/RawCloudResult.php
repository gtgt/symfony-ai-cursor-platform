<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent;

use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\RestClient;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\RunStreamReader;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\SseEvent;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Raw result wrapping a Cursor Cloud Agents run: SSE stream for incremental events,
 * REST fallback to GET /v1/agents/{id}/runs/{runId} when the stream has expired (HTTP 410).
 */
final class RawCloudResult implements RawResultInterface
{
    public function __construct(
        private readonly RestClient $api,
        private readonly RunStreamReader $streamReader,
        private readonly ResponseInterface $stream,
        private readonly string $agentId,
        private readonly string $runId,
    ) {
    }

    /**
     * Drains the SSE stream and returns the run's terminal state.
     *
     * Falls back to {@see RestClient::getRun()} when the stream has expired (HTTP 410).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        try {
            $collected = $this->streamReader->collectFinalText($this->stream);
        } catch (HttpExceptionInterface $e) {
            if (410 === $e->getResponse()->getStatusCode()) {
                return $this->fetchTerminalRun();
            }
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return [
            'text' => $collected['text'],
            'cursor_agent_id' => $this->agentId,
            'cursor_run_id' => $collected['runId'] ?? $this->runId,
            'status' => $collected['status'],
            'duration_ms' => $collected['durationMs'],
            'git' => $collected['git'],
        ];
    }

    /**
     * @return \Generator<int, SseEvent>
     */
    public function getDataStream(): \Generator
    {
        yield from $this->streamReader->events($this->stream);
    }

    public function getObject(): ResponseInterface
    {
        return $this->stream;
    }

    public function getAgentId(): string
    {
        return $this->agentId;
    }

    public function getRunId(): string
    {
        return $this->runId;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTerminalRun(): array
    {
        $run = $this->api->getRun($this->agentId, $this->runId);

        return [
            'text' => (string) ($run['result'] ?? ''),
            'cursor_agent_id' => $this->agentId,
            'cursor_run_id' => (string) ($run['id'] ?? $this->runId),
            'status' => $run['status'] ?? null,
            'duration_ms' => isset($run['durationMs']) ? (int) $run['durationMs'] : null,
            'git' => $run['git'] ?? null,
        ];
    }
}
