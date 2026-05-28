<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Converts Cursor CLI agent output (json / stream-json) into platform result objects.
 */
final class ResultConverter implements ResultConverterInterface
{
    private const METADATA_FIELDS = [
        'session_id', 'request_id', 'duration_ms', 'duration_api_ms',
        'model', 'cwd', 'permissionMode', 'apiKeySource',
    ];

    private readonly TokenUsageExtractorInterface $tokenUsageExtractor;

    public function __construct(?TokenUsageExtractorInterface $tokenUsageExtractor = null)
    {
        $this->tokenUsageExtractor = $tokenUsageExtractor ?? new TokenUsageExtractor();
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        if ([] === $data) {
            throw new RuntimeException('Cursor CLI did not return any result.');
        }

        if (true === ($data['is_error'] ?? false)) {
            throw new RuntimeException((string) ($data['result'] ?? 'Cursor CLI agent run failed.'));
        }

        if (!isset($data['result'])) {
            throw new RuntimeException('Unexpected Cursor CLI JSON response: missing "result" field.');
        }

        $results = [];
        foreach ($data['tool_calls'] ?? [] as $toolCall) {
            if (!\is_array($toolCall) || !isset($toolCall['id'], $toolCall['name'])) {
                continue;
            }
            /** @var array<string, mixed> $args */
            $args = \is_array($toolCall['arguments'] ?? null) ? $toolCall['arguments'] : [];
            $results[] = new ToolCallResult([new ToolCall(
                (string) $toolCall['id'],
                (string) $toolCall['name'],
                $args,
            )]);
        }

        $results[] = new TextResult((string) $data['result']);

        $finalResult = 1 === \count($results) ? $results[0] : new MultiPartResult($results);
        $this->attachMetadata($finalResult, $data);

        return $finalResult;
    }

    public function getTokenUsageExtractor(): TokenUsageExtractorInterface
    {
        return $this->tokenUsageExtractor;
    }

