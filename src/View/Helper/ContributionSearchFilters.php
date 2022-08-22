<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Exception\NotFoundException;

/**
 * View helper for rendering search filters.
 */
class ContributionSearchFilters extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    public const PARTIAL_NAME = 'common/search-filters';

    /**
     * Render filters from search query.
     */
    public function __invoke($partialName = null): string
    {
        $partialName = $partialName ?: self::PARTIAL_NAME;

        $view = $this->getView();
        $translate = $view->plugin('translate');

        $filters = [];
        $api = $view->api();
        $query = $view->params()->fromQuery();

        foreach ($query as $key => $value) {
            if (is_null($value) || $value === '') {
                continue;
            }
            switch ($key) {
                case 'created':
                    $filterLabel = $translate('Created'); // @translate
                    $filterValue = $value;
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'title':
                    $filterLabel = $translate('Title'); // @translate
                    $filterValue = $value;
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'patch':
                    $filterLabel = $translate('Type of contribution'); // @translate
                    $filterValue = (int) $value
                        ? $translate('Correction') // @translate
                        : $translate('Full contribution'); // @translate
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'submitted':
                    $filterLabel = $translate('Is submitted'); // @translate
                    $filterValue = (int) $value
                        ? $translate('Yes') // @translate
                        : $translate('No'); // @translate
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'reviewed':
                    $filterLabel = $translate('Is reviewed'); // @translate
                    $filterValue = (int) $value
                        ? $translate('Yes') // @translate
                        : $translate('No'); // @translate
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'owner_id':
                    $filterLabel = $translate('User'); // @translate
                    try {
                        $filterValue = $api->read('users', $value)->getContent()->name();
                    } catch (NotFoundException $e) {
                        $filterValue = $translate('Unknown user'); // @translate
                    }
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'email':
                    $filterLabel = $translate('Email'); // @translate
                    $filterValue = $value;
                    $filters[$filterLabel][] = $filterValue;
                    break;

                default:
                    break;
            }
        }

        $result = $this->getView()->trigger(
            'view.search.filters',
            ['filters' => $filters, 'query' => $query],
            true
        );
        $filters = $result['filters'];

        return $this->getView()->partial(
            $partialName,
            [
                'filters' => $filters,
            ]
        );
    }
}
