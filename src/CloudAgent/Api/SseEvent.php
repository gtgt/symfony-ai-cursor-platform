<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api;

/**
 * Value object representing one parsed SSE frame from the Cursor Cloud Agents run stream.
 *
 * @phpstan-type SsePayload array<string, mixed>
 */
final class SseEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $event,
        public readonly array $data,
        public readonly ?string $id = null,
    ) {
    }
}
