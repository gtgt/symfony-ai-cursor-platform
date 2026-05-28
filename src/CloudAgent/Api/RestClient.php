<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api;

use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Low-level HTTP client for Cursor Cloud Agents REST API.
 */
final class RestClient
{
    private const DEFAULT_BASE_URI = 'https://api.cursor.com/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly string $baseUri = self::DEFAULT_BASE_URI,
    ) {
        if ('' === $this->apiKey) {
            throw new InvalidArgumentException('Cursor Cloud Agents API key cannot be empty.');
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{agentId: string, runId: string}
     */
    public function createAgentRun(array $body): array
    {
        $response = $this->httpClient->request('POST', $this->endpoint('/v1/agents'), [
            'auth_basic' => [$this->apiKey, ''],
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $this->assertSuccess($response, [401 => AuthenticationException::class]);

        /** @var array{agent?: array{id?: string}, run?: array{id?: string}} $data */
        $data = $response->toArray(false);
        $agentId = $data['agent']['id'] ?? null;
        $runId = $data['run']['id'] ?? null;
        if (!\is_string($agentId) || !\is_string($runId)) {
            throw new RuntimeException('Unexpected Cursor API response: missing agent.id or run.id.');
        }

        return ['agentId' => $agentId, 'runId' => $runId];
    }

    public function openRunStream(string $agentId, string $runId): ResponseInterface
    {
        $url = $this->endpoint(\sprintf(
            '/v1/agents/%s/runs/%s/stream',
            rawurlencode($agentId),
            rawurlencode($runId),
        ));

        $response = $this->httpClient->request('GET', $url, [
            'auth_basic' => [$this->apiKey, ''],
            'headers' => [
                'Accept' => 'text/event-stream',
            ],
            'buffer' => false,
        ]);

        if (401 === $response->getStatusCode()) {
            throw new AuthenticationException($response->getContent(false));
        }

        return $response;
    }

    /**
     * Retrieves the terminal state of a run (used as a fallback when the SSE stream has expired).
     *
     * @return array<string, mixed>
     */
    public function getRun(string $agentId, string $runId): array
    {
        $url = $this->endpoint(\sprintf(
            '/v1/agents/%s/runs/%s',
            rawurlencode($agentId),
            rawurlencode($runId),
        ));

        $response = $this->httpClient->request('GET', $url, [
            'auth_basic' => [$this->apiKey, ''],
        ]);

        $this->assertSuccess($response, [401 => AuthenticationException::class]);

        /** @var array<string, mixed> $data */
        $data = $response->toArray(false);

        return $data;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUri, '/').$path;
    }

    /**
     * @param array<int, class-string<\Throwable>> $exceptionMap
     */
    private function assertSuccess(ResponseInterface $response, array $exceptionMap = []): void
    {
        $status = $response->getStatusCode();
        if ($status < 400) {
            return;
        }

        foreach ($exceptionMap as $code => $exceptionClass) {
            if ($status === $code) {
                throw new $exceptionClass($response->getContent(false));
            }
        }

        throw new BadRequestException($response->getContent(false));
    }
}
