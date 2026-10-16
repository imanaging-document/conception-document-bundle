# Éditeur documentaire HTML

## Installation dans une application Symfony

L’éditeur est livré sous forme de sources dans `assets/editor`. L’application consommatrice installe les dépendances JavaScript avec Yarn et les compile avec Webpack Encore. Les fichiers générés restent dans `public/build`, hors Git. Le bundle utilise les interfaces Doctrine existantes ; aucune dépendance à `App\Entity` n’est ajoutée.

```yaml
imanaging_conception_document:
    editor_enabled: true
    editor_role: ROLE_USER # à remplacer par le rôle autorisé de l’hôte
    editor_output_path: '%kernel.project_dir%/var/conception-document/previews'
    editor_assets_path: '%kernel.project_dir%/public/build/conception-document'
    editor_assets_public_path: '/build/conception-document'
    gotenberg_url: 'http://gotenberg:3000'
    editor_max_text_length: 1000000
```

### Compilation avec Webpack Encore 4 / Webpack 5

Installer Composer avant Yarn : les entrées JavaScript se trouvent dans `vendor/`. Ajouter au `package.json` de l’application les dépendances de la section `dependencies` du `package.json` du bundle, ainsi que `typescript: ^5.9.0` et `ts-loader: ^9.5.0` dans `devDependencies`. Conserver et versionner le `yarn.lock` de l’application. PDF.js 6.4 demande Node.js >= 22.13 dans l’environnement de compilation ; Node.js n’est pas nécessaire au rendu PHP.

Avant `module.exports = Encore.getWebpackConfig()`, ajouter :

```js
require('./vendor/imanaging-document/conception-document-bundle/webpack.config.cjs')(Encore);
```

Ce configurateur ajoute l’entrée `conception-document-editor`, active TypeScript avec le `tsconfig.json` du bundle et copie Paged.js, le script historique et les polices dans `public/build/conception-document`. Le worker PDF.js est émis comme fichier séparé par Webpack via `new URL(..., import.meta.url)`. Le renderer PHP lit Paged.js et les polices depuis `editor_assets_path` puis les incorpore au HTML autonome ; Gotenberg n’a pas besoin d’accéder au serveur Web ni à `node_modules`.

Créer `templates/bundles/ImanagingConceptionDocumentBundle/Editor/editor.html.twig` dans l’application :

```twig
{% extends '@!ImanagingConceptionDocument/Editor/editor.html.twig' %}

{% block editor_stylesheets %}
    {{ encore_entry_link_tags('conception-document-editor') }}
{% endblock %}

{% block editor_javascripts %}
    {{ encore_entry_script_tags('conception-document-editor') }}
{% endblock %}
```

Ces fonctions chargent aussi le runtime et les chunks partagés d’Encore. L’éditeur reste sur une page dédiée pour isoler ses styles des feuilles globales de l’application. Le bundle laisse ces deux blocs libres pour les applications utilisant un autre outil de compilation.

```sh
composer install
yarn install --frozen-lockfile
yarn encore production
```

L’image Docker de HephaistosEnquete effectue déjà ces trois étapes dans cet ordre. Déployer tout `public/build`, dont `conception-document/paged.js`, `conception-document/legacy.js`, les polices, les chunks et le worker PDF.js. Une compilation uniquement en mémoire avec webpack-dev-server ne suffit pas pour les ressources lues par PHP ; utiliser `encore dev --watch` pour ce parcours.

Après installation et compilation :

```sh
php bin/console conception-document:editor:install
php bin/console conception-document:editor:migrate-text
# Après lecture du SQL ciblé :
php bin/console conception-document:editor:migrate-text --apply
php bin/console assets:install public
php bin/console cache:clear
```

Le mapping `texte` des sous-classes de blocs de l’hôte doit être `type: text`, sans `length: 2048`. La commande de migration affiche d’abord le SQL et ne touche que ces colonnes. La commande `install` crée uniquement les trois tables documentaires absentes. Aucun `doctrine:schema:update --force` général n’est nécessaire.

Dans Enquête, utiliser le lanceur habituel sélectionnant le client (`CustomKernel`) pour ces commandes. Les tables et la colonne de texte de l’environnement local de recette ont été installées. Les migrations SQL d’Attestation et de Quittance restent à appliquer lors de leur mise à jour du bundle ; leurs mappings sont préparés.

## Utilisation

Le bouton « Ouvrir le nouvel éditeur » se trouve dans la conception avancée. La route est `conception_document_editor`, paramètres `id` et `entityId`.

