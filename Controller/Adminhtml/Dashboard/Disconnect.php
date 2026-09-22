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
class Disconnect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'PingView_Monitoring::manage';

    public function __construct(Action\Context $context, private readonly Config $config)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $this->config->disconnect();
        $this->messageManager->addSuccessMessage(
            __('Disconnected locally. External monitoring continues in PingView.')
        );
        return $this->resultRedirectFactory->create()->setPath('pingview/dashboard/index');
    }
}
