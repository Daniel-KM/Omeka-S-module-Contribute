<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class ContributionForm extends AbstractHelper
{
    /**
     * The default partial view script.
     */
    const PARTIAL_NAME = 'contribute/site/contribution/form';

    /**
     * Prepare and display the form to contribute.
     *
     * For now, all the options should be passed from the view.
     *
     * @todo Factorize and prepare the options like in the controller.
     *
     * WARNING: The signature of this helper is unstable.
     *
     * @param array|null $options
     * @return string
     */
    public function __invoke(?array $options = null): string
    {
        $view = $this->getView();

        $defaultOptions = [
            'site' => null,
            'user' => null,
            'form' => null,
            'contribution' => null,
            'resource' => null,
            'fields' => [],
            'templateMedia' => null,
            'fieldsByMedia' => [],
            'fieldsMediaBase' => [],
            'action' => null,
            'mode' => null,
            'space' => null,
            'submitLabel' => $this->view->translate('Submit'),
            'cancelLabel' => $this->view->translate('Cancel'),
            'isMainForm' => true,
            'skipContributeCss' => false,
            'template' => self::PARTIAL_NAME,
        ];

        $options = (is_null($options) ? $view->vars()->getArrayCopy() : $options)
            + $defaultOptions;

        $template = $options['template'];
        unset($options['template']);

        return $view->partial($template, $options);
    }
}
