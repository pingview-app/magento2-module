<?php
declare(strict_types=1);

namespace PingView\Monitoring\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use PingView\Monitoring\Model\ConnectionManager;
use Psr\Log\LoggerInterface;

// Not final: Magento declares plugins on Magento\Backend\App\AbstractAction
// (adminAuthentication, adminMassactionKey, adminLoadDesign), so the object
// manager generates an Interceptor that extends this class. A final class
// makes that generated child a fatal error on the first admin request.
class Connect extends Action implements HttpPostActionInterface
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
        /** @var Redirect $redirect */
        $redirect = $this->resultRedirectFactory->create()->setPath('pingview/dashboard/index');
        $token = trim((string)$this->getRequest()->getParam('api_key'));

        if (!preg_match('/^pk_live_[A-Za-z0-9_-]+$/', $token)) {
            $this->messageManager->addErrorMessage(__('PingView API keys start with pk_live_.'));
            return $redirect;
        }

        try {
            $this->connections->connect($token);
            $this->messageManager->addSuccessMessage(__('Connected. Your store status appears below.'));
        } catch (\Throwable $error) {
            $this->logger->error('PingView connection failed', ['exception' => $error]);
            $this->messageManager->addErrorMessage(__($error->getMessage()));
        }

        return $redirect;
    }
}
