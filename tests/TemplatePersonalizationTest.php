<?php

namespace Imanaging\ConceptionDocumentBundle\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;
use Doctrine\ORM\EntityRepository;
use Imanaging\ConceptionDocumentBundle\Editor\Model\DocumentDefinition;
use Imanaging\ConceptionDocumentBundle\Editor\Model\RenderContext;
use Imanaging\ConceptionDocumentBundle\Editor\Service\AssetStore;
use Imanaging\ConceptionDocumentBundle\Editor\Service\BlockAdapterRegistry;
use Imanaging\ConceptionDocumentBundle\Editor\Service\DocumentHtmlBuilder;
use Imanaging\ConceptionDocumentBundle\Editor\Service\LegacySnapshotAdapter;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPersonnalisationServiceInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplatePersonnalisationServiceInterface;
use Imanaging\ConceptionDocumentBundle\Service\ConceptionFontService;
use Imanaging\ConceptionDocumentBundle\Service\ConceptionQrCodeService;
use Imanaging\ConceptionDocumentBundle\Twig\TwigFunctions;
use PHPUnit\Framework\TestCase;

class TemplatePersonalizationTest extends TestCase
{
  public static function renderingModes(): iterable
  {
    foreach (['bloc_texte', 'bloc_qrcode'] as $type) {
      foreach ([true, false] as $templateAware) {
        yield $type.($templateAware ? '-new' : '-legacy') => [$type, $templateAware];
      }
    }
  }

  /** @dataProvider renderingModes */
  public function testModernAndHistoricalRenderingPassTheTemplateWithLegacyFallback(string $type, bool $templateAware): void
  {
    $item = $this->createMock(ConceptionDocumentInterface::class);
    $template = $this->createMock(ConceptionTemplateInterface::class);
    $service = $this->createMock($templateAware
      ? ConceptionTemplatePersonnalisationServiceInterface::class
      : ConceptionPersonnalisationServiceInterface::class);
    $service->method('canShowElementConceptionTemplate')->willReturn(true);
    if ($templateAware) {
      $service->expects(self::exactly(2))->method('personnalizeTextForTemplate')
        ->with('{date_edition}', $item, $template)->willReturn('03/09/2026');
      $service->expects(self::never())->method('personnalizeText');
    } else {
      $service->expects(self::exactly(2))->method('personnalizeText')
        ->with('{date_edition}', $item)->willReturn('03/09/2026');
    }
    $qr = $this->createMock(ConceptionQrCodeService::class);
    if ($type === 'bloc_qrcode') {
      $qr->expects(self::once())->method('getDataUri')->with('03/09/2026')
        ->willReturn('data:image/svg+xml;base64,QR');
    }

    $builder = $this->builder($service, $qr);
    $html = $builder->build($this->definition($type), new RenderContext($item, new \DateTimeImmutable(), template: $template));
    self::assertStringContainsString($type === 'bloc_qrcode' ? 'data:image/svg+xml;base64,QR' : '03/09/2026', $html);
    $twig = new TwigFunctions($service, '/tmp', $this->createMock(ConceptionFontService::class), $qr);
    self::assertSame('03/09/2026', $twig->personnalizeText('{date_edition}', $item, $template));
  }

  public function testPdfGatewayForwardsTemplateAndReferenceDateForFrozenDefinitions(): void
  {
    $definition = $this->definition('bloc_texte');
    $item = $this->createMock(ConceptionDocumentInterface::class);
    $template = $this->createMock(ConceptionTemplateInterface::class);
    $date = new \DateTimeImmutable('2026-09-03');
    $db = $this->createMock(\Doctrine\DBAL\Connection::class);
    $db->method('fetchOne')->willReturn(json_encode($definition, JSON_THROW_ON_ERROR));
    $em = $this->createMock(EntityManagerInterface::class);
    $em->method('getConnection')->willReturn($db);
    $assets = new AssetStore('/tmp');
    $adapter = new LegacySnapshotAdapter($em, $assets);
    $builder = $this->builder($this->createMock(ConceptionPersonnalisationServiceInterface::class),
      $this->createMock(ConceptionQrCodeService::class));
    $store = new \Imanaging\ConceptionDocumentBundle\Editor\Service\TemplateStateStore($em, $adapter,
      new \Imanaging\ConceptionDocumentBundle\Editor\Service\ContentPolicy(), $builder);
    $renderer = $this->createMock(\Imanaging\ConceptionDocumentBundle\Editor\Contract\DocumentRendererInterface::class);
    $source = tempnam(sys_get_temp_dir(), 'template_source_');
    $target = tempnam(sys_get_temp_dir(), 'template_target_');
    file_put_contents($source, '%PDF-1.4 test');
    $renderer->expects(self::once())->method('render')
      ->with(self::callback(static fn ($d) => $d->hash() === $definition->hash()),
        self::callback(static fn (RenderContext $c) => $c->item === $item && $c->template === $template && $c->referenceDate === $date))
      ->willReturn(new \Imanaging\ConceptionDocumentBundle\Editor\Model\RenderResult($source, $source, 1, 1, 'test'));
    $gateway = new \Imanaging\ConceptionDocumentBundle\Editor\Service\DocumentGateway($store, $adapter, $builder, $renderer, true);
    try {
      $result = $gateway->generateAtPath($target, $item, $template,
        ['revision' => 'revision', 'templateKey' => 'key', 'hash' => $definition->hash()], $date);
      self::assertTrue($result['success'], $result['error_message'] ?? '');
      self::assertSame('%PDF-1.4 test', file_get_contents($target));
    } finally {
      foreach ([$source, $target] as $path) {
        if (is_file($path)) { unlink($path); }
      }
    }
  }

  private function builder(ConceptionPersonnalisationServiceInterface $service, ConceptionQrCodeService $qr): DocumentHtmlBuilder
  {
    $repository = $this->createMock(EntityRepository::class);
    $repository->method('getClassName')->willReturn(\stdClass::class);
    $em = $this->createMock(EntityManagerInterface::class);
    $em->method('getRepository')->willReturn($repository);
    $assets = new AssetStore('/tmp');
    return new DocumentHtmlBuilder(new LegacySnapshotAdapter($em, $assets), $service, $assets,
      $this->createMock(ConceptionFontService::class), $qr, new BlockAdapterRegistry(), '/tmp');
  }

  private function definition(string $blockType): DocumentDefinition
  {
    return new DocumentDefinition([
      'schemaVersion' => 1, 'label' => 'Test', 'mode' => 'free', 'fontResources' => '',
      'pageSpec' => ['format' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 0, 'right' => 0, 'bottom' => 0, 'left' => 0]],
      'printProfile' => ['mode' => 'simple'],
      'pages' => [['key' => 'page', 'label' => 'Page', 'blocks' => [[
        'key' => 'block', 'label' => 'Date', 'type' => $blockType, 'style' => [], 'text' => '{date_edition}',
      ]]]],
    ]);
  }
}
