<?php

namespace Imanaging\ConceptionDocumentBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
  public function getConfigTreeBuilder(): TreeBuilder
  {
    $treeBuilder = new TreeBuilder('imanaging_conception_document');
    $rootNode = $treeBuilder->getRootNode();
    $rootNode
      ->children()
        ->variableNode('output_path')->defaultValue('%kernel.project_dir%/public')->info('Output directory for generated files (pdf, png, jpg)')->end()
        ->variableNode('upload_path')->defaultValue('%kernel.project_dir%/public/upload/conception-document')->info('Upload directory for images')->end()
        ->variableNode('font_public_path')->defaultValue('/conception-document/fonts')->info('Public path used in @font-face URLs')->end()
        ->variableNode('base_path')->defaultValue('base.html.twig')->info('Twig base file')->end()
        ->booleanNode('editor_enabled')->defaultFalse()->end()
        ->scalarNode('editor_role')->defaultValue('ROLE_USER')->end()
        ->variableNode('editor_assets_path')->defaultValue('%kernel.project_dir%/public/build/conception-document')->info('Filesystem directory containing Paged.js and fonts copied by the application build')->end()
        ->scalarNode('editor_assets_public_path')->defaultValue('/build/conception-document')->info('Public path containing the legacy editor script')->end()
        ->variableNode('editor_output_path')->defaultValue('%kernel.project_dir%/var/conception-document/previews')->info('Private directory for temporary editor PDF artefacts')->end()
        ->scalarNode('gotenberg_url')->defaultValue('http://gotenberg:3000')->end()
        ->integerNode('editor_max_text_length')->defaultValue(1000000)->min(2048)->end()
        ->variableNode('wkhtmltopdf_path')->defaultValue('wkhtmltopdf')->info('WKHTMLTOPDF path')->end()
      ->end()
    ;
    return $treeBuilder;
  }
}
