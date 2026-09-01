<?php

namespace OPNsense\IPsecWatchdog;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\IPsec\Swanctl;

/**
 * Class IPsecWatchdog
 * @package OPNsense\IPsecWatchdog
 */
class IPsecWatchdog extends BaseModel
{
    /**
     * {@inheritdoc}
     * Two enabled rows watching the exact same connection/child pair would just fight each
     * other (or double-track the same downtime), so flag that as a mistake rather than let it
     * quietly happen. The grid CRUD flows (add/set) always validate with $validateFullModel=true
     * and only surface messages scoped to the node actually being saved (see
     * ApiMutableModelControllerBase::validate()), so the message is attached to BOTH sides of a
     * conflicting pair - otherwise editing whichever row happens to be the "first" one in
     * iteration order would silently swallow the warning.
     */
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        // child -> parent connection uuid, straight from IPsec's own Swanctl model, so a mismatched
        // pick (a child that doesn't actually belong to the chosen connection) gets caught here
        // rather than just silently failing to reconnect anything at watchdog run time
        $childParent = [];
        foreach ((new Swanctl())->children->child->iterateItems() as $child) {
            $childParent[$child->getAttribute('uuid')] = (string)$child->connection;
        }

        $seen = [];
        foreach ($this->tunnel->iterateItems() as $node) {
            if ($node->connection->isEmpty() || $node->child->isEmpty()) {
                continue;
            }

            $childUuid = (string)$node->child;
            if (isset($childParent[$childUuid]) && $childParent[$childUuid] !== (string)$node->connection) {
                $messages->appendMessage(new Message(
                    gettext('This child SA does not belong to the selected connection.'),
                    $node->child->__reference
                ));
            }

            if ((string)$node->enabled !== '1') {
                continue;
            }
            $key = strtolower((string)$node->connection) . '|' . strtolower($childUuid);
            if (isset($seen[$key])) {
                $text = gettext('Another enabled entry already watches this connection/child SA pair.');
                $messages->appendMessage(new Message($text, $node->connection->__reference));
                $messages->appendMessage(new Message($text, $seen[$key]->connection->__reference));
            } else {
                $seen[$key] = $node;
            }
        }

        return $messages;
    }
}
