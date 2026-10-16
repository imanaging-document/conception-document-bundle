<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conception_editor_state')]
class TemplateState
{
    #[ORM\Id]
    #[ORM\Column(name: 'template_key', type: 'string', length: 64)]
    public string $templateKey;
    #[ORM\Column(name: 'version', type: 'integer')]
    public int $version;
    #[ORM\Column(name: 'current_revision', type: 'string', length: 32)]
    public string $currentRevision;
    #[ORM\Column(name: 'projection_hash', type: 'string', length: 64)]
    public string $projectionHash;
}
