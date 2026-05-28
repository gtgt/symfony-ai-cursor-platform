<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\Cli;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Extracts token usage from Cursor CLI agent results (json / stream-json terminal `result` event).
 */
final class TokenUsageExtractor implements TokenUsageExtractorInterface
{
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
    {
        // Stream mode emits TokenUsage as a delta inside the StreamResult.
        if ($options['stream'] ?? false) {
            return null;
        }

        $data = $rawResult->getData();
        $usage = $data['usage'] ?? null;
        if (!\is_array($usage)) {
            return null;
        }

        $input = isset($usage['inputTokens']) ? (int) $usage['inputTokens'] : null;
        $output = isset($usage['outputTokens']) ? (int) $usage['outputTokens'] : null;
        $cacheRead = isset($usage['cacheReadTokens']) ? (int) $usage['cacheReadTokens'] : null;
        $cacheWrite = isset($usage['cacheWriteTokens']) ? (int) $usage['cacheWriteTokens'] : null;

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
