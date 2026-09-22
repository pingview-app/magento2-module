<?php
declare(strict_types=1);

namespace PingView\Monitoring\Setup;

use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use PingView\Monitoring\Model\Config;

/**
 * Remove everything this module stored when it is uninstalled.
 *
 * The API key is encrypted, but an encrypted live credential left behind in
 * core_config_data after `module:uninstall` is still a live credential in a
 * database that gets dumped, copied to staging and handed to agencies. The
 * WordPress plugin does the same in uninstall.php.
 *
 * The monitor itself is deliberately untouched: uninstalling the module stops
 * this panel, not the monitoring the merchant is paying for.
 */
class Uninstall implements UninstallInterface
{
    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        $setup->startSetup();

        $table = $setup->getTable('core_config_data');
        foreach (Config::OWNED_CONFIG_KEYS as $key) {
            $setup->getConnection()->delete($table, ['path = ?' => 'pingview/general/' . $key]);
        }

        $setup->endSetup();
    }
}
