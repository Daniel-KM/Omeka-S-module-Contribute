<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Laminas\View\Helper\AbstractHelper;
use Omeka\Api\Manager as ApiManager;

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
     * @var ApiManager
     */
    protected $api;

    public function __construct(ApiManager $api)
    {
        // The view api doesn't manage options like "returnScalar".
        $this->api = $api;
    }

    /**
     * Render filters from search query.
     */
    public function __invoke($partialName = null): string
    {
        $partialName = $partialName ?: self::PARTIAL_NAME;

        $view = $this->getView();
        $translate = $view->plugin('translate');

        $filters = [];
        $query = $view->params()->fromQuery();

        foreach ($query as $key => $value) {
            if (is_null($value) || $value === '') {
                continue;
            }
            switch ($key) {
                case 'resource_template_id':
                    $filterLabel = $translate('Template'); // @translate
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    $filters[$filterLabel] = $this->api->search('resource_templates', ['id' => $value], ['returnScalar' => 'label'])->getContent();
                    break;

                case 'created':
                    $filterLabel = $translate('Created'); // @translate
                    $filterValue = $value;
                    $filters[$filterLabel][] = $filterValue;
                    break;

                case 'fulltext_search':
                    $filterLabel = $translate('Text'); // @translate
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
                    if (!is_array($value)) {
                        $value = [$value];
                    }
                    try {
                        $filters[$filterLabel] = $this->api->search('users', ['id' => $value], ['returnScalar' => 'name'])->getContent();
                    } catch (\Exception $e) {
                        // Avoid issue with rights.
                    }
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
