<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor\DependencyInjection;

use Symfony\AI\Platform\Bridge\Cursor\Cli\Factory as CliFactory;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Factory as CloudAgentFactory;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers Cursor platform services the same way Symfony AI Bundle registers built-in platforms.
 */
final class PlatformConfigurator
{
    /**
     * @param array{api_key: string, http_client?: string, base_uri?: string, repositories?: list<array{url: string, startingRef?: string|null, prUrl?: string|null}>} $config
     */
    public static function registerCloud(array $config, ContainerBuilder $container): void
    {
        $platformId = 'ai.platform.cursor';
        $definition = (new Definition(Platform::class))
            ->setFactory(CloudAgentFactory::class.'::createPlatform')
            ->setLazy(true)
            ->addTag('proxy', ['interface' => PlatformInterface::class])
            ->setArguments([
                $config['api_key'] ?? null,
                new Reference($config['http_client'] ?? 'http_client', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                new Reference('ai.platform.model_catalog.cursor'),
                null,
                new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                $config['base_uri'] ?? 'https://api.cursor.com/',
                $config['repositories'] ?? [],
                null,
            ])
            ->addTag('ai.platform', ['name' => 'cursor']);

        $container->setDefinition($platformId, $definition);
        $container->registerAliasForArgument($platformId, PlatformInterface::class, 'cursor');
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
    public static function registerCli(array $config, ContainerBuilder $container, ?string $defaultWorkspace = null): void
    {
        $platformId = 'ai.platform.cursor_cli';
        $definition = (new Definition(Platform::class))
            ->setFactory(CliFactory::class.'::createPlatform')
            ->setLazy(true)
            ->addTag('proxy', ['interface' => PlatformInterface::class])
            ->setArguments([
                $config['api_key'] ?? null,
                $config['binary'] ?? 'agent',
                $config['workspace'] ?? $defaultWorkspace,
                $config['trust'] ?? true,
                $config['force'] ?? false,
                $config['sandbox'] ?? null,
                $config['timeout'] ?? 600,
                $config['extra_args'] ?? [],
                new Reference('ai.platform.model_catalog.cursor_cli'),
                null,
                new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
                null,
            ])
            ->addTag('ai.platform', ['name' => 'cursor_cli']);

        $container->setDefinition($platformId, $definition);
        $container->registerAliasForArgument($platformId, PlatformInterface::class, 'cursor_cli');
    }
}
