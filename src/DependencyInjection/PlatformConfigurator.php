<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\DependencyInjection;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Registers Cursor platform services into the container by importing the dedicated service files
 * (config/cli_services.php, config/cloud_services.php) after seeding the required parameters and aliases.
 *
 * Individual services (ModelClient, ResultConverter, TokenUsageExtractor, RestClient, RunStreamReader,
 * Provider, Platform) are then available for autowiring/decoration like any other Symfony service.
 */
final class PlatformConfigurator
{
    /**
     * @param array{
     *     api_key: string,
     *     http_client?: string,
     *     base_uri?: string,
     *     repositories?: list<array{url: string, startingRef?: string|null, prUrl?: string|null}>,
     * } $config
     */
    public static function registerCloud(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $container->setParameter('ai.platform.cursor.api_key', $config['api_key'] ?? '');
        $container->setParameter('ai.platform.cursor.base_uri', $config['base_uri'] ?? 'https://api.cursor.com/');
        $container->setParameter('ai.platform.cursor.repositories', $config['repositories'] ?? []);

        // Alias the configured http client service to a stable id used inside cloud_services.php.
        $container->setAlias('ai.platform.cursor.http_client', $config['http_client'] ?? 'http_client');

        $configurator->import('../config/cloud_services.php');

        $container->registerAliasForArgument('ai.platform.cursor', PlatformInterface::class, 'cursor');
    }

    /**
     * @param array{
     *     api_key?: string|null,
     *     binary?: string,
     *     workspace?: string|null,
     *     trust?: bool,
     *     force?: bool,
     *     sandbox?: string|null,
     *     timeout?: int,
     *     extra_args?: list<string>,
     * } $config
     */
    public static function registerCli(array $config, ContainerConfigurator $configurator, ContainerBuilder $container, ?string $defaultWorkspace = null): void
    {
        $container->setParameter('ai.platform.cursor_cli.api_key', $config['api_key'] ?? null);
        $container->setParameter('ai.platform.cursor_cli.binary', $config['binary'] ?? 'agent');
        $container->setParameter('ai.platform.cursor_cli.workspace', $config['workspace'] ?? $defaultWorkspace);
        $container->setParameter('ai.platform.cursor_cli.trust', $config['trust'] ?? true);
        $container->setParameter('ai.platform.cursor_cli.force', $config['force'] ?? false);
        $container->setParameter('ai.platform.cursor_cli.sandbox', $config['sandbox'] ?? null);
        $container->setParameter('ai.platform.cursor_cli.timeout', $config['timeout'] ?? 600);
        $container->setParameter('ai.platform.cursor_cli.extra_args', $config['extra_args'] ?? []);

        $configurator->import('../config/cli_services.php');

        $container->registerAliasForArgument('ai.platform.cursor_cli', PlatformInterface::class, 'cursor_cli');
    }
}

