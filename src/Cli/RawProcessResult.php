<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

/**
 * Wraps a Symfony Process running the Cursor CLI agent as a RawResultInterface.
 *
 * Two output formats are supported:
 *  - "json"        — a single JSON object emitted on completion
 *  - "stream-json" — newline-delimited JSON events (NDJSON)
 */
final class RawProcessResult implements RawResultInterface
{
    public function __construct(
        private readonly Process $process,
        private readonly string $outputFormat,
    ) {
    }

    /**
     * Waits for the process to finish, parses all output, and returns the canonical
     * terminal result. For stream-json, the last `type=result` event wins and the
     * decoded tool-call events are surfaced under the `tool_calls` key.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->process->wait();
        $this->assertSuccessful();

        $stdout = rtrim($this->process->getOutput());
        if ('' === $stdout) {
            throw new RuntimeException('Cursor CLI returned empty output.');
        }

        if ('json' === $this->outputFormat) {
            /** @var array<string, mixed> $data */
            $data = json_decode($stdout, true, 512, \JSON_THROW_ON_ERROR);

            return $data;
        }

        if ('stream-json' !== $this->outputFormat) {
            throw new RuntimeException(\sprintf('Unsupported Cursor CLI output format "%s".', $this->outputFormat));
        }

        $result = [];
        $toolCalls = [];
        $started = [];

        foreach (self::splitLines($stdout) as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }

            $type = $event['type'] ?? null;

            if ('result' === $type) {
                $result = $event;
                continue;
            }

            if ('tool_call' === $type) {
                $callId = (string) ($event['call_id'] ?? '');
                if ('' === $callId) {
                    continue;
                }

                if ('started' === ($event['subtype'] ?? null)) {
                    [$name, $args] = self::extractToolCall($event['tool_call'] ?? []);
                    $started[$callId] = ['id' => $callId, 'name' => $name, 'arguments' => $args];
                    continue;
                }

                if ('completed' === ($event['subtype'] ?? null)) {
                    [$name, $args] = self::extractToolCall($event['tool_call'] ?? []);
                    $entry = $started[$callId] ?? ['id' => $callId, 'name' => $name, 'arguments' => $args];
                    $entry['name'] = $name ?: $entry['name'];
                    $entry['arguments'] = [] !== $args ? $args : $entry['arguments'];
                    $entry['result'] = self::extractToolResult($event['tool_call'] ?? []);
                    $toolCalls[] = $entry;
                    unset($started[$callId]);
                }
            }
        }

        if ([] === $result) {
            throw new RuntimeException('Cursor CLI stream ended without a terminal "result" event.');
        }

        if ([] !== $toolCalls) {
            $result['tool_calls'] = $toolCalls;
        }

        return $result;
    }

    /**
     * Yields decoded JSONL events from the process as they arrive.
     *
     * For json mode (single object), this yields once on completion.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getDataStream(): \Generator
    {
        if ('json' === $this->outputFormat) {
            yield $this->getData();

            return;
        }

        $buffer = '';

        while ($this->process->isRunning()) {
            $chunk = $this->process->getIncrementalOutput();

            if ('' === $chunk) {
                usleep(10000);
                continue;
            }

            $buffer .= $chunk;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines) ?? '';

            foreach ($lines as $line) {
                $line = trim($line);
                if ('' === $line) {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (\is_array($decoded)) {
                    yield $decoded;
                }
            }
        }

        $buffer .= $this->process->getIncrementalOutput();

        foreach (self::splitLines($buffer) as $line) {
            $decoded = json_decode($line, true);
            if (\is_array($decoded)) {
                yield $decoded;
            }
        }

        $this->assertSuccessful();
    }

    public function getObject(): Process
    {
        return $this->process;
    }

    private function assertSuccessful(): void
    {
        if ($this->process->isSuccessful()) {
            return;
        }

        $err = trim($this->process->getErrorOutput());
        throw new RuntimeException(\sprintf(
            'Cursor CLI process failed (exit %d): %s',
            (int) $this->process->getExitCode(),
            '' !== $err ? $err : 'no stderr output',
        ));
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $stdout): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $stdout)), static fn (string $l): bool => '' !== $l));
    }

    /**
     * Normalises a tool_call payload into [$name, $arguments].
     *
     * Cursor uses tool-specific keys (`readToolCall`, `writeToolCall`) or a generic `function` key.
     *
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
     * Extracts the `result` payload (success or error) from a completed tool_call.
     *
     * @param array<string, mixed> $toolCall
     *
     * @return array<string, mixed>|null
     */
    private static function extractToolResult(array $toolCall): ?array
    {
        foreach ($toolCall as $key => $value) {
            if (\is_array($value) && isset($value['result']) && \is_array($value['result'])) {
                return $value['result'];
            }
        }

        return null;
    }
}
