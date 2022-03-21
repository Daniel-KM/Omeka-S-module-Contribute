<?php declare(strict_types=1);

namespace Contribute\Api\Representation;

use DateTime;
use Omeka\Api\Representation\AbstractEntityRepresentation;

class TokenRepresentation extends AbstractEntityRepresentation
{
    public function getControllerName()
    {
        return 'contribution';
    }

    public function getJsonLdType()
    {
        return 'o-module-contribute:Token';
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
            'o-module-contribute:token' => $this->token(),
            'o:email' => $this->email(),
            'o-module-contribute:expire' => $expire,
            'o:created' => $created,
            'o-module-contribute:accessed' => $accessed,
        ];
    }

    public function resource(): \Omeka\Api\Representation\AbstractResourceEntityRepresentation
    {
        return $this->getAdapter('resources')
            ->getRepresentation($this->resource->getResource());
    }

    public function token(): string
    {
        return $this->resource->getToken();
    }

    public function email(): ?string
    {
        return $this->resource->getEmail();
    }

    public function expire(): ?DateTime
    {
        return $this->resource->getExpire();
    }

    public function created(): DateTime
    {
        return $this->resource->getCreated();
    }

    public function accessed(): ?DateTime
    {
        return $this->resource->getAccessed();
    }

    public function isExpired(): bool
    {
        $expire = $this->expire();
        return $expire
            && $expire < new DateTime('now');
    }

    /**
     * In admin, the token admin url use ContributionController and the id is "0",
     * the token is set in the query.
     *
     * {@inheritDoc}
     * @see \Omeka\Api\Representation\AbstractResourceRepresentation::adminUrl()
     */
    public function adminUrl($action = null, $canonical = false)
    {
        $url = $this->getViewHelper('Url');
        return $url(
            'admin/id',
            [
                'controller' => $this->getControllerName(),
                'action' => $action,
                'id' => 0,
            ],
            [
                'query' => ['token' => $this->token()],
                'force_canonical' => $canonical,
            ]
        );
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

        $contributionResource = $this->resource();
        $query = ['token' => $this->token()];

        $url = $this->getViewHelper('Url');
        return $url(
            'site/resource-id',
            [
                'site-slug' => $siteSlug,
                'controller' => $contributionResource->getControllerName(),
                'id' => $contributionResource->id(),
                'action' => 'edit',
            ],
            [
                'query' => $query,
                'force_canonical' => $canonical,
            ]
        );
    }
}
