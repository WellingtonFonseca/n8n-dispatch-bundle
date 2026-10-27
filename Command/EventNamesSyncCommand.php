<?php

declare(strict_types=1);

namespace MauticPlugin\N8nDispatchBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Event;
use MauticPlugin\N8nDispatchBundle\Service\EventNameSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Names the campaign actions of this plugin that already exist after the communication they dispatch (what
 * EventNameSubscriber does on every campaign save): every action whose name is not the template's. Running it again
 * changes nothing.
 */
#[AsCommand(name: 'n8ndispatch:events:sync-names', description: 'Names the existing n8n campaign actions after the communication they dispatch (every one whose name differs from the template name).')]
class EventNamesSyncCommand extends Command
{
    public function __construct(private EntityManagerInterface $em, private EventNameSync $sync)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only list what would be renamed, write nothing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun  = (bool) $input->getOption('dry-run');
        $changed = 0;
        $seen    = 0;

        $events = $this->em->getRepository(Event::class)->findBy(['type' => EventNameSync::TYPES]);

        foreach ($events as $event) {
            ++$seen;
            $before = (string) $event->getName();

            if ($this->sync->sync($event)) {
                ++$changed;
                $output->writeln(sprintf('#%d (campaign #%d): "%s" -> "%s"', $event->getId(), $event->getCampaign()->getId(), $before, $event->getName()));
            }
        }

        if ($dryRun) {
            $this->em->clear();
            $output->writeln(sprintf('<comment>Dry run: %d of %d actions would be renamed, nothing was written.</comment>', $changed, $seen));

            return Command::SUCCESS;
        }

        if ($changed > 0) {
            $this->em->flush();
        }
        $output->writeln(sprintf('%d of %d actions renamed.', $changed, $seen));

        return Command::SUCCESS;
    }
}
