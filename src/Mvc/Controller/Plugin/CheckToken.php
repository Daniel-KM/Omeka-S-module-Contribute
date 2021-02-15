<?php declare(strict_types=1);

namespace Contribute\Mvc\Controller\Plugin;

use Contribute\Api\Representation\TokenRepresentation;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;

class CheckToken extends AbstractPlugin
{
    /**
     * Check if the current user can edit a resource. The token may be updated.
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
            ->searchOne('contribution_tokens', ['token' => $token, 'resource_id' => $resource->id()])
            ->getContent();
        if (empty($token)) {
            return false;
        }

        // Update the token with last accessed time.
        $controller->api()->update('contribution_tokens', $token->id(), ['o-module-contribute:accessed' => 'now'], [], ['isPartial' => true]);

        // TODO Add a message for expiration.
        if ($token->isExpired()) {
            return false;
        }

        return $token;
    }
}
