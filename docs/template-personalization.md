# Template-aware personalization

Consumers can optionally implement `ConceptionTemplatePersonnalisationServiceInterface` to receive the current template in `personnalizeTextForTemplate(string $text, ConceptionDocumentInterface $document, ConceptionTemplateInterface $template): string`.

PDF generation, HTML/editor previews, text blocks and QR codes forward the template. `RenderContext` accepts it as an optional final argument (`template: $template`). Historical Twig calls may pass the template as the third argument of `personnalizeText`.

The historical interface and two-argument Twig calls are unchanged. Consumers implementing only `ConceptionPersonnalisationServiceInterface` continue to receive `personnalizeText($text, $document)`. All domain-specific field and date rules belong to the consuming application.

Run `tests/TemplatePersonalizationTest.php` with PHPUnit and the consuming application's Composer autoloader, with this version of the bundle installed.