- Un clic sélectionne un bloc, un double-clic ouvre son texte. Ctrl/Cmd + clic ajoute un bloc à la sélection, sur une même page.
- La barre de mise en forme agit sur la sélection de texte lorsqu’elle existe, sinon sur le bloc. Les tableaux disposent d’un panneau de lignes, colonnes, fusions et styles de cellule.
- Les positions sont stockées en millimètres. Les alignements prennent le dernier bloc sélectionné comme référence, ou la page/ses marges. Les répartitions demandent trois blocs. Les blocs verrouillés sont exclus des déplacements.
- Les guides magnétiques ont une tolérance de six pixels à l’écran, convertie selon le zoom ; ils proposent aussi des espacements égaux. La grille s’affiche indépendamment des guides.
- « Image », le dépôt sur la feuille et « Remplacer l’image » utilisent le stockage par empreinte SHA-256. Un remplacement garde le cadre, les proportions du redimensionnement, le cadrage et l’ordre d’affichage. Les SVG sont limités aux éléments graphiques statiques, sans DTD, scripts, références externes ou `<use>`.
- Le brouillon se sauvegarde après une seconde d’inactivité et en fin de manipulation. « Enregistrer le modèle » publie une version complète en transaction et conserve les identifiants existants et l’état actif/inactif du modèle.
- Ctrl/Cmd + Z, Ctrl/Cmd + Maj + Z et Ctrl/Cmd + Y parcourent l’historique commun. Ctrl/Cmd + S enregistre le modèle.
- Un conflit suspend la sauvegarde automatique. Le brouillon peut être exporté dans « Import / export ». Les versions se restaurent dans un brouillon avant publication.

Un ancien HTML non représentable par Tiptap reste inchangé et visible ; son édition passe par le mode avancé. Le chargement ou l’aperçu ne sérialisent jamais le HTML avec Tiptap. Le HTML avancé continue d’être stocké comme HTML.

## Documents longs et livret

« Blocs libres » conserve les pages explicites. « Corps paginé » déroule les blocs dans la zone utile ; sélectionner un bloc puis « Écrire » ouvre un DOM source complet séparé du rendu paginé. Les en-têtes/pieds répétés se placent dans les marges réservées. `{page_number}` et `{page_total}` produisent des compteurs dans ce mode. Les sauts de page explicites, titres solidaires du paragraphe suivant, veuves/orphelines et en-têtes de tableaux sont pris en charge par le CSS commun et Paged.js.

A4 et A3 ont les deux orientations. Les anciennes conceptions A4 sont interprétées en portrait, les A3 en paysage. Un changement de format conserve la taille physique des blocs. Les débordements sont signalés et empêchent un PDF libre tronqué ; aucune réduction automatique du texte n’est appliquée.

Le livret compose des pages A4 portrait en ordre de lecture. FPDI/TCPDF importe ensuite les pages vectorielles sur des faces A3 paysage, avec blancs ajoutés au multiple de quatre. Pour quatre pages : `4 | 1`, puis `2 | 3`. La rotation du verso est enregistrée dans le profil. Chaque destinataire est imposé avant la fusion des lots. Une feuille recto/verso réelle doit être vérifiée sur l’imprimante cible avant envoi.

## Rendu de référence

« Vérifier le PDF » transmet le brouillon courant à Gotenberg, sans le publier. PDF.js affiche l’artefact renvoyé ; le lien de téléchargement pointe vers ce même fichier. Toute modification marque l’aperçu obsolète ; une réponse ancienne est ignorée. Un échec du moteur laisse le brouillon disponible.

Le HTML autonome contient les images et les polices embarquées. Le document s’exécute dans une iframe isolée des styles de l’hôte. Le moteur attend `document.fonts.ready`, le décodage des images et la fin de Paged.js. Le contenu édité n’exécute pas de script ; la CSP autorise uniquement le script documentaire avec nonce.

Profil validé : Gotenberg **8.27.0**, Chromium **145.0.7632.109**. Le digest est enregistré dans `src/Resources/render-profile.json` et dans `docker/compose.gotenberg.yaml`. Le renderer vérifie `/version` avant conversion. Utiliser l’image épinglée du profil pour conserver aussi le même Chromium ; une autre version nécessite une nouvelle recette visuelle et un nouveau profil.

Les fichiers PDF temporaires sont privés, accessibles par un jeton lié à la session, à l’utilisateur et à la conception pendant 24 heures. Prévoir un lancement quotidien de :

```sh
php bin/console conception-document:editor:cleanup-previews --apply
```

