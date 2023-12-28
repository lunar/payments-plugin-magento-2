<?php

namespace Lunar\Payment\Setup\Patch\Data;

use Exception;
use Magento\Sales\Model\Order;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Framework\Setup\Patch\PatchVersionInterface;
use Magento\Framework\Setup\Patch\PatchRevertableInterface;

/**
 * Add new Order Status to be applied to the order
 * For patches documentation:
 * @see https://devdocs.magento.com/guides/v2.4/extension-dev-guide/declarative-schema/data-patches.html
 */
class AddNewOrderStatusPatch implements DataPatchInterface, PatchVersionInterface, PatchRevertableInterface
{
    /**
     * Patch version
     * Must be greater (>) than setup_version from etc/module.xml file
     */
    protected const PATCH_VERSION = '1.0.1';

    /**
     * Custom Processing Order-Status code
     */
    public const ORDER_STATUS_PAYMENT_RECEIVED_CODE = 'payment_received';

    /**
     * Custom Processing Order-Status label
     */
    protected const ORDER_STATUS_PAYMENT_RECEIVED_LABEL = 'Payment Received';

    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * InstallData constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        ModuleDataSetupInterface $moduleDataSetup
    ) {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * Installs data for a module
     *
     * @return void
     *
     * @throws Exception
     */
    public function apply()
    {

        /**
         * Call revert function to be sure to not insert data twice
         */
        $this->revert();

        /**
         * Prepare database for install
         */
        $this->moduleDataSetup->getConnection()->startSetup();

        $orderStatusData = [
            'status' => self::ORDER_STATUS_PAYMENT_RECEIVED_CODE,
            'label' => self::ORDER_STATUS_PAYMENT_RECEIVED_LABEL,
        ];

        /**
         * Assign status to order
         */
        $this->moduleDataSetup->getConnection()->insert(
            $this->moduleDataSetup->getTable('sales_order_status'),
            $orderStatusData
        );


        $statusToStateOrderData = [
            'status' => self::ORDER_STATUS_PAYMENT_RECEIVED_CODE,
            'state' => Order::STATE_PROCESSING,
            'is_default' => 0, // false,
            'visible_on_front' => 1, // true,
        ];

        /**
         * Assign status to order state
         */
        $this->moduleDataSetup->getConnection()->insert(
            $this->moduleDataSetup->getTable('sales_order_status_state'),
            $statusToStateOrderData
        );

        /**
         * Prepare database after install
         */
        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * Reverting actions from apply() method on uninstall
     */
    public function revert()
    {
        /**
         * Here should go code that will revert all operations from `apply` method
         * Please note, that some operations, like removing data from column, that is in role of foreign key reference
         * is dangerous, because it can trigger ON DELETE statement
         */

        $this->moduleDataSetup->getConnection()->startSetup();


        /** Delete associated status state. */
        $this->moduleDataSetup->deleteTableRow(
            $table = 'sales_order_status_state',
            $idField = 'status',
            $rowId = self::ORDER_STATUS_PAYMENT_RECEIVED_CODE,
            $parentField = null,
            $parentId = 0
        );

        /** Delete order status. */
        $this->moduleDataSetup->deleteTableRow(
            $table = 'sales_order_status',
            $idField = 'status',
            $rowId = self::ORDER_STATUS_PAYMENT_RECEIVED_CODE,
            $parentField = null,
            $parentId = 0
        );

        $this->moduleDataSetup->getConnection()->endSetup();
    }

    /**
     * @inheritdoc
     */
    public static function getVersion()
    {
        return self::PATCH_VERSION;
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        /**
         * This internal Magento method, that means that some patches with time can change their names,
         * but changing name should not affect installation process, that's why if we will change name of the patch
         * we will add alias here
         */
        return [];
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        /**
         * This is dependency to another patch. Dependency should be applied first
         * One patch can have few dependencies
         * Patches do not have versions, so if in old approach with Install/Upgrade data scripts you used
         * versions, right now you need to point from patch with higher version to patch with lower version
         * But please, note, that some of your patches can be independent and can be installed in any sequence
         * So use dependencies only if this important for you
         */
        return [];
    }
}
