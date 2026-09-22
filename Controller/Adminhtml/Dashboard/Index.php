<?php
declare(strict_types=1);

namespace PingView\Monitoring\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\View\Result\PageFactory;

// Not final: Magento declares plugins on Magento\Backend\App\AbstractAction
// (adminAuthentication, adminMassactionKey, adminLoadDesign), so the object
// manager generates an Interceptor that extends this class. A final class
// makes that generated child a fatal error on the first admin request.
class Index extends Action implements HttpGetActionInterface
{
    public const ADMIN_RESOURCE = 'PingView_Monitoring::dashboard';

    public function __construct(Action\Context $context, private readonly PageFactory $pageFactory)
    {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->prepend(__('PingView Monitoring'));
        return $page;
    }
}
