<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\EventSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Controller\ConceptionDocumentController;
use Imanaging\ConceptionDocumentBundle\Editor\Service\{TemplateStateStore, LegacySnapshotAdapter};
use Imanaging\ConceptionDocumentBundle\Interfaces\{ConceptionTemplateInterface,ConceptionPageInterface,ConceptionBlocInterface,ConceptionBlocStyleInterface,ConceptionConditionInterface};
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\{ControllerEvent,ResponseEvent,ExceptionEvent};
use Symfony\Component\HttpKernel\Exception\{AccessDeniedHttpException,ConflictHttpException,MethodNotAllowedHttpException};
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\{CsrfToken,CsrfTokenManagerInterface};

/** Historical writes and immutable revisions commit together, including writes by host subclasses. */
final class LegacyEditorSubscriber implements EventSubscriberInterface
{
    private const WRITES = ['add','remove','toggleActif','saveTemplateFormat','importYaml','addPage','removePage','addBloc','importBackgroundAssisted','removeBloc','duplicateBloc','saveBlocsOrder','saveBlocPosition','saveBlocLock','saveBlocCheckboxState','saveBlocLibelle','saveBlocText','saveBlocImage','saveBlocModeRaw','saveBlocStyle','saveMultiBlocs','addConditionToBloc','addConditionToPage','removeCondition'];

    public function __construct(private readonly EntityManagerInterface $em, private readonly TemplateStateStore $store, private readonly LegacySnapshotAdapter $adapter, private readonly CsrfTokenManagerInterface $csrf, private readonly AuthorizationCheckerInterface $authorization, private readonly bool $enabled, private readonly string $role, private readonly string $assetsPublicPath)
    {
    }
    public static function getSubscribedEvents(): array
    {
        return ['kernel.controller' => ['before',5],'kernel.response' => ['after',5],'kernel.exception' => ['failed',100]];
    }

    public function before(ControllerEvent $event): void
    {
        $controller = $event->getController();
        $request = $event->getRequest();
        if (!$this->enabled || !$event->isMainRequest() || !is_array($controller) || !$controller[0] instanceof ConceptionDocumentController) {
            return;
        }
        $request->attributes->set('_document_legacy', true);
        $action = $controller[1];
        $automaticPage = false;
        if ($action === 'conceptionTool') {
            $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($request->attributes->get('id'));
            $automaticPage = $template && $template->getPages()->isEmpty();
        }
        if ((!in_array($action, self::WRITES, true) && !$automaticPage) || ($action === 'remove' && !$request->isMethod('POST'))) {
            return;
        }
        if (!$automaticPage && !$request->isMethod('POST')) {
            throw new MethodNotAllowedHttpException(['POST'], 'Écriture documentaire : méthode POST requise.');
        }
        if (!$this->authorization->isGranted($this->role) || (!$automaticPage && !$this->csrf->isTokenValid(new CsrfToken('conception_editor_legacy', $request->headers->get('X-Document-CSRF', $request->request->get('_document_csrf', '')))))) {
            throw new AccessDeniedHttpException('Session documentaire expirée. Rechargez la page.');
        }
        $templates = $this->affected($action, $request->attributes->all(), $request->request->all());
        $db = $this->em->getConnection();
        $db->beginTransaction();
        $request->attributes->set('_document_transaction', true);
        $expected = json_decode($request->headers->get('X-Document-Revisions', $request->request->get('_document_revisions', '{}')), true) ?? [];
        $ids = [];
        foreach ($templates as $template) {
            $existing = $this->store->hasState($template);
            $state = $this->store->state($template);
            $id = $template->getId();
            if (!$automaticPage && $existing && ($expected[$id] ?? null) !== (int)$state['version']) {
                throw new ConflictHttpException('Cette conception a changé dans un autre onglet. Rechargez avant de modifier.');
            }
            $ids[] = $id;
        }
        $request->attributes->set('_document_template_ids', $ids);
    }

