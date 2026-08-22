<?php

declare(strict_types=1);

namespace App\Core\Presentation\Cli;

use App\Core\Application\Billing\BillingLifecycleService;
use App\Core\Application\Billing\SubscriptionRenewalService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:billing:lifecycle', description: 'Aktualisiert Zahlungs-, Mahn-, Sperr- und Aboablaufstatus.')]
final class BillingLifecycleCommand extends Command
{
    public function __construct(private readonly BillingLifecycleService $lifecycle, private readonly SubscriptionRenewalService $renewals) { parent::__construct(); }
    protected function execute(InputInterface $input, OutputInterface $output): int { $renewed = $this->renewals->processDue(); $result = $this->lifecycle->run(); $output->writeln(json_encode(['renewed' => $renewed] + $result, JSON_THROW_ON_ERROR)); return Command::SUCCESS; }
}
