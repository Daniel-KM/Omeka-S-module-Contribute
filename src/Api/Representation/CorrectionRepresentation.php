<?php
namespace Correction\Api\Representation;

use Omeka\Api\Representation\AbstractEntityRepresentation;

class CorrectionRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'correction';
    }

    public function getJsonLdType()
    {
        return 'o-module-correction:Correction';
    }

    public function getJsonLd()
    {
        $token = $this->token();
        if ($token) {
            $token = $token->getReference();
        }

        $created = [
            '@value' => $this->getDateTime($this->created()),
            '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
        ];

        $modified = $this->modified();
        if ($modified) {
            $modified = [
                '@value' => $this->getDateTime($modified),
                '@type' => 'http://www.w3.org/2001/XMLSchema#dateTime',
            ];
        }

        return [
            'o:id' => $this->id(),
            'o:resource' => $this->resource()->getReference(),
            'o-module-correction:token' => $token,
            'o:email' => $this->email(),
            'o-module-correction:reviewed' => $this->reviewed(),
            'o-module-correction:proposal' => $this->proposal(),
            'o:created' => $created,
            'o:modified' => $modified,
        ];
    }

    public function resource()
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    public function token()
    {
        return $this->getAdapter('correction_tokens')
            ->getRepresentation($this->resource->getToken());
    }

    public function email()
    {
        return $this->resource->getEmail();
    }

    public function reviewed()
    {
        return $this->resource->getReviewed();
    }

    /**
     * @return array
     */
    public function proposal()
    {
        return $this->resource->getProposal();
    }

    public function created()
    {
        return $this->resource->getCreated();
    }

    public function modified()
    {
        return $this->resource->getModified();
    }
}
