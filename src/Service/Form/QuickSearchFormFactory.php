<?php declare(strict_types=1);

namespace Contribute\Service\Form;

use Contribute\Form\QuickSearchForm;
use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class QuickSearchFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        $api = $services->get('Omeka\ApiManager');
        $settings = $services->get('Omeka\Settings');

        $availableTemplates = $api->search('resource_templates', [], ['returnScalar' => 'label'])->getContent();

        $contributeConfig = $settings->get('contribute_config') ?: [];
        $contributables = $contributeConfig['contribute_template_contributable'] ?? [];

        $set = array_intersect_key($availableTemplates, $contributables);
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

        // Check if both contribution types (patch and full) exist.
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');
        $patchCount = (int) $connection->executeQuery('SELECT COUNT(DISTINCT patch) FROM contribution')->fetchOne();
        $hasBothPatchTypes = $patchCount > 1;

        $form = new QuickSearchForm(null, $options ?? []);
        return $form
            ->setResourceTemplates($resourceTemplates)
            ->setUrlHelper($urlHelper)
            ->setHasBothPatchTypes($hasBothPatchTypes);
    }
}
