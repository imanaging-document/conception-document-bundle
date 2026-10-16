<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'conception_editor_revision')]
#[ORM\Index(name: 'conception_editor_revision_template', columns: ['template_key', 'version'])]
class Revision
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'string', length: 32)]
    public string $id;
    #[ORM\Column(name: 'template_key', type: 'string', length: 64)]
    public string $templateKey;
    #[ORM\Column(name: 'version', type: 'integer')]
    public int $version;
    #[ORM\Column(name: 'definition', type: 'text')]
    public string $definition;
    #[ORM\Column(name: 'created_at', type: 'string', length: 32)]
    public string $createdAt;
}
