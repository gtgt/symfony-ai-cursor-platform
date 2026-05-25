<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor;

use Symfony\AI\Platform\Bridge\Cursor\Cli\Factory as CliFactory;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Factory as CloudAgentFactory;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Facade for Cursor platform bridges. Prefer adapter-specific factories for new code:
 *
 * - {@see CloudAgent\Factory} — Cloud Agents REST API ({@code api.cursor.com})
 * - {@see Cli\Factory} — local Cursor CLI ({@code agent})
 */
final class Factory
{
    /**
     * @param list<array{url: string, startingRef?: string|null, prUrl?: string|null}> $defaultRepositories
     */
    public static function createCloudProvider(
        #[\SensitiveParameter] string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'cursor',
        string $baseUri = 'https://api.cursor.com/',
        array $defaultRepositories = [],
    ): ProviderInterface {
        return CloudAgentFactory::createProvider($apiKey, $httpClient, $modelCatalog, $contract, $eventDispatcher, $name, $baseUri, $defaultRepositories);
    }

    /**
     * @param list<array{url: string, startingRef?: string|null, prUrl?: string|null}> $defaultRepositories
     */
    public static function createCloudPlatform(
        #[\SensitiveParameter] string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $baseUri = 'https://api.cursor.com/',
        array $defaultRepositories = [],
        ?ModelRouterInterface $modelRouter = null,
    ): Platform {
        return CloudAgentFactory::createPlatform($apiKey, $httpClient, $modelCatalog, $contract, $eventDispatcher, $baseUri, $defaultRepositories, $modelRouter);
    }

    /**
     * @param list<string> $defaultArgs
     */
    public static function createCliProvider(
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
    ): ProviderInterface {
        return CliFactory::createProvider($apiKey, $binary, $workspace, $trust, $force, $sandbox, $timeout, $defaultArgs, $modelCatalog, $contract, $eventDispatcher, $name);
    }

    /**
     * @param list<string> $defaultArgs
     */
    public static function createCliPlatform(
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
    ): Platform {
        return CliFactory::createPlatform($apiKey, $binary, $workspace, $trust, $force, $sandbox, $timeout, $defaultArgs, $modelCatalog, $contract, $eventDispatcher, $modelRouter);
    }
}
