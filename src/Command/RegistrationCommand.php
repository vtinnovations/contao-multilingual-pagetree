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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\RegistrationClient;
use Vtinnovations\ContaoMultilingualPagetree\Distribution\RegistrationOutcome;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\InstallationIdentity;
use Vtinnovations\ContaoMultilingualPagetree\Security\CapabilityPolicy;
use Vtinnovations\ContaoMultilingualPagetree\Storage\PackageStoreInterface;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootScope;
use Vtinnovations\ContaoMultilingualPagetree\Metadata\RootDomainRegistry;

/**
 * Administrator entry point for activation, refresh, status and removal.
 *
 * Activation and refresh are the installation-initiated half of the channel;
 * both are safe to re-run. Nothing here can be triggered from the web, and the
 * subscription key is never echoed back, logged or written to the output.
 */
#[AsCommand(
    name: 'contao-multilingual-pagetree:registration',
    description: 'Activates, refreshes or reports the product registration of this installation.',
)]
final class RegistrationCommand extends Command
{
    private const ACTIONS = ['status', 'activate', 'refresh', 'remove'];

    public function __construct(
        private readonly RegistrationClient $client,
        private readonly CapabilityPolicy $policy,
        private readonly PackageStoreInterface $store,
        private readonly InstallationIdentity $identity,
        private readonly ?ContaoFramework $framework = null,
        private readonly ?RootScope $rootContext = null,
        private readonly ?RootDomainRegistry $rootDomains = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::OPTIONAL, 'One of: '.implode(', ', self::ACTIONS), 'status')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'Subscription key, required for "activate"')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Confirms "remove"')
            ->addOption('root', null, InputOption::VALUE_REQUIRED, 'Required Contao root page ID')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = strtolower((string) $input->getArgument('action'));

        if (!in_array($action, self::ACTIONS, true)) {
            $io->error('Unknown action. Use one of: '.implode(', ', self::ACTIONS).'.');

            return Command::INVALID;
        }

        try {
            $this->framework?->initialize();
            if (!$this->selectRoot($input, $io)) {
                return Command::INVALID;
            }

            return match ($action) {
                'activate' => $this->activate($io, $input),
                'refresh' => $this->report($io, $this->client->refresh()),
                'remove' => $this->remove($io, (bool) $input->getOption('force')),
                default => $this->status($io),
            };
        } catch (\Throwable $exception) {
            $io->error('The registration command failed: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function selectRoot(InputInterface $input, SymfonyStyle $io): bool
    {
        $root = $input->getOption('root');
        if (null === $this->rootContext || null === $this->rootDomains || !is_numeric($root)) {
            $io->error('A valid root page is required: --root=<id>.');

            return false;
        }
        $rootId = (int) $root;
        $domain = $this->rootDomains->domain($rootId);
        if (null === $domain) {
            $io->error('The selected root page has no valid configured domain.');

            return false;
        }
        $this->rootContext->select($rootId, $domain);

        return true;
    }

    private function activate(SymfonyStyle $io, InputInterface $input): int
    {
        $key = $input->getOption('key');

        if (!is_string($key) || '' === trim($key)) {
            $io->error('A subscription key is required: --key=...');

            return Command::INVALID;
        }

        return $this->report($io, $this->client->activate($key));
    }

    private function report(SymfonyStyle $io, RegistrationOutcome $outcome): int
    {
        if ($outcome->successful) {
            $io->success(sprintf('Registration %s (version %s).', $outcome->status, $outcome->version ?? 'unknown'));

            $this->policy->reset();
            $this->status($io);

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Registration unchanged: %s.', $outcome->status));
        $io->writeln($this->hint($outcome->status));

        return Command::FAILURE;
    }

    private function status(SymfonyStyle $io): int
    {
        $this->policy->reset();
        $decision = $this->policy->decision();

        $io->definitionList(
            ['Status' => $decision->statusLabel()],
            ['Tier' => $decision->tier?->value ?? '-'],
            ['Bound host' => $decision->boundHost ?? '-'],
            ['Installation host' => $this->identity->current() ?? '- (no trusted host outside a request)'],
            ['Version' => null === $decision->version ? '-' : (string) $decision->version],
            ['Expires' => $this->expiry($decision->lifetime, $decision->expiresAt)],
            ['Capabilities' => [] === $decision->capabilities
                ? '-'
                : implode(', ', array_map(static fn (\BackedEnum $capability): string => (string) $capability->value, $decision->capabilities))],
        );

        return $decision->granted ? Command::SUCCESS : Command::FAILURE;
    }

    private function expiry(bool $lifetime, ?int $expiresAt): string
    {
        if ($lifetime) {
            return 'never (lifetime)';
        }

        return null === $expiresAt ? '-' : gmdate('Y-m-d H:i:s', $expiresAt).' UTC';
    }

    private function remove(SymfonyStyle $io, bool $force): int
    {
        if (!$force) {
            $io->warning('This removes the stored registration state of this installation. Re-run with --force to confirm.');

            return Command::INVALID;
        }

        $this->store->clear();
        $this->policy->reset();
        $io->success('The stored registration state was removed. Editorial capabilities are disabled; no content was touched.');

        return Command::SUCCESS;
    }

    private function hint(string $status): string
    {
        return match (true) {
            str_starts_with($status, RegistrationOutcome::HOST_UNKNOWN) => 'Run this command where the installation host is known, or activate once from a request-bound context.',
            str_starts_with($status, RegistrationOutcome::NOT_OPERATIONAL) => 'The service could not provide verification. The existing state is untouched; try again later.',
            str_starts_with($status, RegistrationOutcome::TRANSPORT_TIMEOUT) => 'The verification request timed out. The existing state is untouched.',
            str_starts_with($status, RegistrationOutcome::TLS_FAILURE) => 'The secure verification connection failed. Do not disable TLS verification.',
            str_starts_with($status, RegistrationOutcome::CURL_EXTENSION_MISSING) => 'The PHP cURL extension required for verification is unavailable.',
            str_starts_with($status, RegistrationOutcome::UNKNOWN_SIGNING_KEY) => 'The signing key is not available in this build; no response can be trusted.',
            str_starts_with($status, RegistrationOutcome::NOT_ACTIVATED) => 'Nothing is activated yet. Run "activate --key=..." first.',
            str_starts_with($status, RegistrationOutcome::REJECTED) => 'The package verified but did not apply. The previous state is still active.',
            default => 'Nothing was changed locally.',
        };
    }
}
