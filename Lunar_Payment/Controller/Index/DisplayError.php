<?php

namespace Lunar\Payment\Controller\Index;

use Magento\Framework\App\ActionInterface;
use Magento\Framework\View\Result\PageFactory;

/**
 *
 */
class DisplayError implements ActionInterface
{
    public function __construct(
        PageFactory $resultPageFactory
    ) {
        $this->resultPageFactory = $resultPageFactory;
    }

    /**
     *
     */
    public function execute()
    {
        $page = $this->resultPageFactory->create();
        $page->getConfig()->getTitle()->set('Mobilepay error');
        // $page->getConfig()->setPageLayout('page/1column.phtml'); // works, but is better with 2 columns (default)
        return $page;
    }
}
