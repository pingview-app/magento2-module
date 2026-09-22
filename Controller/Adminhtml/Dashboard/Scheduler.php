<?php
declare(strict_types=1);

namespace PingView\Monitoring\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use PingView\Monitoring\Model\ConnectionManager;
use Psr\Log\LoggerInterface;

// Not final: Magento declares plugins on Magento\Backend\App\AbstractAction
// (adminAuthentication, adminMassactionKey, adminLoadDesign), so the object
// manager generates an Interceptor that extends this class. A final class
// makes that generated child a fatal error on the first admin request.
class Scheduler extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PingView_Monitoring::manage';

    public function __construct(
        Action\Context $context,
        private readonly ConnectionManager $connections,
        private readonly LoggerInterface $logger
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $enable = (bool)$this->getRequest()->getParam('enable');

        try {
            if ($enable) {
                $this->connections->enableSchedulerWatch();
                $this->messageManager->addSuccessMessage(
                    __('External observation is on. Add the cron line below to the crontab that already runs Magento cron.')
                );
            } else {
                $this->connections->disableSchedulerWatch();
                $this->messageManager->addSuccessMessage(
                    __('External observation is off. The heartbeat monitor is paused, not deleted.')
                );
            }
        } catch (\Throwable $error) {
            $this->logger->error('PingView scheduler watch toggle failed', ['exception' => $error, 'enable' => $enable]);
            $this->messageManager->addErrorMessage(__($error->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('pingview/dashboard/index');
    }
}
