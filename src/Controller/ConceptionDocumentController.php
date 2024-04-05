<?php

namespace Imanaging\ConceptionDocumentBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Imanaging\ConceptionDocumentBundle\ConceptionDocument;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocStyleInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocTypeInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionConditionInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionConditionTypeInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionDocumentInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionPageInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionTemplateTypeInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use ZipArchive;

class ConceptionDocumentController extends AbstractController
{
  private EntityManagerInterface $em;
  private ConceptionDocument $conceptionDocument;
  private Environment $twig;
  private string $uploadPath;
  private string $basePath;

  /**
   * MappingController constructor.
   * @param EntityManagerInterface $em
   * @param ConceptionDocument $conceptionDocument
   * @param Environment $twig
   * @param $basePath
   */
  public function __construct(EntityManagerInterface $em, ConceptionDocument $conceptionDocument, Environment $twig, $uploadPath, $basePath)
  {
    $this->em = $em;
    $this->conceptionDocument = $conceptionDocument;
    $this->twig = $twig;
    $this->uploadPath = $uploadPath;
    $this->basePath = $basePath;
  }

  /**
   * @return Response
   * @throws LoaderError
   * @throws RuntimeError
   * @throws SyntaxError
   */
  public function index(): Response
  {
    return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/index.html.twig", [
      'conceptions' => $this->em->getRepository(ConceptionTemplateInterface::class)->findAll(),
      'types' => $this->em->getRepository(ConceptionTemplateTypeInterface::class)->findAll(),
      'basePath' => $this->basePath
    ]));
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function add(Request $request): mixed
  {
    $params = $request->request->all();
    $type = $this->em->getRepository(ConceptionTemplateTypeInterface::class)->find($params['type']);
    if ($type instanceof ConceptionTemplateTypeInterface){
      $className = $this->em->getRepository(ConceptionTemplateInterface::class)->getClassName();
      $template = new $className();
      if ($template instanceof ConceptionTemplateInterface){
        $template->setLibelle($params['libelle']);
        $template->setType($type);
        $template->setActif(false);
        $this->em->persist($template);
        $this->em->flush();
        $this->addFlash('success', 'Nouvelle conception ajoutée avec succès.');
      } else {
        $this->addFlash('error', 'Impossible de créer une nouvelle conception');
      }
    } else {
      $this->addFlash('error', 'Type de conception introuvable : '.$params['type']);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $id
   * @return mixed
   */
  public function remove($id): mixed
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      try {
        $this->em->remove($template);
        $this->em->flush();
        $this->addFlash('success', 'Conception supprimée avec succès.');
      } catch (\Exception $e){
        $this->addFlash('error', 'Impossible de supprimer cette conception : '.$e->getMessage());
      }
    } else {
      $this->addFlash('error', 'Impossible de supprimer cette conception : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  public function uploadYaml($id)
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $imagesPathToZip = [];
      $pages = [];
      foreach ($template->getPages() as $page){
        $blocs = [];
        if ($page instanceof ConceptionPageInterface){
          foreach ($page->getBlocs() as $bloc){
            $styles = [];
            foreach ($bloc->getStyles() as $style){
              if ($style instanceof ConceptionBlocStyleInterface){
                $styles[] = [
                  'code' => $style->getCode(),
                  'nom' => $style->getNom(),
                  'style' => $style->getStyle(),
                ];
              }
            }

            $conditions = [];
            foreach ($bloc->getConditions() as $condition){
              if ($condition instanceof ConceptionConditionInterface){
                $conditions[] = [
                  'type_condition' => $condition->getTypeCondition(),
                  'type_comparaison' => $condition->getTypeComparaison(),
                  'valeur_comparaison' => $condition->getValeurComparaison()
                ];
              }
            }

            $formattedBloc = [
              'libelle' => $bloc->getLibelle(),
              'type' => $bloc->getType()->getCode(),
              'ordre' => $bloc->getOrdre(),
              'styles' => $styles,
              'conditions' => $conditions,
            ];
            if ($bloc->getType()->getCode() == 'bloc_image'){
              $formattedBloc['filename'] = basename($bloc->getPath());
              $filePath = $this->uploadPath.$bloc->getPath();
              if (file_exists($filePath)){
                $imagesPathToZip[] = $filePath;
              } else {
                $this->addFlash('error', 'Image introuvable : '.$filePath);
                return $this->redirectToRoute('hephaistos_administration_quittance_conception');
              }
            } elseif($bloc->getType()->getCode() == 'formes_predefinies'){
              $formattedBloc['type_forme'] = $bloc->getTypeForme();
            } elseif($bloc->getType()->getCode() == 'bloc_texte'){
              $formattedBloc['texte'] = $bloc->getTexte();
              $formattedBloc['mode_raw'] = $bloc->isModeRaw();
            }
            $blocs[] = $formattedBloc;
          }

          $conditions = [];
          foreach ($page->getConditions() as $condition){
            if ($condition instanceof ConceptionConditionInterface){
              $conditions[] = [
                'type_condition' => $condition->getTypeCondition(),
                'type_comparaison' => $condition->getTypeComparaison(),
                'valeur_comparaison' => $condition->getValeurComparaison()
              ];
            }
          }

          $pages[] = [
            'libelle' => $page->getLibelle(),
            'blocs' => $blocs,
            'conditions' => $conditions,
          ];
        }
      }

      $yamlContent = [
        'libelle' => $template->getLibelle(),
        'pages' => $pages
      ];

      $yamlUploadsDir = $this->uploadPath.'/yaml-upload/'.$template->getId().'/';
      if (!is_dir($yamlUploadsDir)){
        mkdir($yamlUploadsDir, 0755, true);
      }

      $now = new \DateTime();
      $zip = new ZipArchive;
      $zipPath = $yamlUploadsDir.$now->format('YmdHis').'_conception.zip';
      if ($zip->open($zipPath, ZipArchive::CREATE) === TRUE) {
        $zip->addFromString('conception.yaml', Yaml::dump($yamlContent));
        foreach ($imagesPathToZip as $path){
          $zip->addFile($path, basename($path));
        }
        $zip->close();
        return $this->file($zipPath, $now->format('YmdHis').'_conception.zip');
      } else {
        $this->addFlash('error', 'Une erreur est survenue lors de la création du fichier ZIP !');
        return $this->redirectToRoute('conception_document');
      }
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
      return $this->redirectToRoute('conception_document');
    }
  }

  public function showImportModal($id)
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/modals/import-yaml.html.twig", [
        'template' => $template
      ]));
    } else {
      return $this->json(['error_message' => 'Template introuvable : '.$id], 500);
    }
  }

  public function importYaml($id, Request $request)
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $files = $request->files->all();
      $fichier = $files['fichier'];
      if ($fichier instanceof UploadedFile){
        $now = new \DateTime();
        $zip = new ZipArchive;
        $downloadsDir = $this->uploadPath.'/yaml-download/'.$template->getId().'/'.$now->format('YmdHis').'/';
        if (!is_dir($downloadsDir)){
          mkdir($downloadsDir, 0755, true);
        }
        if ($zip->open($fichier) === TRUE) {
          $zip->extractTo($downloadsDir);
          $zip->close();

          $yamlFile = $downloadsDir.'conception.yaml';
          if (file_exists($yamlFile)){
            // Ici le fichier ZIP est correctement dézippé
            $data = Yaml::parseFile($yamlFile);
            foreach ($template->getPages() as $page){
              if ($page instanceof ConceptionPageInterface){
                foreach ($page->getBlocs() as $bloc){
                  if ($bloc instanceof ConceptionBlocInterface){
                    foreach ($bloc->getStyles() as $style){
                      $this->em->remove($style);
                    }
                    foreach ($bloc->getConditions() as $condition){
                      $this->em->remove($condition);
                    }
                    $this->em->remove($bloc);
                  }
                }
                foreach ($page->getConditions() as $condition){
                  $this->em->remove($condition);
                }
                $this->em->remove($page);
              }
            }

            $ordre = 0;
            foreach ($data['pages'] as $formattedPage){
              $imagesRelativeDir = '/template-'.$template->getId(). '/page-'.$ordre.'/images/';
              $imagesDir = $this->uploadPath . $imagesRelativeDir;
              if (!is_dir($imagesDir)) {
                mkdir($imagesDir, 0755, true);
              }

              $ordre++;
              $className = $this->em->getRepository(ConceptionPageInterface::class)->getClassName();
              $page = new $className();
              $page->setTemplate($template);
              $page->setOrdre($ordre);
              $page->setLibelle($formattedPage['libelle']);
              $this->em->persist($page);
              foreach ($formattedPage['blocs'] as $formattedBloc){
                // Temporaire pour switch de version
                $codeType = str_replace('bloc_forme', 'formes_predefinies', $formattedBloc['type']);
                $type = $this->em->getRepository(ConceptionBlocTypeInterface::class)->findOneBy(['code' => $codeType]);
                if (!($type instanceof ConceptionBlocTypeInterface)){
                  $this->addFlash('error', 'Type de bloc non géré dans cette application: '.$formattedBloc['type']);
                  return $this->redirectToRoute('conception_document');
                }
                $className = $type->getEntity();
                $bloc = new $className();
                $bloc->setPage($page);
                $bloc->setType($type);
                $bloc->setLibelle($formattedBloc['libelle']);
                $bloc->setOrdre($formattedBloc['ordre']);
                if($codeType == 'bloc_image'){
                  $filePath = $downloadsDir.$formattedBloc['filename'];
                  if (file_exists($filePath)){
                    $destinationFilepath = $imagesDir.$formattedBloc['filename'];
                    if (copy($filePath, $destinationFilepath)){
                      $bloc->setPath($imagesRelativeDir.$formattedBloc['filename']);
                    } else {
                      $this->addFlash('error', 'Une erreur est survenue lors de la copie de l\'image : '.$filePath);
                      return $this->redirectToRoute('conception_document');
                    }
                  } else {
                    $this->addFlash('error', 'Une erreur est survenue lors du déplacement d\'une image : '.$filePath);
                    return $this->redirectToRoute('conception_document');
                  }
                } elseif($codeType == 'formes_predefinies'){
                  $bloc->setTypeForme($formattedBloc['type_forme']);
                } elseif($codeType == 'bloc_texte'){
                  $bloc->setTexte($formattedBloc['texte']);
                  $bloc->setModeRaw($formattedBloc['mode_raw']);
                }

                foreach($formattedBloc['styles'] as $formattedStyle){
                  $className = $this->em->getRepository(ConceptionBlocStyleInterface::class)->getClassName();
                  $style = new $className($bloc, $formattedStyle['code'], $formattedStyle['nom'], $formattedStyle['style']);
                  $this->em->persist($style);
                }
                foreach ($formattedBloc['conditions'] as $formattedCondition){
                  $className = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
                  $condition = new $className();
                  $condition->setBloc($bloc);
                  $condition->setTypeCondition($formattedCondition['type_condition']);
                  $condition->setTypeComparaison($formattedCondition['type_comparaison']);
                  $condition->setValeurComparaison($formattedCondition['valeur_comparaison']);
                  $this->em->persist($condition);
                }
                $this->em->persist($bloc);
              }

              foreach ($formattedPage['conditions'] as $formattedCondition){
                $className = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
                $condition = new $className();
                $condition->setPage($page);
                $condition->setTypeCondition($formattedCondition['type_condition']);
                $condition->setTypeComparaison($formattedCondition['type_comparaison']);
                $condition->setValeurComparaison($formattedCondition['valeur_comparaison']);
                $this->em->persist($condition);
              }
            }

            $this->em->flush();
            $this->addFlash('success', 'Conception chargée avec succès !');
          } else {
            $this->addFlash('error', 'Le fichier YAML est introuvable dans le fichier ZIP : '.$yamlFile);
            return $this->redirectToRoute('conception_document');
          }
        } else {
          $this->addFlash('error', 'Impossible de dézipper le fichier fournis !');
          return $this->redirectToRoute('conception_document');
        }
      }
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $id
   * @return Response
   */
  public function showSearchEntityForm($id): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      return $this->conceptionDocument->showSearchEntityForm($template->getType());
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $id
   * @return mixed
   */
  public function selectEntity($id): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/select_entity.html.twig", [
        'template' => $template,
        'basePath' => $this->basePath
      ]));
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $id
   * @param Request $request
   * @return mixed
   */
  public function validateEntitySelection($id, Request $request): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $params = $request->request->all();
      $targetEntity = $this->em->getRepository($template->getType()->getTargetEntity())->find($params['id']);
      if (is_null($targetEntity)){
        $this->addFlash('error', 'Item de personnalisation introuvable pour la classe '.$template->getType()->getTargetEntity().' : '.$params['id']);
        return $this->redirectToRoute('conception_document_select_entity', ['id' => $params['id']]);
      }
      return $this->redirectToRoute('conception_document_conception_tool', ['id' => $id, 'entityId' => $params['id'], 'pageNumber' => 1]);
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
      return $this->redirectToRoute('conception_document');
    }
  }

  /**
   * @param $id
   * @param $entityId
   * @param $pageNumber
   * @return mixed
   * @throws LoaderError
   * @throws RuntimeError
   * @throws SyntaxError
   */
  public function conceptionTool($id, $entityId, $pageNumber): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $page = $this->em->getRepository(ConceptionPageInterface::class)->findOneBy(['template' => $template, 'ordre' => $pageNumber]);
      if (!($page instanceof ConceptionPageInterface)){
        if ($this->em->getRepository(ConceptionPageInterface::class)->count(['template' => $template]) == 0){
          $className = $this->em->getRepository(ConceptionPageInterface::class)->getClassName();
          $page = new $className();
          $page->setTemplate($template);
          $page->setOrdre($pageNumber);
          $page->setLibelle('Page '.$pageNumber);
          $this->em->persist($page);
          $this->em->flush();
        } else {
          $this->addFlash('error', 'Page introuvable : '.$pageNumber);
          return $this->redirectToRoute('conception_document');
        }
      }
      return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/conception.html.twig", [
        'page' => $page,
        'entity_id' => $entityId,
        'basePath' => $this->basePath
      ]));
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $id
   * @param $entityId
   * @return mixed
   */
  public function addPage($id, $entityId): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $ordre = $this->em->getRepository(ConceptionPageInterface::class)->count(['template' => $template]) + 1;
      $className = $this->em->getRepository(ConceptionPageInterface::class)->getClassName();
      $page = new $className();
      $page->setTemplate($template);
      $page->setOrdre($ordre);
      $page->setLibelle('Page '.$ordre);
      $this->em->persist($page);
      $this->em->flush();
      return $this->redirectToRoute('conception_document_conception_tool', ['id' => $id, 'entityId' => $entityId, 'pageNumber' => $ordre]);
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
      return $this->redirectToRoute('conception_document');
    }
  }

  /**
   * @param $entityId
   * @param $id
   * @return mixed
   */
  public function removePage($entityId, $id): Response
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($id);
    if ($page instanceof ConceptionPageInterface){
      $templateId = $page->getTemplate()->getId();
      $pageNumber = $page->getOrdre();
      try {
        $this->em->remove($page);
        $this->em->flush();
        $this->addFlash('success', 'Page supprimée avec succès');
        $pageNumber = 1;
      }catch (Exception $e){
        $this->addFlash('error', 'Une erreur est survenue lors de la tentative de suppression de la page '.$pageNumber.' : '.$e->getMessage());
      }
      return $this->redirectToRoute('conception_document_conception_tool', ['id' => $templateId, 'entityId' => $entityId, 'pageNumber' => $pageNumber]);
    } else {
      $this->addFlash('error', 'Page introuvable : '.$id);
      return $this->redirectToRoute('conception_document');
    }
  }

  /**
   * @param $id
   * @param $entityId
   * @param $pageNumber
   * @return mixed
   * @throws LoaderError
   * @throws RuntimeError
   * @throws SyntaxError
   */
  public function pagePreview($id, $entityId, $pageNumber): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $page = $this->em->getRepository(ConceptionPageInterface::class)->findOneBy(['template' => $template, 'ordre' => $pageNumber]);
      if ($page instanceof ConceptionPageInterface){
        $conceptionDocument = $this->em->getRepository($template->getType()->getTargetEntity())->find($entityId);
        if ($conceptionDocument instanceof ConceptionDocumentInterface){
          return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/document.html.twig", [
            'page' => $page,
            'entity_id' => $entityId,
            'conception_document' => $conceptionDocument,
            'preshow' => true,
            'basePath' => $this->basePath,
          ]));
        } else {
          $this->addFlash('error', $template->getType()->getTargetEntity().' introuvable : '.$entityId);
        }
      } else {
        $this->addFlash('error', 'Page introuvable : '.$pageNumber);
      }
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $id
   * @param $entityId
   * @param $pageNumber
   * @return mixed
   * @throws LoaderError
   * @throws RuntimeError
   * @throws SyntaxError
   */
  public function gestionDesBlocs($id, $entityId, $pageNumber): Response
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $page = $this->em->getRepository(ConceptionPageInterface::class)->findOneBy(['template' => $template, 'ordre' => $pageNumber]);
      if ($page instanceof ConceptionPageInterface){
        $className = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
        return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/gestion-des-blocs.html.twig", [
          'page' => $page,
          'entity_id' => $entityId,
          'types_bloc' => $this->em->getRepository(ConceptionBlocTypeInterface::class)->findAll(),
          'types_conditions' => $className::getTypesConditions(),
          'types_comparaisons' => $className::getTypesComparaisons(),
          'preshow' => true,
          'basePath' => $this->basePath,
        ]));
      } else {
        $this->addFlash('error', 'Page introuvable : '.$pageNumber);
      }
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $pageId
   * @param $entityId
   * @param Request $request
   * @return mixed
   */
  public function addBloc($pageId, $entityId, Request $request): Response
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($pageId);
    if ($page instanceof ConceptionPageInterface){
      $params = $request->request->all();

      $typeBloc = $this->em->getRepository(ConceptionBlocTypeInterface::class)->findOneBy(['code' => $params['type_bloc']]);
      if ($typeBloc instanceof ConceptionBlocTypeInterface){
        $className = $typeBloc->getEntity();
        $bloc = new $className();
        $bloc->setPage($page);
        $bloc->setType($typeBloc);
        switch ($params['type_bloc']){
          case 'bloc_image':
            $files = $request->files->all();
            $image = $files['image'];
            if ($image instanceof UploadedFile){
              $relativeDir = '/template-'.$page->getTemplate()->getId(). '/page-'.$page->getId().'/images/';
              $imagesDir = $this->uploadPath . $relativeDir;
              if (!is_dir($imagesDir)) {
                mkdir($imagesDir, 0755, true);
              }

              $fileName = $image->getClientOriginalName();
              if ($image->move($imagesDir, $fileName)){
                $bloc->setPath($relativeDir.$fileName);
              } else {
                $this->addFlash('error', 'Une erreur est survenue lors du déplacement de l\'image dans le dossier de destination');
                return $this->redirectToRoute('conception_document_conception_tool', ['id' => $page->getTemplate()->getId(), 'entityId' => $entityId, 'pageNumber' => $page->getOrdre()]);
              }
            } else {
              $this->addFlash('error', 'Veuillez sélectionner une image.');
              return $this->redirectToRoute('conception_document_conception_tool', ['id' => $page->getTemplate()->getId(), 'entityId' => $entityId, 'pageNumber' => $page->getOrdre()]);
            }
            break;
          case 'bloc_texte':
            $bloc->setTexte($params['texte']);
            $bloc->setModeRaw(true);
            break;
          case 'formes_predefinies':
            $bloc->setTypeForme($params['forme_predefinie']);
            break;
        }
        $bloc->setLibelle($params['libelle']);
        $this->em->persist($bloc);

        $stylesToCreate = $bloc->getStylesToCreate();
        foreach ($stylesToCreate as $style){
          $this->em->persist($style);
        }
        $this->em->flush();
        return $this->redirectToRoute('conception_document_conception_tool', ['id' => $page->getTemplate()->getId(), 'entityId' => $entityId, 'pageNumber' => $page->getOrdre()]);
      } else {
        $this->addFlash('error', 'Type de bloc introuvable : '.$params['type_bloc']);
      }
    } else {
      $this->addFlash('error', 'Page introuvable : '.$pageId);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $pageId
   * @param $entityId
   * @param $blocId
   * @return mixed
   */
  public function removeBloc($pageId, $entityId, $blocId): Response
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($pageId);
    if ($page instanceof ConceptionPageInterface){
      $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($blocId);
      if (!is_null($bloc)){
        try {
          foreach ($bloc->getStyles() as $style){
            $this->em->remove($style);
          }
          $this->em->remove($bloc);
          $this->em->flush();
          $this->addFlash('success', 'Bloc supprimé avec succès');
        }catch (Exception $e){
          $this->addFlash('error', 'Une erreur est survenue lors de la tentative de suppression de ce bloc : '.$e->getMessage());
        }
      } else {
        $this->addFlash('error', 'Bloc introuvable : '.$blocId);
      }

      return $this->redirectToRoute('conception_document_conception_tool', ['id' => $page->getTemplate()->getId(), 'entityId' => $entityId, 'pageNumber' => $page->getOrdre()]);
    } else {
      $this->addFlash('error', 'Page introuvable : '.$pageId);
    }
    return $this->redirectToRoute('conception_document');
  }

  /**
   * @param $pageId
   * @param $entityId
   * @param $blocId
   * @return mixed
   */
  public function duplicateBloc($pageId, $entityId, $blocId): Response
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($pageId);
    if ($page instanceof ConceptionPageInterface){
      $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($blocId);
      if (!is_null($bloc)){
        $newBloc = clone $bloc;
        $newBloc->setLibelle($bloc->getLibelle().' - Dupliqué');
        $this->em->persist($newBloc);
        foreach ($bloc->getStyles() as $style){
          $newStyle = clone $style;
          $newStyle->setBloc($newBloc);
          $this->em->persist($newStyle);
        }
        $this->em->flush();
      } else {
        $this->addFlash('error', 'Bloc introuvable : '.$blocId);
      }
      return $this->redirectToRoute('conception_document_conception_tool', ['id' => $page->getTemplate()->getId(), 'entityId' => $entityId, 'pageNumber' => $page->getOrdre()]);
    } else {
      $this->addFlash('error', 'Page introuvable : '.$pageId);
    }
    return $this->redirectToRoute('conception_document');
  }
  
  public function loadTypeBlocPartial(Request $request): Response
  {
    $params = $request->request->all();
    switch ($params['typeBloc']){
      case 'bloc_texte':
        return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/partials/bloc-generique/bloc-texte.html.twig"));
      case 'bloc_image':
        return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/partials/bloc-generique/bloc-image.html.twig"));
      case 'formes_predefinies':
        return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/partials/bloc-generique/bloc-forme.html.twig"));
      default:
        return new Response();
    }
  }

  /**
   * @param $pageId
   * @param Request $request
   * @return mixed
   */
  public function saveBlocsOrder($pageId, Request $request): Response
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($pageId);
    if ($page instanceof ConceptionPageInterface){
      $params = $request->request->all();
      foreach ($params as $blocId => $ordre){
        $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($blocId);
        if (!is_null($bloc)){
          $bloc->setOrdre($ordre);
          $this->em->persist($bloc);
          $style = $bloc->getStyleByCode('root');
          if ($style instanceof ConceptionBlocStyleInterface){
            $actualStyle = $style->getDecodedStyle();
            $actualStyle['z-index'] = $bloc->getOrdre();
            $style->setStyle(json_encode($actualStyle));
            $this->em->persist($style);
          }
        }
      }
      $this->em->flush();
      return $this->json([]);
    } else {
      return $this->json(['error_message' => 'Page introuvable : '.$pageId], 500);
    }
  }

  /**
   * @param Request $request
   * @return mixed
   */
  public function saveBlocPosition(Request $request): Response
  {
    $params = $request->request->all();
    $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($params['bloc_id']);
    if ($bloc instanceof ConceptionBlocInterface){
      $rootStyle = $bloc->getStyleByCode('root');
      if ($rootStyle instanceof ConceptionBlocStyleInterface){
        $properties = json_decode($rootStyle->getStyle(), true);
        $properties['left'] = $params['x'].'mm';
        $properties['top'] = $params['y'].'mm';
        if (isset($params['height'])){
          if (!in_array($params['height'], ['auto'])){
            $properties['height'] = $params['height'].'mm';
          } else {
            $properties['height'] = $params['height'];
          }
        }
        if (isset($params['width'])){
          if (!in_array($params['width'], ['auto'])){
            $properties['width'] = $params['width'].'mm';
          } else {
            $properties['width'] = $params['width'];
          }
        }
        if (isset($params['opacity'])){
          $properties['opacity'] = $params['opacity'];
        }
        if (isset($params['font_size'])){
          $properties['font-size'] = $params['font_size'];
        }
        $rootStyle->setStyle(json_encode($properties));
        $this->em->persist($rootStyle);
        $this->em->flush();
        return $this->json([
          'x'=> $rootStyle->getPropertyValue('left'),
          'y'=> $rootStyle->getPropertyValue('top'),
          'height'=> $rootStyle->getPropertyValue('height'),
          'width'=> $rootStyle->getPropertyValue('width'),
          'opacity'=> $rootStyle->getPropertyValue('opacity'),
          'font-size'=> $rootStyle->getPropertyValue('font-size'),
        ]);
      } else {
        return $this->json(['error_message' => 'Style introuvable : '.$params['bloc_id']], 500);
      }
    } else {
      return $this->json(['error_message' => 'Bloc introuvable : '.$params['bloc_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function loadBlocEdition($id, $entityId)
  {
    $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($id);
    if ($bloc instanceof ConceptionBlocInterface){
      $className = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
      return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/partials/infos_bloc.html.twig", [
        'bloc' => $bloc,
        'entity_id' => $entityId,
        'types_conditions' => $className::getTypesConditions(),
        'types_comparaisons' => $className::getTypesComparaisons(),
      ]));
    } else {
      return $this->json(['error_message' => 'Bloc introuvable : '.$params['bloc_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function saveBlocLibelle(Request $request)
  {
    $params = $request->request->all();
    $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($params['bloc_id']);
    if ($bloc instanceof ConceptionBlocInterface){
      $bloc->setLibelle($params['libelle']);
      $this->em->persist($bloc);
      $this->em->flush();
      return $this->json([]);
    } else {
      return $this->json(['error_message' => 'Bloc introuvable : '.$params['bloc_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function saveBlocText(Request $request)
  {
    $params = $request->request->all();
    $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($params['bloc_id']);
    if ($bloc instanceof ConceptionBlocInterface){
      if ($bloc->getType()->getCode() == 'bloc_texte'){
        $bloc->setTexte($params['texte']);
        $this->em->persist($bloc);
        $this->em->flush();
        return $this->json([]);
      } else {
        return $this->json(['error_message' => 'Pas un bloc texte : '.$params['bloc_id']], 500);
      }
    } else {
      return $this->json(['error_message' => 'Bloc introuvable : '.$params['bloc_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function saveBlocModeRaw(Request $request)
  {
    $params = $request->request->all();
    $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($params['bloc_id']);
    if ($bloc instanceof ConceptionBlocInterface){
      if ($bloc->getType()->getCode() == 'bloc_texte'){
        $bloc->setModeRaw($params['mode_raw'] == 'true');
        $this->em->persist($bloc);
        $this->em->flush();
        return $this->json([]);
      } else {
        return $this->json(['error_message' => 'Pas un bloc texte : '.$params['bloc_id']], 500);
      }
    } else {
      return $this->json(['error_message' => 'Bloc introuvable : '.$params['bloc_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function saveBlocStyle(Request $request)
  {
    $params = $request->request->all();
    $style = $this->em->getRepository(ConceptionBlocStyleInterface::class)->find($params['style_id']);
    if ($style instanceof ConceptionBlocStyleInterface){
      $style->setStyle($params['json_properties']);
      $this->em->persist($style);
      $this->em->flush();
      return $this->json(['page_number' => $style->getBloc()->getPage()->getOrdre()]);
    } else {
      return $this->json(['error_message' => 'Style introuvable : '.$params['style_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function saveMultiBlocs(Request $request)
  {
    $params = $request->request->all();
    $nbMmToMove = (float)$params['nb_mm_to_move'];
    if ($nbMmToMove > 0){
      foreach ($params['blocs_ids'] as $blocId){
        $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($blocId);
        if ($bloc instanceof ConceptionBlocInterface){
          $rootStyle = $bloc->getStyleByCode('root');
          if ($rootStyle instanceof ConceptionBlocStyleInterface){
            switch ($params['type_action']){
              case 'deplacer_haut':
                $y = $rootStyle->getPropertyValue('top');
                $newY = (float)$y - $nbMmToMove;
                $actualStyle = $rootStyle->getDecodedStyle();
                $actualStyle['top'] = $newY.'mm';
                $rootStyle->setStyle(json_encode($actualStyle));
                $this->em->persist($rootStyle);
                break;
              case 'deplacer_bas':
                $y = $rootStyle->getPropertyValue('top');
                $newY = (float)$y + $nbMmToMove;
                $actualStyle = $rootStyle->getDecodedStyle();
                $actualStyle['top'] = $newY.'mm';
                $rootStyle->setStyle(json_encode($actualStyle));
                $this->em->persist($rootStyle);
                break;
              case 'deplacer_droite':
                $x = $rootStyle->getPropertyValue('left');
                $newX = (float)$x + $nbMmToMove;
                $actualStyle = $rootStyle->getDecodedStyle();
                $actualStyle['left'] = $newX.'mm';
                $rootStyle->setStyle(json_encode($actualStyle));
                $this->em->persist($rootStyle);
                break;
              case 'deplacer_gauche':
                $x = $rootStyle->getPropertyValue('left');
                $newX = (float)$x - $nbMmToMove;
                $actualStyle = $rootStyle->getDecodedStyle();
                $actualStyle['left'] = $newX.'mm';
                $rootStyle->setStyle(json_encode($actualStyle));
                $this->em->persist($rootStyle);
                break;
            }
          } else {
            return $this->json(['error_message' => 'Le style root est introuvable pour le bloc : '.$blocId], 500);
          }
        } else {
          return $this->json(['error_message' => 'Bloc introuvable : '.$blocId], 500);
        }
      }
      $this->em->flush();
      return $this->json([]);
    } else {
      return $this->json(['error_message' => 'Veuillez saisir une valeur en millimètre cohérente : '.$params['nb_mm_to_move']], 500);
    }
  }

  /**
   * @return Response
   */
  public function addConditionToBloc($id, $entityId, Request $request)
  {
    $bloc = $this->em->getRepository(ConceptionBlocInterface::class)->find($id);
    if ($bloc instanceof ConceptionBlocInterface){
      $params = $request->request->all();
      $className = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
      $condition = new $className();
      $condition->setBloc($bloc);
      $condition->setTypeCondition($params['type_condition']);
      $condition->setTypeComparaison($params['type_comparaison']);
      $condition->setValeurComparaison($params['valeur_comparaison']);
      $this->em->persist($condition);
      $this->em->flush();
      return $this->redirectToRoute('conception_document_conception_tool', [
        'id' => $bloc->getPage()->getTemplate()->getId(),
        'entityId' => $entityId,
        'pageNumber' => $bloc->getPage()->getOrdre()
      ]);
    } else {
      return $this->redirectToRoute('hephaistos_homepage');
    }
  }

  /**
   * @return Response
   */
  public function addConditionToPage($id, $entityId, Request $request)
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($id);
    if ($page instanceof ConceptionPageInterface){
      $params = $request->request->all();
      $className = $this->em->getRepository(ConceptionConditionInterface::class)->getClassName();
      $condition = new $className();
      $condition->setPage($page);
      $condition->setTypeCondition($params['type_condition']);
      $condition->setTypeComparaison($params['type_comparaison']);
      $condition->setValeurComparaison($params['valeur_comparaison']);
      $this->em->persist($condition);
      $this->em->flush();
      return $this->redirectToRoute('conception_document_conception_tool', [
        'id' => $page->getTemplate()->getId(),
        'entityId' => $entityId,
        'pageNumber' => $page->getOrdre()
      ]);
    } else {
      return $this->redirectToRoute('hephaistos_homepage');
    }
  }

  /**
   * @return Response
   */
  public function removeCondition($id, $entityId)
  {
    $condition = $this->em->getRepository(ConceptionConditionInterface::class)->find($id);
    if ($condition instanceof ConceptionConditionInterface){
      if (!is_null($condition->getPage())){
        $templateId = $condition->getPage()->getTemplate()->getId();
        $pageId = $condition->getPage()->getOrdre();
      } elseif (!is_null($condition->getBloc())){
        $templateId = $condition->getBloc()->getPage()->getTemplate()->getId();
        $pageId = $condition->getBloc()->getPage()->getOrdre();
      } else {
        return $this->redirectToRoute('hephaistos_homepage');
      }
      try {
        $this->em->remove($condition);
        $this->em->flush();
      } catch (Exception $e){
        $this->addFlash('error', 'Impossible de supprimer cette condition : '.$e->getMessage());
      }
      return $this->redirectToRoute('conception_document_conception_tool', [
        'id' => $templateId,
        'entityId' => $entityId,
        'pageNumber' => $pageId
      ]);
    } else {
      return $this->redirectToRoute('hephaistos_homepage');
    }
  }

  public function checkPageIntegrity($id)
  {
    $page = $this->em->getRepository(ConceptionPageInterface::class)->find($id);
    if ($page instanceof ConceptionPageInterface){
      $anomalies = [];
      // CHECK SUR LA PAGE
      if ($page->getLibelle() == ''){
        $anomalies[] = 'Cette page ne possède pas de nom';
      }
      if ($page->getOrdre() == 0){
        $anomalies[] = 'Cette page ne possède pas d\'ordre (0)';
      }
      if (count($page->getBlocs()) == 0){
        $anomalies[] = 'Cette page ne possède pas encore de blocs';
      }
      // CHECK SUR LES BLOCS
      $blocsOrdres = [];
      foreach ($page->getBlocs() as $bloc){
        if ($bloc instanceof ConceptionBlocInterface){
          if ($bloc->getOrdre() == 0){
            $anomalies[] = '<b>Bloc #'.$bloc->getId().'</b> : Ce bloc ne possède pas d\'ordre (0)';
          }
          if (in_array($bloc->getOrdre(), $blocsOrdres)){
            $anomalies[] = '<b>Bloc #'.$bloc->getId().'</b> : Ce bloc possède le même ordre qu\'un autre bloc ! ('.$bloc->getOrdre().')';
          }
          if ($bloc->getLibelle() == ''){
            $anomalies[] = '<b>Bloc #'.$bloc->getId().'</b> : Ce bloc ne possède pas de nom !';
          }
          $blocsOrdres[] = $bloc->getOrdre();

          if ($bloc->getType()->getCode() == 'bloc_texte'){
            if ($bloc->getTexte() == ''){
              $anomalies[] = '<b>Bloc #'.$bloc->getId().'</b> : Ce bloc texte ne possède pas de "texte"';
            }
          }
          if ($bloc->getType()->getCode() == 'bloc_image'){
            $filePath = $this->uploadPath.$bloc->getPath();
            if (!file_exists($filePath)){
              $anomalies[] = '<b>Bloc #'.$bloc->getId().'</b> : image introuvable '.$filePath;
            }
          }

          // CHECK SUR LES STYLES DE CE BLOC
          foreach ($bloc->getStyles() as $style){
            if ($style instanceof ConceptionBlocStyleInterface){
              foreach ($style->getDecodedStyle() as $property => $value){
                if ($value == 'NaNmm'){
                  $anomalies[] = '<b>Bloc '.$bloc->getLibelle().'</b> : Le style "'.$property.'" possède une valeur non autorisée : '.$value;
                }
                switch ($property){
                  case 'width':
                    if ((strpos($value, 'px') === false) && (strpos($value, 'mm') === false) && (strpos($value, 'em') === false) && (strpos($value, 'auto') === false)){
                      $anomalies[] = '<b>Bloc '.$bloc->getLibelle().'</b> : Le style "'.$property.'" ne possède pas une valeur recommandée (px, mm, em ou auto)';
                    }
                    break;
                }
              }
            }
          }
        }
      }

      return new Response($this->twig->render("@ImanagingConceptionDocument/ConceptionDocument/partials/check-integrite.html.twig", [
        'anomalies' => $anomalies
      ]));
    } else {
      return $this->json(['error_message' => 'Bloc introuvable : '.$params['bloc_id']], 500);
    }
  }

  /**
   * @return Response
   */
  public function generatePdf($id, $entityId)
  {
    $template = $this->em->getRepository(ConceptionTemplateInterface::class)->find($id);
    if ($template instanceof ConceptionTemplateInterface){
      $targetEntity = $this->em->getRepository($template->getType()->getTargetEntity())->find($entityId);
      if ($targetEntity instanceof ConceptionDocumentInterface){
        $res = $this->conceptionDocument->genererPdf($targetEntity, $template, true);
        if ($res['success']){
          return $this->file($res['filepath'], strtolower($template->getType()->getLibelle()).'_document_test.pdf');
        } else {
          $this->addFlash('error', $res['error_message']);
        }
        return $this->redirectToRoute('conception_document_conception_tool', [
          'id' => $templateId,
          'entityId' => $entityId,
          'pageNumber' => $pageId
        ]);
      } else {
        $this->addFlash('error', 'Entité introuvable : '.$entityId);
      }
    } else {
      $this->addFlash('error', 'Template introuvable : '.$id);
    }
    return $this->redirectToRoute('hephaistos_homepage');
  }
}