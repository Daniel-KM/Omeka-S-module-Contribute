<?php
namespace Correction\Api\Representation;

use DateTime;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class CorrectionTokenRepresentation extends AbstractEntityRepresentation
{
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
        $expire = $this->expire();
        if ($expire) {
            $expire = [
                '@value' => $this->getDateTime($expire),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        $created = [
            '@value' => $this->getDateTime($this->created()),
            '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
        ];

        $accessed = $this->accessed();
        if ($accessed) {
            $accessed = [
                '@value' => $this->getDateTime($accessed),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        return [
            'o:id' => $this->id(),
            'o:resource' => $this->resource()->getReference(),
            'o-module-correction:token' => $this->token(),
            'o:email' => $this->email(),
            'o-module-correction:expire' => $expire,
            'o:created' => $created,
            'o-module-correction:accessed' => $accessed,
        ];
    }

    public function resource()
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    public function token()
    {
        return $this->resource->getToken();
    }

    public function email()
    {
        return $this->resource->getEmail();
    }

    public function expire()
    {
        return $this->resource->getExpire();
    }

    public function created()
    {
        return $this->resource->getCreated();
    }

    public function accessed()
    {
        return $this->resource->getAccessed();
    }
}
