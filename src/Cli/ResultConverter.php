<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\Cli;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

final class ResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return $model instanceof Agent;
    }

    public function convert(RawResultInterface $result, array $options = []): TextResult
    {
        $data = $result->getData();

        if (($data['is_error'] ?? false) === true) {
            throw new RuntimeException((string) ($data['result'] ?? 'Cursor CLI agent run failed.'));
        }

        if (isset($data['type']) && 'result' === $data['type']) {
            return new TextResult((string) ($data['result'] ?? ''));
        }

        // stream-json: last line is typically the terminal "result" event
        if (isset($data['_cli_stdout_lines']) && \is_array($data['_cli_stdout_lines'])) {
            return new TextResult(self::extractTextFromStreamLines($data['_cli_stdout_lines']));
        }

        throw new RuntimeException('Unexpected Cursor CLI JSON response: missing result payload.');
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }

    /**
     * @param list<mixed> $lines
     */
    private static function extractTextFromStreamLines(array $lines): string
    {
        $text = '';
        foreach ($lines as $line) {
            if (!\is_string($line)) {
                continue;
            }
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }
            if (isset($event['type']) && 'result' === $event['type']) {
                return (string) ($event['result'] ?? $text);
            }
            if (isset($event['type']) && 'assistant' === $event['type']) {
                $message = $event['message'] ?? null;
                if (\is_array($message) && isset($message['content']) && \is_array($message['content'])) {
                    foreach ($message['content'] as $block) {
                        if (\is_array($block) && isset($block['text'])) {
                            $text .= (string) $block['text'];
                        }
                    }
                }
            }
        }

        return $text;
    }
}