Cette commande ne supprime jamais de ressource source, de brouillon ou de version. Les images immuables sont conservées : aucune collecte automatique susceptible de casser une version ou un traitement n’est activée.

## Services publics et hôtes

- `DocumentDefinition` contient la définition versionnée, le HTML, les conditions, les ressources, le format et le profil d’impression.
- `DocumentRendererInterface::render(definition, context)` renvoie les PDF lecture/impression, les nombres de pages et l’empreinte du rendu.
- `DocumentGateway` est utilisé par `ConceptionDocument`. Les méthodes publiques historiques restent disponibles ; `generatePdfAtPath` accepte aussi une version figée et une date de référence.
- Les sous-classes applicatives qui surchargeaient Gotenberg peuvent devenir des façades de compatibilité, à condition d’appeler `setDocumentGateway` sur leur définition de service. Enquête utilise cette façade.
- Les adaptateurs métier implémentent `BlockTypeAdapterInterface` (snapshot, validation, application, rendu) et sont automatiquement tagués `conception_document.block_adapter`. Sans adaptateur dédié, le rendu métier historique de l’hôte reste utilisé, avec ses styles internes conservés.
- Un fournisseur `PersonalizationFieldProviderInterface` peut être exposé sous l’alias `imanaging_conception_document.field_provider`. Il peut aussi implémenter `BlockPresetProviderInterface`. Les champs et préréglages Enquête se trouvent dans l’application, pas dans le bundle.

Les variantes de police utilisées sont figées dans chaque version. Un adaptateur métier recevant `RenderContext` doit utiliser sa date de référence plutôt que l’horloge courante pour les valeurs datées. Le contexte ne prétend pas figer les données métier vivantes du destinataire ; le traitement conserve séparément ses propres données de personnalisation.

### Traitements par jalon

Au lancement, `freezeTemplate()` produit `{templateKey, revision, hash}`. Enquête le stocke dans `template_versions`, avec `document_reference_date`, dans les données du traitement. Chaque destinataire et chaque reprise réutilisent ces références. La modification ultérieure du modèle ne change pas un lot lancé. Une reprise d’un traitement antérieur dépourvu de références figées demande une régénération complète.

### Mode avancé et archives

Les écritures du contrôleur historique et de ses sous-classes passent dans une transaction englobant la projection Doctrine et la création de version. Le script `legacy.js` ajoute CSRF et révisions aux formulaires/AJAX et convertit les anciens liens de mutation en POST. Les intégrations appelant ces routes directement doivent fournir `X-Document-CSRF` et `X-Document-Revisions` (ou les champs de formulaire correspondants). Les révisions courantes reviennent dans l’en-tête `X-Document-Revisions`.

Les exports ZIP/YAML v2 incluent la définition, les polices et les images par empreinte. L’import accepte aussi les anciens exports. Les fichiers sont lus sans extraction de chemins fournis par le ZIP. Le nouvel éditeur importe dans un brouillon ; l’action d’import historique conserve sa publication explicite, désormais versionnée et transactionnelle.

## Développement et validation

Dans le projet hôte, `yarn encore dev` vérifie aussi les types de l’éditeur. Vérifier ensuite la page de l’éditeur, son aperçu PDF et le mode paginé avec Gotenberg.

Pour travailler sur les sources seules dans le dépôt du bundle :

```sh
npm ci
npm run typecheck
```

Il n’existe plus de build Vite séparé. Les ressources compilées ne sont pas versionnées dans le bundle.

Les images insérées utilisent une dimension automatique : largeur fixe et hauteur naturelle, ou hauteur fixe et largeur naturelle. Le réglage « Dimensions » permet aussi d’ajuster un ancien cadre. Modifier une dimension numérique fixe celle-ci et rend l’autre automatique ; le redimensionnement conserve ce choix. Le cadre fixe reste disponible pour le recadrage et les fonds de page. Les anciennes versions conservent leur géométrie jusqu’à une modification explicite.

Dans Enquête, `tests/Integration/Service/ConceptionEditorLocalTest.php` utilise la base locale explicitement autorisée par `ENQUETE_LOCAL_RECIPE=1`, annule les mutations et ne déclenche aucun envoi. `CONCEPTION_RENDER_RECIPE=1` active les conversions réelles A4/A3, pagination, livrets 1/4/5/8 pages et rendu depuis une version figée.

La recette imprimante, le corpus visuel complet des anciens modèles et les parcours de génération propres à Attestation/Quittance restent des étapes de déploiement. Le nouvel éditeur est désactivé par défaut dans le bundle ; l’activer d’abord dans Enquête sur des copies inactives.
