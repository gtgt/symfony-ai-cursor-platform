<?php

declare(strict_types=1);

use Symfony\AI\Platform\Bridge\Cursor\Cli\ModelCatalog as CliModelCatalog;
use Symfony\AI\Platform\Bridge\Cursor\CloudAgent\ModelCatalog as CloudModelCatalog;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->services()
        ->set('ai.platform.model_catalog.cursor', CloudModelCatalog::class)
        ->set('ai.platform.model_catalog.cursor_cli', CliModelCatalog::class)
    ;
};
