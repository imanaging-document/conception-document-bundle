<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Service;

use Imanaging\ConceptionDocumentBundle\Editor\Contract\DocumentRendererInterface;
use Imanaging\ConceptionDocumentBundle\Editor\Model\{DocumentDefinition,RenderContext,RenderResult};
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

final class GotenbergRenderer implements DocumentRendererInterface
{
    public function __construct(private readonly DocumentHtmlBuilder $html, private readonly HttpClientInterface $http, private readonly BookletImposer $imposer, private readonly string $url, private readonly string $outputPath)
    {
    }
    public function render(DocumentDefinition $definition, RenderContext $context): RenderResult
    {
        $profile = json_decode(file_get_contents(__DIR__.'/../../Resources/render-profile.json'), true, 512, JSON_THROW_ON_ERROR);
        $version = trim($this->http->request('GET', rtrim($this->url, '/').'/version', ['timeout' => 5])->getContent());
        if ($version !== $profile['gotenberg']) {
            throw new \RuntimeException('Version de Gotenberg différente du profil validé (attendue : '.$profile['gotenberg'].', reçue : '.$version.').');
        }
        $html = $this->html->build($definition, $context);
        $normalized = preg_replace('/nonce-[a-zA-Z0-9+\/=]+|nonce="[a-zA-Z0-9+\/=]+"/', 'nonce', $html);
        $fingerprint = hash('sha256', $normalized.$context->referenceDate->format(DATE_ATOM).$context->dataFingerprint.$profile['id']);
        $dir = rtrim($this->outputPath, '/').'/editor';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Répertoire PDF inaccessible.');
        }
        // Unique artefacts: never overwrite an in-flight viewer or share personalized files by URL.
        $id = bin2hex(random_bytes(16));
        $reading = $dir.'/'.$id.'.pdf';
        $printing = $reading;
        $form = new FormDataPart(['files' => new DataPart($html, 'index.html', 'text/html'),'printBackground' => 'true','preferCssPageSize' => 'true','marginTop' => '0','marginBottom' => '0','marginLeft' => '0','marginRight' => '0','scale' => '1','emulatedMediaType' => 'print','failOnConsoleExceptions' => 'true','waitForExpression' => 'window.__documentReady === true || Boolean(window.__documentError)']);
        $response = $this->http->request('POST', rtrim($this->url, '/').'/forms/chromium/convert/html', ['headers' => $form->getPreparedHeaders()->toArray(),'body' => $form->bodyToIterable(),'timeout' => 60]);
        $bytes = $response->getContent(false);
        if ($response->getStatusCode() !== 200 || !str_starts_with($bytes, '%PDF-')) {
            if ($response->getStatusCode() === 409 && preg_match('/Document invalide : ([^\r\n]+)/u', $bytes, $match)) {
                throw new \DomainException(trim($match[1]));
            }
            throw new \RuntimeException('Gotenberg : '.substr($bytes, 0, 300));
        }
        if (file_put_contents($reading, $bytes) === false) {
            throw new \RuntimeException('Écriture du PDF impossible.');
        }
        $reader = new \setasign\Fpdi\Tcpdf\Fpdi();
        $logical = $reader->setSourceFile($reading);
        $physical = $logical;
        if ($definition->isBooklet()) {
            $printing = $dir.'/'.$id.'-print.pdf';
            $physical = $this->imposer->impose($reading, $printing, (bool)($definition->data['printProfile']['rotateBack'] ?? false));
        }
        return new RenderResult($reading, $printing, $logical, $physical, $fingerprint);
    }
}
