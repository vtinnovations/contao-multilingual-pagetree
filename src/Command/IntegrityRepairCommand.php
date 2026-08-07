<?php

/*
 * Contao Multilingual Pagetree
 *
 * Package: vtinnovations/contao-multilingual-pagetree
 * Copyright: V&T Innovations Team
 * Licence: proprietary
 * Website: https://www.v-t.one
 */


declare(strict_types=1);

namespace Vtinnovations\ContaoMultilingualPagetree\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairExecutor;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairPlanner;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityRepairResult;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScanner;
use Vtinnovations\ContaoMultilingualPagetree\Integrity\IntegrityScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;

/**
 * Repairs integrity issues.
 *
 * Without --execute the command only prints the plan; nothing is written. Even
 * with --execute, destructive actions additionally require --force, so deleting
 * records can never happen by accident.
 */
#[AsCommand(
    name: 'contao-multilingual-pagetree:integrity:repair',
    description: 'Repairs multilingual integrity issues (dry run by default).',
)]
final class IntegrityRepairCommand extends Command
{
    public function __construct(
        private readonly IntegrityScanner $scanner,
        private readonly IntegrityRepairPlanner $planner,
        private readonly IntegrityRepairExecutor $executor,
        private readonly ?ContaoFramework $framework = null,
        private readonly ?RootScope $rootLicenceContext = null,
        private readonly ?RootDomainRegistry $rootDomains = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Limit the repair to one root page id')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Limit the repair to one language code')
            ->addOption('entity', null, InputOption::VALUE_REQUIRED, 'Limit the repair to one entity type')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Actually apply the repair plan')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Also apply destructive actions')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->framework?->initialize();

            $root = (int) $input->getOption('root');
            $language = $this->stringOption($input->getOption('language'));
            $entity = $this->stringOption($input->getOption('entity'));
            $execute = (bool) $input->getOption('execute');
            $force = (bool) $input->getOption('force');
            $json = 'json' === strtolower((string) $input->getOption('format'));

            if ($execute && $root <= 0) {
                $io->error('Executing repairs requires an explicit --root=<id>.');

                return Command::INVALID;
            }
            if ($root > 0 && null !== $this->rootLicenceContext && null !== $this->rootDomains) {
                $domain = $this->rootDomains->domain($root);
                if (null === $domain) {
                    $io->error('The selected root has no valid configured domain.');

                    return Command::INVALID;
                }
                $this->rootLicenceContext->select($root, $domain);
            }

            $scope = $root > 0
                ? IntegrityScope::root($root, $language, $entity)
                : IntegrityScope::installation($language, $entity);

            $report = $this->scanner->scan($scope);
            $plan = $this->planner->plan($report);
            $preview = $plan->preview();

            if (!$execute) {
                $this->render($io, $output, $preview, $json, true);

                return $report->exitCode();
            }

            if ($plan->hasDestructiveActions() && !$force) {
                $io->warning('The plan contains destructive actions. Re-run with --force to apply them.');
                $this->render($io, $output, $preview, $json, true);

                return 1;
            }

            $result = $this->executor->execute($plan, $force);

            if (IntegrityRepairResult::STATUS_DENIED === $result->status && !$plan->isEmpty()) {
                // The executor is the authority; this message only explains the
                // refusal instead of leaving an unexplained "denied" status.
                $io->warning('The repair was not applied. Confirm destructive actions with --force, and check the product registration status.');
            }

            if ($json) {
                $output->writeln((string) json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $io->text(sprintf(
                    'Status: %s (deleted %d, quarantined %d, normalised %d)',
                    $result->status,
                    $result->deleted,
                    $result->quarantined,
                    $result->normalised,
                ));
            }

            if (!$result->isSuccessful()) {
                return 2;
            }

            return $this->scanner->scan($scope)->exitCode();
        } catch (\Throwable $exception) {
            $io->error('The integrity repair could not be completed: '.$exception->getMessage());

            return 3;
        }
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function render(SymfonyStyle $io, OutputInterface $output, array $preview, bool $json, bool $dryRun): void
    {
        if ($json) {
            $output->writeln((string) json_encode(
                ['dryRun' => $dryRun] + $preview,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }

        $io->title($dryRun ? 'Integrity repair (dry run)' : 'Integrity repair');
        $io->text(sprintf(
            'Planned: %d normalisation(s), %d quarantine(s), %d deletion(s)',
            (int) ($preview['recordsNormalised'] ?? 0),
            (int) ($preview['recordsQuarantined'] ?? 0),
            (int) ($preview['recordsDeleted'] ?? 0),
        ));

        if ([] !== ($preview['unresolved'] ?? [])) {
            $io->text('Unresolved (need an editor decision): '.implode(', ', $preview['unresolved']));
        }

        if ($dryRun) {
            $io->note('Nothing was changed. Re-run with --execute to apply the plan.');
        }
    }

    private function stringOption(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }
}
