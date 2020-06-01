<?php
namespace Contribute\Mvc\Controller\Plugin;

use Contribute\Api\Representation\TokenRepresentation;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Zend\Mvc\Controller\Plugin\AbstractPlugin;

class CheckToken extends AbstractPlugin
{
    /**
     * Check if the current user can correct a resource. The token may be updated.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @return TokenRepresentation|bool
     */
    public function __invoke(AbstractResourceEntityRepresentation $resource)
    {
        $controller = $this->getController();

        $token = $controller->params()->fromQuery('token');
        if (empty($token)) {
            return false;
        }

        /** @var \Contribute\Api\Representation\TokenRepresentation $token */
        $token = $controller->api()
            ->searchOne('contribute_tokens', ['token' => $token, 'resource_id' => $resource->id()])
            ->getContent();
        if (empty($token)) {
            return false;
        }

        // Update the token with last accessed time.
        $controller->api()->update('contribute_tokens', $token->id(), ['o-module-contribute:accessed' => 'now'], [], ['isPartial' => true]);

        // TODO Add a message for expiration.
        if ($token->isExpired()) {
            return false;
        }

        return $token;
    }
}
