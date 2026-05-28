<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\Cursor;

use Symfony\AI\Platform\Bridge\Cursor\DependencyInjection\PlatformConfigurator;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class Bundle extends AbstractBundle
{
    /**
     * @return string
     */
    public function __construct()
    {
        $this->name = $this->extensionAlias = 'ai_platform_cursor';
    }
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->import('../config/definition.php');
    }

    /**
     * @param array{cloud?: array<string, mixed>, cli?: array<string, mixed>} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $configurator, ContainerBuilder $container): void
    {
        $configurator->import('../config/services.php');

        $container->registerForAutoconfiguration(\Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface::class)
            ->addTag('ai.platform.token_usage_extractor');

        if ($this->isAdapterEnabled($config['cloud'] ?? null)) {
            PlatformConfigurator::registerCloud($config['cloud'], $configurator, $container);
        }

        if ($this->isAdapterEnabled($config['cli'] ?? null)) {
            PlatformConfigurator::registerCli(
                $config['cli'],
                $configurator,
                $container,
                $container->hasParameter('kernel.project_dir') ? (string) $container->getParameter('kernel.project_dir') : null,
            );
        }
    }

    /**
     * @param array<string, mixed>|null $adapterConfig
     */
    private function isAdapterEnabled(?array $adapterConfig): bool
    {
        return null !== $adapterConfig && false !== ($adapterConfig['enabled'] ?? true);
    }
}
