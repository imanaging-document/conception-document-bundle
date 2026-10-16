<?php

namespace Imanaging\ConceptionDocumentBundle\Editor\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface,InputOption};
use Symfony\Component\Console\Output\OutputInterface;

/** Removes only temporary preview artefacts, never source assets, drafts or immutable revisions. */
final class CleanupPreviewsCommand extends Command
{
    public function __construct(private readonly string $outputPath)
    {
        parent::__construct('conception-document:editor:cleanup-previews');
    }
    protected function configure(): void
    {
        $this->setDescription('Supprime les aperçus PDF expirés (plus de 24 h).')->addOption('apply', null, InputOption::VALUE_NONE, 'Appliquer le nettoyage.');
    }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = 0;
        foreach (glob(rtrim($this->outputPath, '/').'/editor/*.pdf') ?: [] as $path) {
            if (!preg_match('/^[a-f0-9]{32}(?:-print)?\.pdf$/', basename($path)) || filemtime($path) >= time() - 86400) {
                continue;
            }
            if ($input->getOption('apply') && !unlink($path)) {
                throw new \RuntimeException('Suppression impossible : '.$path);
            }
            ++$count;
        }
        $output->writeln($count.' aperçu(s) expiré(s)'.($input->getOption('apply') ? ' supprimé(s).' : ' ; ajoutez --apply pour nettoyer.'));
        return Command::SUCCESS;
    }
}
