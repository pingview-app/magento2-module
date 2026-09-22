<?php
declare(strict_types=1);

namespace PingView\Monitoring\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\ResultInterface;
use PingView\Monitoring\Model\Config;

// Not final: Magento declares plugins on Magento\Backend\App\AbstractAction
// (adminAuthentication, adminMassactionKey, adminLoadDesign), so the object
// manager generates an Interceptor that extends this class. A final class
// makes that generated child a fatal error on the first admin request.
class Refresh extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PingView_Monitoring::dashboard';

    public function __construct(Action\Context $context, private readonly Config $config)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $this->config->clearStatusCache();
        $this->messageManager->addSuccessMessage(__('Status refreshed.'));
        return $this->resultRedirectFactory->create()->setPath('pingview/dashboard/index');
    }
}