    /**
     * @return \Generator<int, \Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface>
     */
    private function convertStream(RawResultInterface $result): \Generator
    {
        /** @var array<string, array{id: string, name: string, arguments: array<string, mixed>}> $pendingToolCalls */
        $pendingToolCalls = [];

        foreach ($result->getDataStream() as $event) {
            $type = $event['type'] ?? null;
            $subtype = $event['subtype'] ?? null;

            switch ($type) {
                case 'system':
                    if ('init' === $subtype) {
                        yield new MetadataDelta('session', [
                            'session_id' => $event['session_id'] ?? null,
                            'model' => $event['model'] ?? null,
                            'cwd' => $event['cwd'] ?? null,
                            'permissionMode' => $event['permissionMode'] ?? null,
                            'apiKeySource' => $event['apiKeySource'] ?? null,
                        ]);
                    }
                    break;

                case 'thinking':
                    if ('delta' === $subtype) {
                        yield new ThinkingDelta((string) ($event['text'] ?? ''));
                    }
                    // 'completed' subtype intentionally ignored — no semantic delta.
                    break;

                case 'assistant':
                    if (!self::shouldEmitAssistant($event)) {
                        break;
                    }
                    $blocks = $event['message']['content'] ?? [];
                    if (\is_array($blocks)) {
                        foreach ($blocks as $block) {
                            if (\is_array($block) && isset($block['text']) && \is_string($block['text'])) {
                                yield new TextDelta($block['text']);
                            }
                        }
                    }
                    break;

                case 'tool_call':
                    $callId = (string) ($event['call_id'] ?? '');
                    $payload = \is_array($event['tool_call'] ?? null) ? $event['tool_call'] : [];
                    [$name, $args] = self::extractToolCall($payload);

                    if ('started' === $subtype && '' !== $callId) {
                        $pendingToolCalls[$callId] = ['id' => $callId, 'name' => $name, 'arguments' => $args];
                        yield new ToolCallStart($callId, $name);
                        if ([] !== $args) {
                            try {
                                yield new ToolInputDelta($callId, $name, json_encode($args, \JSON_THROW_ON_ERROR));
                            } catch (\JsonException) {
                                // ignore; tool args could not be re-encoded
                            }
                        }
                    } elseif ('completed' === $subtype && '' !== $callId) {
                        $pendingToolCalls[$callId] = [
                            'id' => $callId,
                            'name' => $name ?: ($pendingToolCalls[$callId]['name'] ?? ''),
                            'arguments' => [] !== $args ? $args : ($pendingToolCalls[$callId]['arguments'] ?? []),
                        ];
                        yield new MetadataDelta('tool_call.'.$callId, $payload);
                    }
                    break;

                case 'result':
                    if ([] !== $pendingToolCalls) {
                        $toolCalls = array_map(
                            static fn (array $tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments']),
                            array_values($pendingToolCalls),
                        );
                        yield new ToolCallComplete($toolCalls);
                        $pendingToolCalls = [];
                    }

                    foreach (['session_id', 'request_id', 'duration_ms', 'duration_api_ms'] as $key) {
                        if (isset($event[$key])) {
                            yield new MetadataDelta($key, $event[$key]);
                        }
                    }

                    if (null !== ($tokenUsage = self::buildTokenUsage($event['usage'] ?? null))) {
                        yield $tokenUsage;
                    }
                    break;

                // 'user' and unknown types are ignored — forward-compatible.
            }
        }
    }

    /**
     * Applies Cursor's documented --stream-partial-output dedup rules:
     *  - Skip events with `model_call_id` (buffered flush before a tool call).
     *  - Skip events without `timestamp_ms` (final flush at end of turn).
     *  Events lacking both indicators (non --stream-partial-output mode) are kept.
     *
     * @param array<string, mixed> $event
     */
    private static function shouldEmitAssistant(array $event): bool
    {
        $hasTimestamp = \array_key_exists('timestamp_ms', $event);
        $hasModelCallId = \array_key_exists('model_call_id', $event);

        if (!$hasTimestamp && !$hasModelCallId) {
            // Plain assistant event (no partial-output streaming) — emit.
            return true;
        }

        return $hasTimestamp && !$hasModelCallId;
    }

    /**
     * @param array<string, mixed> $toolCall
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function extractToolCall(array $toolCall): array
    {
        if (isset($toolCall['function']) && \is_array($toolCall['function'])) {
            $args = $toolCall['function']['arguments'] ?? [];
            if (\is_string($args)) {
                try {
                    $decoded = json_decode($args, true, 512, \JSON_THROW_ON_ERROR);
                    $args = \is_array($decoded) ? $decoded : [];
                } catch (\JsonException) {
                    $args = [];
                }
            }

            return [(string) ($toolCall['function']['name'] ?? ''), \is_array($args) ? $args : []];
        }

        foreach ($toolCall as $key => $value) {
            if (!\is_array($value) || !str_ends_with((string) $key, 'ToolCall')) {
                continue;
            }
            $name = substr((string) $key, 0, -\strlen('ToolCall'));
            $args = $value['args'] ?? [];

            return [$name, \is_array($args) ? $args : []];
        }

        return ['', []];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function attachMetadata(ResultInterface $result, array $data): void
    {
        $metadata = $result->getMetadata();
        foreach (self::METADATA_FIELDS as $field) {
            if (isset($data[$field])) {
                $metadata->add($field, $data[$field]);
            }
        }

        if (null !== ($tokenUsage = self::buildTokenUsage($data['usage'] ?? null))) {
            $metadata->add('token_usage', $tokenUsage);
        }
    }

    private static function buildTokenUsage(mixed $usage): ?TokenUsage
    {
        if (!\is_array($usage)) {
            return null;
        }

        $input = isset($usage['inputTokens']) ? (int) $usage['inputTokens'] : null;
        $output = isset($usage['outputTokens']) ? (int) $usage['outputTokens'] : null;
        $cacheRead = isset($usage['cacheReadTokens']) ? (int) $usage['cacheReadTokens'] : null;
        $cacheWrite = isset($usage['cacheWriteTokens']) ? (int) $usage['cacheWriteTokens'] : null;

        if (null === $input && null === $output && null === $cacheRead && null === $cacheWrite) {
            return null;
        }

        $total = null;
        if (null !== $input || null !== $output) {
            $total = ($input ?? 0) + ($output ?? 0);
        }

        return new TokenUsage(
            promptTokens: $input,
            completionTokens: $output,
            cacheCreationTokens: $cacheWrite,
            cacheReadTokens: $cacheRead,
            totalTokens: $total,
        );
    }
}
