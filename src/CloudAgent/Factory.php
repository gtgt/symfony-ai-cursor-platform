<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\CloudAgent;

use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\ModelRouterInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Factory
{
    /**
     * @param list<array{url: string, startingRef?: string|null, prUrl?: string|null}> $defaultRepositories
     */
    public static function createProvider(
        #[\SensitiveParameter] string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'cursor',
        string $baseUri = 'https://api.cursor.com/',
        array $defaultRepositories = [],
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): ProviderInterface {
        $httpClient ??= HttpClient::create();
        $modelCatalog ??= new ModelCatalog();

        return new Provider(
            $name,
            [
                ModelClient::fromHttpClient($httpClient, $apiKey, $baseUri, $defaultRepositories),
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
     * @param list<array{url: string, startingRef?: string|null, prUrl?: string|null}> $defaultRepositories
     */
    public static function createPlatform(
        #[\SensitiveParameter] string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ?ModelCatalogInterface $modelCatalog = null,
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $baseUri = 'https://api.cursor.com/',
        array $defaultRepositories = [],
        ?ModelRouterInterface $modelRouter = null,
        ?TokenUsageExtractorInterface $tokenUsageExtractor = null,
    ): Platform {
        return new Platform(
            [self::createProvider($apiKey, $httpClient, $modelCatalog, $contract, $eventDispatcher, 'cursor', $baseUri, $defaultRepositories, $tokenUsageExtractor)],
            $modelRouter ?? new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }
}
