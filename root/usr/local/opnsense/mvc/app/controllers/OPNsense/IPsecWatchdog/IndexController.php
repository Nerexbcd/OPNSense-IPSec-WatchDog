<?php

namespace OPNsense\IPsecWatchdog;

/**
 * Class IndexController
 * @package OPNsense\IPsecWatchdog
 */
class IndexController extends \OPNsense\Base\IndexController
{
    public function indexAction()
    {
        $this->view->formDialogTunnel = $this->getForm('tunnel');
        $this->view->formGridTunnel = $this->getFormGrid('tunnel');
        $this->view->formGeneralSettings = $this->getForm('general');
        $this->view->pick('OPNsense/IPsecWatchdog/index');
    }
}
