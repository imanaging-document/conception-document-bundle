<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Imanaging\ConceptionDocumentBundle\Editor\Entity\{TemplateState,Draft,Revision};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class InstallEditorStorageCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct('conception-document:editor:install');
    }
    protected function configure(): void
    {
        $this->setDescription('Crée uniquement les tables absentes de brouillons et versions documentaires.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manager = $this->em->getConnection()->createSchemaManager();
        $missing = [];
        foreach ([TemplateState::class,Draft::class,Revision::class] as $class) {
            $meta = $this->em->getClassMetadata($class);
            if (!$manager->tablesExist([$meta->getTableName()])) {
                $missing[] = $meta;
            }
        }
        if ($missing) {
            (new SchemaTool($this->em))->createSchema($missing);
        }
        $output->writeln('<info>Stockage des brouillons et versions disponible.</info>');
        return Command::SUCCESS;
    }
}
