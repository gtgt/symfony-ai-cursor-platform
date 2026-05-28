<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\AI\Platform\Bridge\Cursor\Cli\ModelClient;
use Symfony\AI\Platform\Bridge\Cursor\Cli\ResultConverter;
use Symfony\AI\Platform\Bridge\Cursor\Cli\TokenUsageExtractor;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('ai.platform.cursor_cli.token_usage_extractor', TokenUsageExtractor::class)
        ->tag('ai.platform.token_usage_extractor', ['provider' => 'cursor_cli']);

    $services->alias(TokenUsageExtractorInterface::class.' $cursorCliTokenUsageExtractor', 'ai.platform.cursor_cli.token_usage_extractor');

    $services->set('ai.platform.cursor_cli.result_converter', ResultConverter::class)
        ->args([service('ai.platform.cursor_cli.token_usage_extractor')])
        ->tag('ai.platform.result_converter', ['provider' => 'cursor_cli']);

    $services->alias(ResultConverterInterface::class.' $cursorCliResultConverter', 'ai.platform.cursor_cli.result_converter');

    $services->set('ai.platform.cursor_cli.model_client', ModelClient::class)
        ->args([
            param('ai.platform.cursor_cli.binary'),
            param('ai.platform.cursor_cli.api_key'),
            param('ai.platform.cursor_cli.workspace'),
            param('ai.platform.cursor_cli.trust'),
            param('ai.platform.cursor_cli.force'),
            param('ai.platform.cursor_cli.sandbox'),
            param('ai.platform.cursor_cli.timeout'),
            param('ai.platform.cursor_cli.extra_args'),
        ])
        ->tag('ai.platform.model_client', ['provider' => 'cursor_cli']);

    $services->set('ai.platform.cursor_cli.contract', Contract::class)
        ->factory([Contract::class, 'create']);

    $services->set('ai.platform.cursor_cli.provider', Provider::class)
        ->args([
            'cursor_cli',
            [service('ai.platform.cursor_cli.model_client')],
            [service('ai.platform.cursor_cli.result_converter')],
            service('ai.platform.model_catalog.cursor_cli'),
            service('ai.platform.cursor_cli.contract'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ]);

    $services->alias(ProviderInterface::class.' $cursorCliProvider', 'ai.platform.cursor_cli.provider');

    $services->set('ai.platform.cursor_cli.model_router', CatalogBasedModelRouter::class);

    $services->set('ai.platform.cursor_cli', Platform::class)
        ->args([
            [service('ai.platform.cursor_cli.provider')],
            service('ai.platform.cursor_cli.model_router'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ])
        ->tag('ai.platform', ['name' => 'cursor_cli'])
        ->tag('proxy', ['interface' => PlatformInterface::class]);

    $services->alias(PlatformInterface::class.' $cursorCli', 'ai.platform.cursor_cli');
};
