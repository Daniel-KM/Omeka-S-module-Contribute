<?php
namespace Correction\Api\Representation;

use DateTime;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class TokenRepresentation extends AbstractEntityRepresentation
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

    /**
     * @return \Omeka\Api\Representation\AbstractResourceEntityRepresentation
     */
    public function resource()
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    /**
     * @return string
     */
    public function token()
    {
        return $this->resource->getToken();
    }

    /**
     * @return string
     */
    public function email()
    {
        return $this->resource->getEmail();
    }

    /**
     * @return \DateTime
     */
    public function expire()
    {
        return $this->resource->getExpire();
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
    public function accessed()
    {
        return $this->resource->getAccessed();
    }

    /**
     * @return bool
     */
    public function isExpired()
    {
        $expire = $this->expire();
        $result = $expire && $expire < new DateTime('now');
        return $result;
    }

    public function siteUrl($siteSlug = null, $canonical = false)
    {
        $services = $this->getServiceLocator();
        if (!$siteSlug) {
            $siteSlug = $services->get('Application')
                ->getMvcEvent()->getRouteMatch()->getParam('site-slug');
        }
        if (empty($siteSlug)) {
            $plugins = $services->get('ControllerPluginManager');
            $siteSlug = $plugins->get('defaultSiteSlug');
            $siteSlug = $siteSlug();
            if (is_null($siteSlug)) {
                $messenger = $plugins->get('messenger');
                $messenger()->addError('A site is required to create a public token.'); // @translate
                return '';
            }
        }

        $resource = $this->resource();
        $query = ['token' => $this->token()];

        $url = $this->getViewHelper('Url');
        return $url(
            'site/resource-id',
            [
                'site-slug' => $siteSlug,
                'controller' => $resource->getControllerName(),
                'id' => $resource->id(),
                'action' => 'edit',
            ],
            [
                'query' => $query,
                'force_canonical' => $canonical,
            ]
        );
    }
}
