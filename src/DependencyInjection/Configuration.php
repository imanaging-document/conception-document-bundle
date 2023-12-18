<?php

namespace Imanaging\ConceptionDocumentBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
  public function getConfigTreeBuilder() : TreeBuilder
  {
    $treeBuilder = new TreeBuilder('imanaging_conception_document');
    $rootNode = $treeBuilder->getRootNode();
    $rootNode
      ->children()
        ->variableNode('output_path')->defaultValue('%kernel.project_dir%/public')->info('Output directory for generated files (pdf, png, jpg)')->end()
      ->end()
    ;
    return $treeBuilder;
  }
}
