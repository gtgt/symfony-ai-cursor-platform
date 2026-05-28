<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\Cli;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class Factory
{
    /**
     * @param list<string> $defaultArgs extra CLI flags appended before the prompt
     */
    public static function createProvider(
        ?string $apiKey = null,
        string $binary = 'agent',
        ?string $workspace = null,
        bool $trust = true,
        bool $force = false,
        ?string $sandbox = null,
        int $timeout = 600,
        array $defaultArgs = [],
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'cursor_cli',
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): ProviderInterface {
        $modelCatalog ??= new ModelCatalog();

        return new Provider(
            $name,
            [
                new ModelClient($binary, $apiKey, $workspace, $trust, $force, $sandbox, $timeout, $defaultArgs),
            ],
            [
                new ResultConverter($tokenUsageExtractor),
            ],
            $modelCatalog,
            $contract ?? Contract::create(),
            $eventDispatcher,
        );
    }

    /**
     * @param list<string> $defaultArgs extra CLI flags appended before the prompt
     */
    public static function createPlatform(
        ?string $apiKey = null,
        string $binary = 'agent',
        ?string $workspace = null,
        bool $trust = true,
        bool $force = false,
        ?string $sandbox = null,
        int $timeout = 600,
        array $defaultArgs = [],
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?ModelRouterInterface $modelRouter = null,
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): Platform {
        return new Platform(
            [self::createProvider($apiKey, $binary, $workspace, $trust, $force, $sandbox, $timeout, $defaultArgs, $modelCatalog, $contract, $eventDispatcher, 'cursor_cli', $tokenUsageExtractor)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
