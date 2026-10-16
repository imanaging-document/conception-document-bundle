<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Command;

use Doctrine\DBAL\Types\{Type, Types};
use Doctrine\ORM\EntityManagerInterface;
use Imanaging\ConceptionDocumentBundle\Interfaces\ConceptionBlocInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateTextStorageCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct('conception-document:editor:migrate-text');
    }
    protected function configure(): void
    {
        $this->setDescription('Affiche la migration ciblée des textes documentaires (aucune autre table).')->addOption('apply', null, InputOption::VALUE_NONE, 'Exécuter les ALTER TABLE affichés.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $db = $this->em->getConnection();
        $manager = $db->createSchemaManager();
        $seen = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            if (!is_a($metadata->getName(), ConceptionBlocInterface::class, true) || !$metadata->hasField('texte')) {
                continue;
            }
            $table = $metadata->getTableName();
            $column = $metadata->getColumnName('texte');
            if (isset($seen[$table.':'.$column])) {
                continue;
            }
            $seen[$table.':'.$column] = true;
            $before = $manager->introspectTable($table);
            if ($before->getColumn($column)->getType() instanceof \Doctrine\DBAL\Types\TextType) {
                continue;
            }
            $after = clone $before;
            $after->modifyColumn($column, ['type' => Type::getType(Types::TEXT), 'length' => null]);
            foreach ($db->getDatabasePlatform()->getAlterTableSQL($manager->createComparator()->compareTables($before, $after)) as $sql) {
                $output->writeln($sql.';');
                if ($input->getOption('apply')) {
                    $db->executeStatement($sql);
                }
            }
        }
        $output->writeln($input->getOption('apply') ? '<info>Migration ciblée terminée.</info>' : '<info>Ajoutez --apply pour exécuter. Mettez également le mapping texte de l’hôte à type: text.</info>');
        return Command::SUCCESS;
    }
}
