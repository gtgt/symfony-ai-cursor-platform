<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent;

use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\SseEvent;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
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
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Converts Cursor Cloud Agents run results (REST + SSE) into platform result objects.
 */
final class ResultConverter implements ResultConverterInterface
{
    /**
     * @param TokenUsageExtractorInterface|null $tokenUsageExtractor Cursor Cloud Agents v1 does not expose token usage today,
     *                                                               so this defaults to null. Pass an extractor here if/when
     *                                                               a custom integration begins surfacing usage data.
     */
    public function __construct(
        private readonly ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ) {
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

        $textResult = new TextResult((string) ($data['text'] ?? ''));
        $metadata = $textResult->getMetadata();
        foreach (['cursor_agent_id', 'cursor_run_id', 'status', 'duration_ms'] as $field) {
            if (isset($data[$field])) {
                $metadata->add($field, $data[$field]);
            }
        }
        if (isset($data['git']) && \is_array($data['git']) && isset($data['git']['branches'])) {
            $metadata->add('git_branches', $data['git']['branches']);
        }

        return $textResult;
    }

    /**
     * Cursor Cloud Agents v1 does not surface token usage on runs today, so this returns null by default.
     */
    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
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
            if (!$event instanceof SseEvent) {
                continue;
            }

            switch ($event->event) {
                case 'status':
                    yield new MetadataDelta('status', (string) ($event->data['status'] ?? ''));
                    break;

                case 'assistant':
                    yield new TextDelta((string) ($event->data['text'] ?? ''));
                    break;

                case 'thinking':
                    yield new ThinkingDelta((string) ($event->data['text'] ?? ''));
                    break;

                case 'tool_call':
                    $callId = (string) ($event->data['callId'] ?? '');
                    $name = (string) ($event->data['name'] ?? '');
                    if ('' === $callId) {
                        break;
                    }
                    $status = (string) ($event->data['status'] ?? '');
                    /** @var array<string, mixed> $args */
                    $args = \is_array($event->data['args'] ?? null) ? $event->data['args'] : [];

                    if ('running' === $status) {
                        $pendingToolCalls[$callId] = ['id' => $callId, 'name' => $name, 'arguments' => $args];
                        yield new ToolCallStart($callId, $name);
                        if ([] !== $args) {
                            try {
                                yield new ToolInputDelta($callId, $name, json_encode($args, \JSON_THROW_ON_ERROR));
                            } catch (\JsonException) {
                                // ignore — args could not be re-encoded
                            }
                        }
                    } elseif ('completed' === $status) {
                        $pendingToolCalls[$callId] = [
                            'id' => $callId,
                            'name' => $name ?: ($pendingToolCalls[$callId]['name'] ?? ''),
                            'arguments' => [] !== $args ? $args : ($pendingToolCalls[$callId]['arguments'] ?? []),
                        ];
                        yield new MetadataDelta('tool_call.'.$callId, $event->data);
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

                    foreach (['runId' => 'run_id', 'status' => 'status', 'durationMs' => 'duration_ms'] as $src => $dst) {
                        if (isset($event->data[$src])) {
                            yield new MetadataDelta($dst, $event->data[$src]);
                        }
                    }
                    if (isset($event->data['git']) && \is_array($event->data['git']) && isset($event->data['git']['branches'])) {
                        yield new MetadataDelta('git_branches', $event->data['git']['branches']);
                    }
                    if (isset($event->data['text']) && \is_string($event->data['text']) && '' !== $event->data['text']) {
                        yield new TextDelta($event->data['text']);
                    }
                    break;

                case 'error':
                    throw new RuntimeException(\sprintf(
                        'Cursor Cloud Agents stream error [%s]: %s',
                        (string) ($event->data['code'] ?? 'unknown'),
                        (string) ($event->data['message'] ?? 'no message'),
                    ));

                case 'done':
                    return;

                // 'heartbeat', 'interaction_update' and unknowns are ignored — forward-compatible.
            }
        }
    }
}