    public function after(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->attributes->get('_document_legacy')) {
            return;
        }
        if ($request->attributes->get('_document_transaction')) {
            try {
                if ($event->getResponse()->getStatusCode() >= 400) {
                    $this->rollback($request);
                    return;
                }
                $this->em->clear();
                foreach ($request->attributes->get('_document_template_ids', []) as $id) {
                    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
                    if ($template) {
                        $this->store->resetLegacy($template);
                    }
                }
                $this->em->getConnection()->commit();
                $request->attributes->set('_document_transaction', false);
            } catch (\Throwable $e) {
                $this->rollback($request);
                throw $e;
            }
        }
        $states = [];
        if ($this->em->getConnection()->createSchemaManager()->tablesExist(['conception_editor_state'])) {
            $versions = $this->em->getConnection()->fetchAllKeyValue('SELECT template_key, version FROM conception_editor_state');
            foreach ($this->em->getRepository(ConceptionTemplateInterface::class)->findAll() as $template) {
                $key = $this->adapter->templateKey($template);
                $states[$template->getId()] = (int)($versions[$key] ?? 0);
            }
        }
        $response = $event->getResponse();
        $response->headers->set('X-Document-Revisions', json_encode($states, JSON_THROW_ON_ERROR));
        $contentType = $response->headers->get('Content-Type', '');
        if (!str_contains($contentType, 'text/html') && !($contentType === '' && str_starts_with(ltrim($response->getContent()), '<'))) {
            return;
        }
        $token = json_encode($this->csrf->getToken('conception_editor_legacy')->getValue(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $json = json_encode($states, JSON_THROW_ON_ERROR);
        $legacyUrl = htmlspecialchars($request->getBasePath().'/'.trim($this->assetsPublicPath, '/').'/legacy.js', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $script = '<script>window.documentEditorLegacy={csrf:'.$token.',revisions:'.$json.'};</script><script src="'.$legacyUrl.'"></script>';
        $content = $response->getContent();
        $response->setContent(str_contains($content, '</body>') ? str_replace('</body>', $script.'</body>', $content) : $content.$script);
    }
    public function failed(ExceptionEvent $event): void
    {
        $this->rollback($event->getRequest());
    }
    private function rollback($request): void
    {
        if ($request->attributes->get('_document_transaction')) {
            $this->em->getConnection()->rollBack();
            $request->attributes->set('_document_transaction', false);
            $this->em->clear();
        }
    }
    private function affected(string $action, array $attributes, array $payload): array
    {
        $entities = [];
        $id = $attributes['id'] ?? null;
        if (in_array($action, ['remove','toggleActif','saveTemplateFormat','importYaml','addPage','conceptionTool'], true)) {
            $entities[] = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
        } elseif (isset($attributes['pageId'])) {
            $entities[] = $this->em->getRepository(ConceptionPageInterface::class)->find($attributes['pageId']);
        } elseif (in_array($action, ['removePage','addConditionToPage'], true)) {
            $entities[] = $this->em->getRepository(ConceptionPageInterface::class)->find($id);
        } elseif ($action === 'addConditionToBloc') {
            $entities[] = $this->em->getRepository(ConceptionBlocInterface::class)->find($id);
        } elseif ($action === 'removeCondition') {
            $entities[] = $this->em->getRepository(ConceptionConditionInterface::class)->find($id);
        } elseif (isset($payload['style_id'])) {
            $entities[] = $this->em->getRepository(ConceptionBlocStyleInterface::class)->find($payload['style_id']);
        } else {
            foreach ($payload['blocs_ids'] ?? [$payload['bloc_id'] ?? null] as $blocId) {
                if ($blocId) {
                    $entities[] = $this->em->getRepository(ConceptionBlocInterface::class)->find($blocId);
                }
            }
        }
        $templates = [];
        foreach ($entities as $entity) {
            if ($entity instanceof ConceptionConditionInterface) {
                $entity = $entity->getPage() ?? $entity->getBloc();
            }
            if ($entity instanceof ConceptionBlocStyleInterface) {
                $entity = $entity->getBloc();
            }
            if ($entity instanceof ConceptionBlocInterface) {
                $entity = $entity->getPage();
            }
            if ($entity instanceof ConceptionPageInterface) {
                $entity = $entity->getTemplate();
            }
            if ($entity instanceof ConceptionTemplateInterface) {
                $templates[$entity->getId()] = $entity;
            }
        }
        return array_values($templates);
    }
}
