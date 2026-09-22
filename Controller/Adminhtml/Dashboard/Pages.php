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
class Pages extends Action implements HttpPostActionInterface
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
        $redirect = $this->resultRedirectFactory->create()->setPath('pingview/dashboard/index');
        $pages = $this->getRequest()->getParam('pages');

        if (!is_array($pages) || $pages === []) {
            $this->messageManager->addErrorMessage(__('Select at least one page to monitor.'));
            return $redirect;
        }

        try {
            // The service accepts only addresses it offered itself, so the
            // request cannot widen the set of pages to anything else.
            $created = $this->connections->addPages(array_map('strval', $pages));

            if ($created > 0) {
                $this->messageManager->addSuccessMessage(__('%1 pages are now monitored.', $created));
            } else {
                $this->messageManager->addErrorMessage(
                    __('No page could be added. Your plan may already be at its monitor limit.')
                );
            }
        } catch (\Throwable $error) {
            $this->logger->error('PingView could not add page monitors', ['exception' => $error]);
            $this->messageManager->addErrorMessage(__($error->getMessage()));
        }

        return $redirect;
    }
}
