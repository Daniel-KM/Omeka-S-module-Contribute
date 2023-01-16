<?php declare(strict_types=1);

namespace Contribute\Service\Form;

use Contribute\Form\QuickSearchForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class QuickSearchFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $availableTemplates = $api->search('resource_templates', [], ['returnScalar' => 'label'])->getContent();
        $contributeTemplates = $services->get('Omeka\Settings')->get('contribute_templates', []);
        $set = array_intersect_key($availableTemplates, array_flip($contributeTemplates));
        $unset = array_diff_key($availableTemplates, $set);
        natcasesort($set);
        natcasesort($unset);
        $resourceTemplates = [
            'set' => [
                'label' => 'Contribute templates', // @translate
                'options' => $set,
            ],
            'unset' => [
                'label' => 'Other templates', // @translate
                'options' => $unset,
            ],
        ];

        $urlHelper = $services->get('ViewHelperManager')->get('url');

        $form = new QuickSearchForm(null, $options ?? []);
        return $form
            ->setResourceTemplates($resourceTemplates)
            ->setUrlHelper($urlHelper);
    }
}
