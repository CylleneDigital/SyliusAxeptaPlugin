<?php

declare(strict_types=1);

namespace CylleneDigital\SyliusAxeptaPlugin\DependencyInjection;

use CylleneDigital\SyliusAxeptaPlugin\Axepta\Protocol\AxeptaCredentials;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @internal
 */
final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('cyllene_digital_sylius_axepta');

        // The tree is written without the usual chaining, and that is not a matter of taste. The
        // fluent `TreeBuilder` API is not typed the same way from one Symfony major to the next:
        // `getRootNode()` declares a union in 6.4 and `end()` returns `NodeParentInterface|null`
        // there, so static analysis loses the thread at the second node. Going through the node
        // builder avoids `end()`, which only serves to walk back up a chain, and makes the file
        // analysable on both sides.
        $rootNode = $treeBuilder->getRootNode();
        \assert($rootNode instanceof ArrayNodeDefinition);

        $children = $rootNode->children();

        // A single URL, overridable. It exists to absorb an endpoint change on the BNP side
        // without waiting for a release - certainly not to switch between test and production:
        // there is only one endpoint, and the `MerchantID` is what determines the environment.
        $children->scalarNode('payment_page_url')
            ->cannotBeEmpty()
            ->defaultValue(AxeptaCredentials::DEFAULT_PAYMENT_PAGE_URL);

        $children->scalarNode('logger_channel')
            ->cannotBeEmpty()
            ->defaultValue('axepta');

        return $treeBuilder;
    }
}
