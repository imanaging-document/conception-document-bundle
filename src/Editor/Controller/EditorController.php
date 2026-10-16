<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Editor\Service\{TemplateStateStore,LegacySnapshotAdapter,AssetStore,DocumentHtmlBuilder,GotenbergRenderer,DocumentArchive};
use Imanaging\ConceptionDocumentBundle\Editor\Contract\PersonalizationFieldProviderInterface;
use Imanaging\ConceptionDocumentBundle\Editor\Model\{DocumentDefinition,RenderContext};
use Imanaging\ConceptionDocumentBundle\Interfaces\{ConceptionTemplateInterface,ConceptionDocumentInterface,ConceptionPersonnalisationServiceInterface};
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{Request,Response,JsonResponse,BinaryFileResponse,ResponseHeaderBag};

final class EditorController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em, private readonly TemplateStateStore $store, private readonly LegacySnapshotAdapter $adapter, private readonly AssetStore $assets, private readonly DocumentHtmlBuilder $html, private readonly GotenbergRenderer $renderer, private readonly ConceptionPersonnalisationServiceInterface $personalization, private readonly bool $enabled, private readonly string $role, private readonly ?PersonalizationFieldProviderInterface $fields = null, private readonly ?DocumentArchive $archive = null)
    {
    }
    public function handle(int $id, int $entityId, string $action, Request $request): Response
    {
        if (!$this->enabled) {
            throw $this->createNotFoundException('Éditeur non activé.');
        }$this->denyAccessUnlessGranted($this->role);
        $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
        if (!$template) {
            throw $this->createNotFoundException('Conception introuvable.');
        }
        if ($request->isMethod('POST') && !$this->isCsrfTokenValid('conception_editor_'.$id, $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['message' => 'Session expirée. Rechargez la page.'], 403);
        }
        $write = in_array($action, ['draft','publish','restore','image','html','preview','import'], true);
        if ($write && !$request->isMethod('POST')) {
            return $this->json(['message' => 'Méthode POST requise.'], 405);
        }
        try {
            $user = $this->getUser()->getUserIdentifier();
            if ($action === 'open') {
                return $this->render('@ImanagingConceptionDocument/Editor/editor.html.twig', ['template' => $template,'entityId' => $entityId,'csrf' => $this->container->get('security.csrf.token_manager')->getToken('conception_editor_'.$id)->getValue()]);
            }
            if ($action === 'document') {
                $data = $this->store->document($template, $user);
                $data['fields'] = $this->fields ? $this->fields->fields($template) : [];
                $data['presets'] = $this->fields instanceof \Imanaging\ConceptionDocumentBundle\Editor\Contract\BlockPresetProviderInterface ? $this->fields->presets($template) : [];
                $data['fields'][] = ['token' => '{page_number}','label' => 'Numéro de page (corps paginé)'];
                $data['fields'][] = ['token' => '{page_total}','label' => 'Nombre de pages (corps paginé)'];
                $data['fonts'] = $this->html->fontFamilies();
                return $this->json($data);
            }
            if ($action === 'versions') {
                return $this->json($this->store->versions($template));
            }
            if ($action === 'draft') {
                return $this->json($this->store->saveDraft($template, $user, $request->toArray()));
            }
            if ($action === 'publish') {
                $payload = $request->toArray();
                if (!isset($payload['expectedSequence'])) {
                    throw new \DomainException('Révision de brouillon requise.');
                }
                return $this->json($this->store->publish($template, $user, $payload));
            }
            if ($action === 'restore') {
                $payload = $request->toArray();
                return $this->json($this->store->restore($template, $user, $payload['revision'] ?? '', (int)($payload['baseVersion'] ?? 0), (int)($payload['sequence'] ?? 0)));
            }
            if ($action === 'export' && $request->isMethod('POST')) {
                $payload = $request->toArray();
                $definition = new DocumentDefinition($payload['definition'] ?? []);
                $this->store->validateExport($template, $definition, (int)($payload['baseVersion'] ?? 0));
                return $this->file($this->archive->write($definition), 'brouillon-conception.zip')->deleteFileAfterSend(true);
            }
            if ($action === 'export') {
                $file = $this->archive->write($request->query->getBoolean('draft') ? new DocumentDefinition($this->store->document($template, $user)['definition']) : $this->store->current($template));
                return $this->file($file, 'conception.zip')->deleteFileAfterSend(true);
            }
            if ($action === 'import') {
                $file = $request->files->get('archive');
                if (!$file) {
                    throw new \DomainException('Sélectionnez une archive ZIP.');
                }
                return $this->json($this->store->importAsDraft($template, $user, $this->archive->read($file->getPathname()), $request->request->getInt('baseVersion'), $request->request->getInt('sequence')));
            }
            if ($action === 'image') {
                $file = $request->files->get('image');
                if (!$file) {
                    throw new \DomainException('Sélectionnez une image.');
                }$asset = $this->assets->importFile($file->getPathname());
                return $this->json($asset);
            }
            if ($action === 'asset') {
                $asset = $request->query->get('asset', '');
                $bytes = $this->assets->read($asset);
                $ext = pathinfo($asset, PATHINFO_EXTENSION);
                $mime = ['png' => 'image/png','jpg' => 'image/jpeg','webp' => 'image/webp','svg' => 'image/svg+xml'][$ext];
                return new Response($bytes, 200, ['Content-Type' => $mime,'Cache-Control' => 'private, max-age=3600','X-Content-Type-Options' => 'nosniff','Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox"]);
            }
            if ($action === 'artifact') {
                $all = $request->getSession()->get('conception_editor_artifacts', []);
                $token = $request->query->get('token', '');
                $entry = $all[$token] ?? null;
                if (!$entry || $entry['template'] !== $id || ($entry['user'] ?? '') !== hash('sha256', $user) || $entry['created'] < time() - 86400) {
                    throw $this->createNotFoundException('Aperçu expiré.');
                }
                $path = $entry[$request->query->get('view') === 'print' ? 'printing' : 'reading'];
                $response = new BinaryFileResponse($path);
                $response->headers->set('Cache-Control', 'private, no-store');
                $response->setContentDisposition($request->query->getBoolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE, 'conception.pdf');
                return $response;
            }
            if (in_array($action, ['html','preview'], true)) {
                $payload = $request->toArray();
                $definition = DocumentDefinition::fromArray($payload['definition'] ?? []);
                $this->store->validateDefinition($template, $definition);
                $target = $template->getType()->getTargetEntity();
                $item = $this->em->getMetadataFactory()->isTransient($target) ? $this->personalization->getCustomConceptionDocument($template->getType(), $entityId) : $this->em->getRepository($target)->find($entityId);
                if (!$item instanceof ConceptionDocumentInterface) {
                    throw new \DomainException('Item de personnalisation introuvable.');
                }
                $context = new RenderContext($item, new \DateTimeImmutable());
                if ($action === 'html') {
                    return new Response($this->html->build($definition, $context, true), 200, ['Content-Type' => 'text/html; charset=UTF-8','Cache-Control' => 'private, no-store']);
                }
                $result = $this->renderer->render($definition, $context);
                $token = bin2hex(random_bytes(24));
                $all = $request->getSession()->get('conception_editor_artifacts', []);
                $all = array_filter($all, fn ($e) => $e['created'] >= time() - 86400);
                $all[$token] = ['template' => $id,'user' => hash('sha256', $user),'reading' => $result->readingPdf,'printing' => $result->printingPdf,'created' => time()];
                $request->getSession()->set('conception_editor_artifacts', $all);
                $url = $this->generateUrl('conception_document_editor_api', ['id' => $id,'entityId' => $entityId,'action' => 'artifact','token' => $token]);
                return $this->json(['readingUrl' => $url,'printingUrl' => $url.'&view=print','logicalPages' => $result->logicalPages,'physicalPages' => $result->physicalPages,'fingerprint' => $result->fingerprint,'definitionHash' => $definition->hash()]);
            }
            throw $this->createNotFoundException();
        } catch (\UnexpectedValueException $e) {
            return $this->json(['message' => $e->getMessage()], 409);
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->json(['message' => $e->getMessage()], 422);
        } catch (\Doctrine\DBAL\Exception\TableNotFoundException $e) {
            return $this->json(['message' => 'Installez les tables : conception-document:editor:install.'], 503);
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface|\RuntimeException $e) {
            return $this->json(['message' => 'Le rendu ou le stockage est indisponible. '.$e->getMessage()], 503);
        }
    }
}
