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
class Resync extends Action implements HttpPostActionInterface
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
        try {
            $this->connections->resyncTarget();
            $this->messageManager->addSuccessMessage(__('The monitor now checks the current store address.'));
        } catch (\Throwable $error) {
            $this->logger->error('PingView target resync failed', ['exception' => $error]);
            $this->messageManager->addErrorMessage(__($error->getMessage()));
        }

        return $this->resultRedirectFactory->create()->setPath('pingview/dashboard/index');
    }
}
