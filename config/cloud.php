<?php

declare(strict_types=1);

namespace Symfony\Component\Config\Definition\Configurator;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;

return (new ArrayNodeDefinition('cloud'))
    ->canBeDisabled()
    ->children()
        ->scalarNode('api_key')
            ->isRequired()
            ->cannotBeEmpty()
            ->info('Cursor Cloud Agents API key (CURSOR_API_KEY)')
        ->end()
        ->scalarNode('http_client')
            ->defaultValue('http_client')
            ->info('Service ID of the HTTP client to use')
        ->end()
        ->scalarNode('base_uri')
            ->defaultValue('https://api.cursor.com/')
            ->info('Cursor Cloud Agents API base URI')
        ->end()
        ->arrayNode('repositories')
            ->info('Default repositories attached to each agent run')
            ->arrayPrototype()
                ->children()
                    ->scalarNode('url')->isRequired()->cannotBeEmpty()->end()
                    ->scalarNode('startingRef')->defaultNull()->end()
                    ->scalarNode('prUrl')->defaultNull()->end()
                ->end()
            ->end()
            ->defaultValue([])
        ->end()
    ->end();
