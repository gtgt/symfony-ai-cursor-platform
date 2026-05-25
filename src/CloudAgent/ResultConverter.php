<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent;

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

        return new TextResult((string) ($data['text'] ?? ''));
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
