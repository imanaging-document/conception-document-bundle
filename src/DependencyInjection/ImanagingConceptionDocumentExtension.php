<?php


namespace Imanaging\ConceptionDocumentBundle\DependencyInjection;

use Exception;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ImanagingConceptionDocumentExtension extends Extension implements PrependExtensionInterface
{
  /**
   * @param array $configs
   * @param ContainerBuilder $container
   * @throws Exception
   */
  public function load(array $configs, ContainerBuilder $container): void
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

    $definition = $container->getDefinition('imanaging_conception_document.font_service');
    $definition->setArgument(0, $config['upload_path']);
    $definition->setArgument(1, $config['font_public_path']);

    $definition = $container->getDefinition('imanaging_conception_document.font_controller');
    $definition->setArgument(2, $config['base_path']);

    $definition = $container->getDefinition('imanaging_conception_document.twig_functions');
    $definition->setArgument(1, $config['upload_path']);
    $this->registerEditor($container,$config);
  }

  public function prepend(ContainerBuilder $container): void
  {
    if ($container->hasExtension('doctrine')) $container->prependExtensionConfig('doctrine', ['orm'=>['mappings'=>['ConceptionEditor'=>['type'=>'attribute','dir'=>__DIR__.'/../Editor/Entity','prefix'=>'Imanaging\\ConceptionDocumentBundle\\Editor\\Entity','is_bundle'=>false]]]]);
  }
  private function registerEditor(ContainerBuilder $c,array $config): void
  {
    $ns='Imanaging\\ConceptionDocumentBundle\\Editor\\';
    $c->registerForAutoconfiguration($ns.'Contract\\BlockTypeAdapterInterface')->addTag('conception_document.block_adapter');
    $c->register($ns.'Service\\BlockAdapterRegistry',$ns.'Service\\BlockAdapterRegistry')->setArguments([new \Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument('conception_document.block_adapter')]);
    $em=new Reference('doctrine.orm.default_entity_manager');$hook=new Reference('app.conception_personnalisation_service');
    $c->register($ns.'Service\\AssetStore',$ns.'Service\\AssetStore')->setArguments([$config['upload_path']]);
    $c->register($ns.'Service\\ContentPolicy',$ns.'Service\\ContentPolicy')->setArguments([$config['editor_max_text_length']]);
    $c->register($ns.'Service\\LegacySnapshotAdapter',$ns.'Service\\LegacySnapshotAdapter')->setArguments([$em,new Reference($ns.'Service\\AssetStore'),new Reference($ns.'Service\\BlockAdapterRegistry')]);
    $c->register($ns.'Service\\TemplateStateStore',$ns.'Service\\TemplateStateStore')->setArguments([$em,new Reference($ns.'Service\\LegacySnapshotAdapter'),new Reference($ns.'Service\\ContentPolicy'),new Reference($ns.'Service\\DocumentHtmlBuilder'),new Reference($ns.'Service\\BlockAdapterRegistry')])->setPublic(true);
    $c->register($ns.'Service\\BookletImposer',$ns.'Service\\BookletImposer');
    $c->register($ns.'Service\\DocumentHtmlBuilder',$ns.'Service\\DocumentHtmlBuilder')->setArguments([new Reference($ns.'Service\\LegacySnapshotAdapter'),$hook,new Reference($ns.'Service\\AssetStore'),new Reference('imanaging_conception_document.font_service'),new Reference('imanaging_conception_document.qr_code_service'),new Reference($ns.'Service\\BlockAdapterRegistry'),$config['editor_assets_path']]);
    $c->register($ns.'Service\\GotenbergRenderer',$ns.'Service\\GotenbergRenderer')->setArguments([new Reference($ns.'Service\\DocumentHtmlBuilder'),new Reference('http_client'),new Reference($ns.'Service\\BookletImposer'),$config['gotenberg_url'],$config['editor_output_path']])->setPublic(true);
    $c->setAlias($ns.'Contract\\DocumentRendererInterface',$ns.'Service\\GotenbergRenderer');
    $c->register($ns.'Service\\DocumentGateway',$ns.'Service\\DocumentGateway')->setArguments([new Reference($ns.'Service\\TemplateStateStore'),new Reference($ns.'Service\\LegacySnapshotAdapter'),new Reference($ns.'Service\\DocumentHtmlBuilder'),new Reference($ns.'Contract\\DocumentRendererInterface'),$config['editor_enabled']])->setPublic(true);
    $c->getDefinition('imanaging_conception_document.conception_document')->addMethodCall('setDocumentGateway',[new Reference($ns.'Service\\DocumentGateway')]);
    $c->register($ns.'Service\\DocumentArchive',$ns.'Service\\DocumentArchive')->setArguments([new Reference($ns.'Service\\AssetStore'),new Reference($ns.'Service\\ContentPolicy')])->setPublic(true);
    $c->getDefinition('imanaging_conception_document.conception_document_controller')->addMethodCall('setEditorStorage',[new Reference($ns.'Service\\DocumentArchive'),new Reference($ns.'Service\\TemplateStateStore'),new Reference($ns.'Service\\AssetStore'),$config['editor_enabled'],new Reference('security.token_storage')]);
    $c->register($ns.'Controller\\EditorController',$ns.'Controller\\EditorController')->setArguments([$em,new Reference($ns.'Service\\TemplateStateStore'),new Reference($ns.'Service\\LegacySnapshotAdapter'),new Reference($ns.'Service\\AssetStore'),new Reference($ns.'Service\\DocumentHtmlBuilder'),new Reference($ns.'Service\\GotenbergRenderer'),$hook,$config['editor_enabled'],$config['editor_role'],new Reference('imanaging_conception_document.field_provider',ContainerInterface::NULL_ON_INVALID_REFERENCE),new Reference($ns.'Service\\DocumentArchive')])->addTag('controller.service_arguments')->setAutowired(true)->setAutoconfigured(true)->setPublic(true);
    $c->register($ns.'Command\\InstallEditorStorageCommand',$ns.'Command\\InstallEditorStorageCommand')->setArguments([$em])->addTag('console.command');
    $c->register($ns.'Command\\MigrateTextStorageCommand',$ns.'Command\\MigrateTextStorageCommand')->setArguments([$em])->addTag('console.command');
    $c->register($ns.'EventSubscriber\\LegacyEditorSubscriber',$ns.'EventSubscriber\\LegacyEditorSubscriber')->setArguments([$em,new Reference($ns.'Service\\TemplateStateStore'),new Reference($ns.'Service\\LegacySnapshotAdapter'),new Reference('security.csrf.token_manager'),new Reference('security.authorization_checker'),$config['editor_enabled'],$config['editor_role'],$config['editor_assets_public_path']])->addTag('kernel.event_subscriber');
    $c->register($ns.'Command\\CleanupPreviewsCommand',$ns.'Command\\CleanupPreviewsCommand')->setArguments([$config['editor_output_path']])->addTag('console.command');
    $c->register($ns.'EditorTwigExtension',$ns.'EditorTwigExtension')->setArguments([$config['editor_enabled']])->addTag('twig.extension');
  }
  public function getAlias(): string
  {
    return 'imanaging_conception_document';
  }
}
