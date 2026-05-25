<?php

declare(strict_types=1);

namespace Symfony\Component\Config\Definition\Configurator;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

return (new ArrayNodeDefinition('cli'))
    ->canBeDisabled()
    ->children()
        ->scalarNode('api_key')
            ->defaultNull()
            ->info('Optional API key (CURSOR_API_KEY); omit when using CLI login session')
        ->end()
        ->scalarNode('binary')
            ->defaultValue('agent')
            ->info('Cursor CLI binary (agent or cursor agent)')
        ->end()
        ->scalarNode('workspace')
            ->defaultNull()
            ->info('Workspace directory (--workspace); defaults to project dir when null')
        ->end()
        ->booleanNode('trust')
            ->defaultTrue()
            ->info('Pass --trust for headless / non-interactive runs')
        ->end()
        ->booleanNode('force')
            ->defaultFalse()
            ->info('Pass --force to auto-approve tool calls')
        ->end()
        ->enumNode('sandbox')
            ->values(['enabled', 'disabled'])
            ->defaultNull()
            ->info('Override sandbox mode (enabled/disabled)')
        ->end()
        ->integerNode('timeout')
            ->defaultValue(600)
            ->min(1)
            ->info('Process timeout in seconds')
        ->end()
        ->arrayNode('extra_args')
            ->scalarPrototype()->end()
            ->defaultValue([])
            ->info('Additional CLI arguments inserted before the prompt')
        ->end()
    ->end();
