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

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation
     */
    public function resource()
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    /**
     * @return \Correction\Api\Representation\TokenRepresentation
     */
    public function token()
    {
        return $this->getAdapter('correction_tokens')
            ->getRepresentation($this->resource->getToken());
    }

    /**
     * @return string
     */
    public function email()
    {
        return $this->resource->getEmail();
    }

    /**
     * @return bool
     */
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

    /**
     * @return \DateTime
     */
    public function created()
    {
        return $this->resource->getCreated();
    }

    /**
     * @return \DateTime
     */
    public function modified()
    {
        return $this->resource->getModified();
    }

    /**
     * Get all proposed corrections for a term.
     *
     * @todo Make it compatible with any type of field (resource, uri…).
     *
     * @param string $term
     * @return array
     */
    public function proposedValues($term)
    {
        $result = [];
        $data = $this->proposal();
        if (!empty($data[$term])) {
            foreach ($data[$term] as $value) {
                $result[] = $value['@value'];
            }
        }
        return $result;
    }

    /**
     * Get a specific proposed correction for a term.
     *
     * @todo Don't manage proposition of correction by key when there are multiple values.
     *
     * @param string $term
     * @param int $key
     * @return string
     */
    public function proposedValue($term, $key)
    {
        $data = $this->proposal();
        return isset($data[$term][$key]['@value'])
            ? $data[$term][$key]['@value']
            : null;
    }

    /**
     * Check if a value is the same than the resource one.
     *
     * @todo Don't manage proposition of correction by key when there are multiple values.
     *
     * @param string $term
     * @param int $key
     * @return bool
     */
    public function isApprovedValue($term, $key)
    {
        $data = $this->proposal();
        $value = isset($data[$term][$key]['@value'])
            ? $data[$term][$key]['@value']
            : null;
        $values = $this->resource()->value($term, ['all' => true], []);
        return isset($values[$key]) && $values[$key]->value() === $value;
    }
}
