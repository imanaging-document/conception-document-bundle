<?php


namespace Imanaging\ConceptionDocumentBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

class ImanagingConceptionDocumentExtension extends Extension
{
  /**
   * @param array $configs
   * @param ContainerBuilder $container
   * @throws Exception
   */
  public function load(array $configs, ContainerBuilder $container)
  {
    $loader = new XmlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
    $loader->load('services.xml');

    $configuration = $this->getConfiguration($configs, $container);
    $config = $this->processConfiguration($configuration, $configs);
    $definition = $container->getDefinition('imanaging_conception_document.conception_document');
    $definition->setArgument(1, $config['output_path']);
    $definition->setArgument(2, $config['wkhtmltopdf_path']);

    $definition = $container->getDefinition('imanaging_conception_document.conception_document_controller');
    $definition->setArgument(3, $config['upload_path']);
    $definition->setArgument(4, $config['base_path']);

    $definition = $container->getDefinition('imanaging_conception_document.twig_functions');
    $definition->setArgument(1, $config['upload_path']);
  }

  public function getAlias() : string
  {
    return 'imanaging_conception_document';
  }
}