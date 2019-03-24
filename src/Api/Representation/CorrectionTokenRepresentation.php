<?php
namespace Correction\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class CorrectionTokenRepresentation extends AbstractEntityRepresentation
{
    /*
     * Magic getter to always pull data from resource.
     */
    public function __call($method, $arguments)
    {
        $method = 'get' . ucfirst($method);
        if (method_exists(\Correction\Entity\CorrectionToken::class, $method)) {
            return $this->resource->$method();
        }
    }

    public function getControllerName()
    {
        return 'correction';
    }

    public function getJsonLdType()
    {
        return 'o-module-correction:Token';
    }

    public function getJsonLd()
    {
        $expire = $this->resource->getExpire();
        if ($expire) {
            $expire = [
                '@value' => $this->getDateTime($expire),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $created = [
            '@value' => $this->getDateTime($this->resource->getCreated()),
            '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
        ];

        $accessed = $this->resource->getAccessed();
        if ($accessed) {
            $accessed = [
                '@value' => $this->getDateTime($accessed),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        return [
            'o:id' => $this->resource->getId(),
            'o:resource' => $this->resource->getResource()->getReference(),
            'o-module-correction:token' => $this->resource->getToken(),
            'o:email' => $this->resource->getEmail(),
            'o-module-correction:expire' => $expire,
            'o:created' => $created,
            'o-module-correction:accessed' => $accessed,
        ];
    }
}
