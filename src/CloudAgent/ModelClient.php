<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent;

use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\RestClient;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\RunStreamReader;
use Symfony\AI\Platform\Bridge\Cursor\MessagePayload;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Maps each Platform invocation to Cursor Cloud Agents: POST /v1/agents, then SSE on the run stream.
 *
 * Each invoke creates a new agent (stateless). Reuse agent ids across turns via {@code cursor_agent_id}
 * in a custom extension of this client if needed.
 */
final class ModelClient implements ModelClientInterface
{
    /**
     * @param list<array{url: string, startingRef?: string|null, prUrl?: string|null}> $defaultRepositories
     */
    public function __construct(
        private readonly RestClient $api,
        private readonly RunStreamReader $streamReader,
        private readonly array $defaultRepositories = [],
    ) {
    }

    public static function fromHttpClient(
        HttpClientInterface $httpClient,
        #[\SensitiveParameter] string $apiKey,
        string $baseUri = 'https://api.cursor.com/',
        array $defaultRepositories = [],
    ): self {
        return new self(
            new RestClient($httpClient, $apiKey, $baseUri),
            new RunStreamReader($httpClient),
            $defaultRepositories,
        );
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        if (!\is_array($payload)) {
            throw new InvalidArgumentException(\sprintf('Payload must be an array, string given to "%s".', self::class));
        }

        $body = $this->buildRequestBody($model, MessagePayload::flattenMessages(MessagePayload::requireMessages($payload)), $options);
        $run = $this->api->createAgentRun($body);
        $stream = $this->api->openRunStream($run['agentId'], $run['runId']);
        $text = $this->streamReader->collectAssistantText($stream);

        return new InMemoryRawResult(
            [
                'text' => $text,
                'cursor_agent_id' => $run['agentId'],
                'cursor_run_id' => $run['runId'],
            ],
            [],
            (object) ['status' => 200],
        );
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildRequestBody(Model $model, string $promptText, array $options): array
    {
        $repos = $options['cursor_repos'] ?? $this->defaultRepositories;
        if (!\is_array($repos)) {
            throw new InvalidArgumentException('Option "cursor_repos" must be an array of repository descriptors when set.');
        }

        $body = [
            'prompt' => [
                'text' => $promptText,
            ],
        ];

        if ([] !== $repos) {
            $body['repos'] = $repos;
        }

        $modelName = $model->getName();
        if ('default' !== $modelName) {
            $body['model'] = [
                'id' => $modelName,
            ];
        }

        return $body;
    }
}
