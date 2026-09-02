<?php

namespace OPNsense\IPsecWatchdog\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Config;

/**
 * Class GeneralController
 * Get/set for the plugin's global settings (currently just webhook notification config) - the
 * "general" node of the model, as opposed to TunnelController's per-row "tunnel" array.
 * Not an ArrayField, so this doesn't use getBase()/setBase() (those are for a specific array
 * item, and getBase() with no uuid returns a *blank* template meant for an add-dialog, not the
 * current settings) - it works the "general" node directly instead.
 * @package OPNsense\IPsecWatchdog\Api
 */
class GeneralController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'ipsecwatchdog';
    protected static $internalModelClass = '\OPNsense\IPsecWatchdog\IPsecWatchdog';

    public function getAction()
    {
        return ['general' => $this->getModel()->general->getNodes()];
    }

    public function setAction()
    {
        $result = ['result' => 'failed'];
        if ($this->request->isPost() && $this->request->hasPost('general')) {
            Config::getInstance()->lock();
            $mdl = $this->getModel();
            $mdl->general->setNodes($this->request->getPost('general'));
            // scope validation (and its field names) to just the "general" node, so an unrelated
            // tunnel-row validation issue elsewhere in the model never surfaces on this form
            $result = $this->validate($mdl->general, 'general');
            if (empty($result['validations'])) {
                $this->setBaseHook($mdl->general);
                return $this->save(false, true);
            }
            $result['result'] = 'failed';
        }
        return $result;
    }
}
