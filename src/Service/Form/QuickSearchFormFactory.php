<?php declare(strict_types=1);

namespace Contribute\Service\Form;

use Contribute\Form\QuickSearchForm;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class QuickSearchFormFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $contributeTemplates = $services->get('Omeka\Settings')->get('contribute_templates', []);

        $form = new QuickSearchForm(null, $options);
        $urlHelper = $services->get('ViewHelperManager')->get('url');
        return $form
            ->setContributeTemplates($contributeTemplates)
            ->setUrlHelper($urlHelper);
    }
}
