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
class Provision extends Action implements HttpPostActionInterface
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
        $email = trim((string)$this->getRequest()->getParam('email'));
        $consent = (bool)$this->getRequest()->getParam('consent');

        // Whatever happens next is a redirect back to the same form. The
        // admin's own input rides along in the session so a failed attempt
        // does not hand them an empty form and an unticked consent box.
        $this->_getSession()->setPingviewSetupForm(['email' => $email, 'consent' => $consent, 'error' => '']);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->messageManager->addErrorMessage(__('Enter a valid email address.'));
            return $redirect;
        }
        if (!$consent) {
            $this->messageManager->addErrorMessage(__('Consent is required to create the PingView connection.'));
            return $redirect;
        }

        try {
            $this->connections->provision($email);
            $this->_getSession()->unsPingviewSetupForm();
            $this->messageManager->addSuccessMessage(
                __('Monitoring is on. Check your inbox for access to the PingView dashboard.')
            );
        } catch (\Throwable $error) {
            $this->logger->error('PingView provisioning failed', ['exception' => $error]);
            $this->messageManager->addErrorMessage(__($error->getMessage()));
            // The same sentence again inside the card, next to the field it is
            // about: the Magento message bar sits on another axis, full width,
            // 300px away from a 650px card.
            $this->_getSession()->setPingviewSetupForm(['email' => $email, 'consent' => $consent, 'error' => $error->getMessage()]);

            // An existing account cannot be provisioned again, so send the
            // merchant back with the API key form already open rather than to
            // the same form that just refused them.
            if ($error->getCode() === ConnectionManager::ACCOUNT_EXISTS) {
                $redirect->setPath('pingview/dashboard/index', ['connect' => 1]);
            }
        }

        return $redirect;
    }
}
