<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conception_editor_draft')]
class Draft
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'string', length: 64)]
    public string $id;
    #[ORM\Column(name: 'template_key', type: 'string', length: 64)]
    public string $templateKey;
    #[ORM\Column(name: 'user_key', type: 'string', length: 64)]
    public string $userKey;
    #[ORM\Column(name: 'base_version', type: 'integer')]
    public int $baseVersion;
    #[ORM\Column(name: 'sequence', type: 'integer')]
    public int $sequence;
    #[ORM\Column(name: 'definition', type: 'text')]
    public string $definition;
    #[ORM\Column(name: 'updated_at', type: 'string', length: 32)]
    public string $updatedAt;
}
