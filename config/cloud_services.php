<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\RestClient;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\Api\RunStreamReader;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\ModelClient;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\ResultConverter;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\AI\Platform\ResultConverterInterface;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('ai.platform.cursor.rest_client', RestClient::class)
        ->args([
            service('ai.platform.cursor.http_client'),
            param('ai.platform.cursor.api_key'),
            param('ai.platform.cursor.base_uri'),
        ]);

    $services->set('ai.platform.cursor.run_stream_reader', RunStreamReader::class)
        ->args([
            service('ai.platform.cursor.http_client'),
        ]);

    $services->set('ai.platform.cursor.result_converter', ResultConverter::class)
        // Cursor Cloud Agents v1 doesn't expose token usage on runs today, so no extractor is injected.
        ->args([null])
        ->tag('ai.platform.result_converter', ['provider' => 'cursor']);

    $services->alias(ResultConverterInterface::class.' $cursorResultConverter', 'ai.platform.cursor.result_converter');

    $services->set('ai.platform.cursor.model_client', ModelClient::class)
        ->args([
            service('ai.platform.cursor.rest_client'),
            service('ai.platform.cursor.run_stream_reader'),
            param('ai.platform.cursor.repositories'),
        ])
        ->tag('ai.platform.model_client', ['provider' => 'cursor']);

    $services->set('ai.platform.cursor.contract', Contract::class)
        ->factory([Contract::class, 'create']);

    $services->set('ai.platform.cursor.provider', Provider::class)
        ->args([
            'cursor',
            [service('ai.platform.cursor.model_client')],
            [service('ai.platform.cursor.result_converter')],
            service('ai.platform.model_catalog.cursor'),
            service('ai.platform.cursor.contract'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ]);

    $services->alias(ProviderInterface::class.' $cursorProvider', 'ai.platform.cursor.provider');

    $services->set('ai.platform.cursor.model_router', CatalogBasedModelRouter::class);

    $services->set('ai.platform.cursor', Platform::class)
        ->args([
            [service('ai.platform.cursor.provider')],
            service('ai.platform.cursor.model_router'),
            service('event_dispatcher')->ignoreOnInvalid(),
        ])
        ->tag('ai.platform', ['name' => 'cursor'])
        ->tag('proxy', ['interface' => PlatformInterface::class]);

    $services->alias(PlatformInterface::class.' $cursor', 'ai.platform.cursor');
};
