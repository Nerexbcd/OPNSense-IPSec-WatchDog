<?php

namespace OPNsense\IPsecWatchdog\Api;

use OPNsense\Base\ApiMutableModelControllerBase;

/**
 * Class TunnelController
 * CRUD grid backing the watched-tunnel list (VPN > IPsec Watchdog): one row per
 * connection/child SA pair the watchdog should monitor and reconnect on prolonged downtime.
 * @package OPNsense\IPsecWatchdog\Api
 */
class TunnelController extends ApiMutableModelControllerBase
{
    protected static $internalModelName = 'ipsecwatchdog';
    protected static $internalModelClass = '\OPNsense\IPsecWatchdog\IPsecWatchdog';

    public function searchAction()
    {
        return $this->searchBase('tunnel', null, 'connection');
    }

    public function getAction($uuid = null)
    {
        return $this->getBase('tunnel', 'tunnel', $uuid);
    }

    public function addAction()
    {
        return $this->addBase('tunnel', 'tunnel');
    }

    public function setAction($uuid = null)
    {
        return $this->setBase('tunnel', 'tunnel', $uuid);
    }

    public function delAction($uuid = null)
    {
        return $this->delBase('tunnel', $uuid);
    }

    public function toggleAction($uuid = null, $enabled = null)
    {
        return $this->toggleBase('tunnel', $uuid, $enabled);
    }
}
