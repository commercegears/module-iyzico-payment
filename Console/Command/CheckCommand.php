<?php
/**
 * @category  CommerceGears
 * @package   CommerceGears_IyzicoPayment
 * @copyright Copyright © CommerceGears (https://commercegears.com/)
 */
declare(strict_types=1);

namespace CommerceGears\IyzicoPayment\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Sales\Model\OrderFactory;
use CommerceGears\IyzicoPayment\Cron\CheckPendingPayments;
use CommerceGears\IyzicoPayment\Model\Config;
use CommerceGears\IyzicoPayment\Model\OrderStatusService;
use CommerceGears\IyzicoPayment\Model\StatusChecker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * bin/magento commercegears:iyzico:check [order number] [--cancel-unpaid]
 * Without an order number: runs the scheduled check (waiting and fraud-review orders) now.
 */
class CheckCommand extends Command
{
    public function __construct(
        private readonly OrderStatusService $checker,
        private readonly CheckPendingPayments $cron,
        private readonly OrderFactory $orderFactory,
        private readonly State $state
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('commercegears:iyzico:check')
            ->setDescription('Asks iyzico for the payment status of an order (or of all waiting orders) and updates it')
            ->addArgument('order', InputArgument::OPTIONAL, 'Order number (increment ID)')
            ->addOption('cancel-unpaid', null, InputOption::VALUE_NONE, 'Cancel the order if iyzico has no payment for it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->state->setAreaCode(Area::AREA_CRONTAB);
        } catch (\Exception) {
            // Area already set.
        }
        $increment = (string)$input->getArgument('order');
        if ($increment === '') {
            $output->writeln('Waiting orders: ' . json_encode($this->cron->checkWaiting()));
            $output->writeln('Fraud review orders: ' . json_encode($this->cron->checkReview()));
            return Command::SUCCESS;
        }
        $order = $this->orderFactory->create()->loadByIncrementId($increment);
        if (!$order->getId() || $order->getPayment()?->getMethod() !== Config::METHOD_CODE) {
            $output->writeln('<error>No iyzico order with this number.</error>');
            return Command::FAILURE;
        }
        $outcome = $this->checker->check((int)$order->getId(), StatusChecker::SOURCE_ADMIN, (bool)$input->getOption('cancel-unpaid'));
        $order = $this->orderFactory->create()->loadByIncrementId($increment);
        $output->writeln(sprintf('Order %s: %s (order state now: %s)', $increment, $outcome, $order->getState()));
        return Command::SUCCESS;
    }
}
