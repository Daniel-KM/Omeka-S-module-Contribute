<?php declare(strict_types=1);

namespace Contribute\Controller\Site;

use Contribute\Api\Representation\ContributionRepresentation;
use Contribute\Form\ContributeForm;
use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
// TODO Use the admin resource form, but there are some differences in features (validation by field, possibility to update the item before validate correction, anonymous, fields is more end user friendly and enough in most of the case), themes and security issues, so not sure it is simpler.
// use Omeka\Form\ResourceForm;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\Message;

class ContributionController extends AbstractActionController
{
    public function showAction()
    {
        $site = $this->currentSite();
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

        $resourceTypeMap = [
            'contribution' => 'Contribute\Controller\Site\Contribution',
            'item' => 'Omeka\Controller\Site\Item',
            'media' => 'Omeka\Controller\Site\Media',
            'item-set' => 'Omeka\Controller\Site\ItemSet',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        if ($resourceType !== 'contribution') {
            $this->forward()->dispatch($resourceTypeMap[$resourceType], [
                'site-slug' => $this->currentSite()->slug(),
                'controller' => $resourceType,
                'action' => 'show',
                'id' => $resourceId,
            ]);
        }

        // Rights are automatically checked.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $contribution = $this->api()->read('contributions', ['id' => $resourceId])->getContent();

        return new ViewModel([
            'site' => $site,
            'resource' => $contribution,
            'contribution' => $contribution,
        ]);
    }

    public function viewAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'show';
        return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
    }

    public function addAction()
    {
        $site = $this->currentSite();
        $resourceType = $this->params('resource');

        $resourceTypeMap = [
            'contribution' => 'items',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        if ($resourceType === 'contribution') {
            $resourceType = 'item';
        }
        // TODO Use the resource name to store the contribution (always items here for now).
        $resourceName = $resourceTypeMap[$resourceType];

        $settings = $this->settings();
        $user = $this->identity();
        $contributeMode = $settings->get('contribute_mode');

        // TODO Allow to use a token to add a resource.
        // $token = $this->checkToken($resource);
        $token = null;
        if (!$token
            && (
                !in_array($contributeMode, ['user', 'open'])
                || ($contributeMode === 'user' && !$user)
            )
        ) {
            return $this->viewError403();
        }

        // Prepare the resource template. Use the first if not queryied.

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributiveData */
        $contributiveData = $this->getPluginManager()->get('contributiveData');
        $allowedResourceTemplates = $this->settings()->get('contribute_templates', []);
        $templates = [];
        $templateLabels = [];
        // Remove non-contributive templates.
        foreach ($this->api()->search('resource_templates', ['id' => $allowedResourceTemplates])->getContent() as $template) {
            $contributive = $contributiveData($template);
            if ($contributive->isContributive()) {
                $templates[$template->id()] = $template;
                $templateLabels[$template->id()] = $template->label();
            }
        }

        // A template is required to contribute: set by query or previous form.
        $template = $this->params()->fromQuery('template')
            ?: $this->params()->fromPost('template');

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        if ($template) {
            $contributive = clone $contributiveData($template);
            $resourceTemplate = $contributive->template();
        }
        if (!count($templates) || ($template && !$resourceTemplate)) {
            $this->logger()->err('A template is required to add a resource. Ask the administrator for more information.'); // @translate
            return new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'resource' => null,
                'contribution' => null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
            ]);
        }

        if (!$template) {
            if (count($templates) === 1) {
                $resourceTemplate = reset($templates);
                $contributive = clone $contributiveData($template);
            } else {
                $resourceTemplate = null;
            }
        }

        $currentUrl = $this->url()->fromRoute(null, [], true);

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            // Use setOptions, not getForm().
            ->setTemplates($templateLabels)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        // First step: select a template if not set.
        if (!$resourceTemplate) {
            return new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => $form,
                'resource' => null,
                'contribution' => null,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
            ]);
        }

        $form->get('template')->setValue($resourceTemplate->id());
        $step = $this->params()->fromPost('step');

        // Second step: fill the template and create a contribution, even partial.
        if ($this->getRequest()->isPost() && !$step) {
            $post = $this->params()->fromPost();
            // The template cannot be changed once set.
            $post['template'] = $resourceTemplate->id();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $proposal = $this->prepareProposal($data);
                if ($proposal) {
                    // When there is a resource, it isn’t updated, but the
                    // proposition of contribution is saved for moderation.
                    $data = [
                        'o:resource' => null,
                        'o:owner' => $user ? ['o:id' => $user->getId()] : null,
                        'o-module-contribute:token' => $token ? ['o:id' => $token->id()] : null,
                        'o:email' => $token ? $token->email() : ($user ? $user->getEmail() : null),
                        'o-module-contribute:reviewed' => false,
                        'o-module-contribute:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->create('contributions', $data);
                    if ($response) {
                        $this->messenger()->addSuccess('Contribution successfully submitted!'); // @translate
                        $this->prepareContributionEmail($response->getContent());
                        $eventManager = $this->getEventManager();
                        $eventManager->trigger('contribute.submit', $this, [
                            'contribution' => $response->getContent(),
                            'resource' => null,
                            'data' => $data,
                        ]);
                        return $this->redirect()->toUrl($currentUrl);
                    }
                } else {
                    // The only error for now is a missing template, and it
                    // should not occurs since it is checked above.
                    $this->messenger()->addError('Contribution not submitted: a template is required.'); // @translate
                    $this->messenger()->addFormErrors($form);
                }
            } else {
                $this->messenger()->addError('An error occurred: check your input.'); // @translate
                $this->messenger()->addFormErrors($form);
            }
        }

        /** @var \Contribute\View\Helper\ContributionFields $contributionFields */
        $contributionFields = $this->viewHelpers()->get('contributionFields');
        $fields = $contributionFields(null, null, $resourceTemplate);

        // Only items can have a sub resource template for medias.
        if (in_array($resourceName, ['contributions', 'items']) && $contributive->contributiveMedia()) {
            // TODO Check bad contribution for invalid data.
            $fieldsByMedia = [];
            // Add a list of fields without values for new media.
            $fieldsMediaBase = $contributionFields(null, null, $contributive->contributiveMedia()->template(), true);
        } else {
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        return new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'resource' => null,
            'contribution' => null,
            'fields' => $fields,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
        ]);
    }

    public function editAction()
    {
        $site = $this->currentSite();
        $api = $this->api();
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

        // Unlike addAction(), edition is always the right contribution or
        // resource.
        $resourceTypeMap = [
            'contribution' => 'contributions',
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }

        $resourceName = $resourceTypeMap[$resourceType];

        // Rights are automatically checked.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $api->read($resourceName, ['id' => $resourceId])->getContent();

        $settings = $this->settings();
        $user = $this->identity();
        $contributeMode = $settings->get('contribute_mode');

        if ($resourceName === 'contributions') {
            $contribution = $resource;
            $resource = $contribution->resource();
            $resourceId = $resource ? $resource->id() : null;
            $resourceTemplate = $contribution->resourceTemplate();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        } else {
            $token = $this->checkToken($resource);
            if (!$token
                && (
                    !in_array($contributeMode, ['user', 'open'])
                    || ($contributeMode === 'user' && !$user)
                )
            ) {
                return $this->viewError403();
            }

            // There may be no contribution when it is a correction.
            if ($token) {
                $contribution = $api
                    ->searchOne('contributions', ['resource_id' => $resourceId, 'token_id' => $token->id()])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
            } elseif ($user) {
                $contribution = $api
                    ->searchOne('contributions', ['resource_id' => $resourceId, 'email' => $user->getEmail(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                    ->getContent();
                $currentUrl = $this->url()->fromRoute(null, [], true);
            } else {
                $contribution = null;
                $currentUrl = $this->url()->fromRoute(null, [], true);
            }

            $resourceTemplate = $resource->resourceTemplate();
        }

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        $contributive = clone $this->contributiveData($resourceTemplate);
        if (!$resourceTemplate || !$contributive->isContributive()) {
            $this->logger()->warn('This resource cannot be edited: no resource template, no fields, or not allowed.'); // @translate
            return new ViewModel([
                'site' => $site,
                'user' => $user,
                'form' => null,
                'resource' => $resource,
                'contribution' => $contribution,
                'fields' => [],
                'fieldsByMedia' => [],
                'fieldsMediaBase' => [],
            ]);
        }

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        // No need to set the template, but simplify view for form.
        $form->get('template')->setValue($resourceTemplate->id());

        // There is no step for edition: the resource template is always set.

        if ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            // The template cannot be changed once set.
            $post['template'] = $resourceTemplate->id();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $proposal = $this->prepareProposal($data, $resource);
                if ($proposal) {
                    // The resource isn’t updated, but the proposition of contribute
                    // is saved for moderation.
                    $response = null;
                    if (empty($contribution)) {
                        $data = [
                            'o:resource' => $resourceId ? ['o:id' => $resourceId] : null,
                            'o:owner' => $user ? ['o:id' => $user->getId()] : null,
                            'o-module-contribute:token' => $token ? ['o:id' => $token->id()] : null,
                            'o:email' => $token ? $token->email() : ($user ? $user->getEmail() : null),
                            'o-module-contribute:reviewed' => false,
                            'o-module-contribute:proposal' => $proposal,
                        ];
                        $response = $this->api($form)->create('contributions', $data);
                        if ($response) {
                            $this->messenger()->addSuccess('Contribution successfully submitted!'); // @translate
                            $this->prepareContributionEmail($response->getContent());
                        }
                    } elseif ($proposal !== $contribution->proposal()) {
                        $data = [
                            'o-module-contribute:reviewed' => false,
                            'o-module-contribute:proposal' => $proposal,
                        ];
                        $response = $this->api($form)->update('contributions', $contribution->id(), $data, [], ['isPartial' => true]);
                        if ($response) {
                            $this->messenger()->addSuccess('Contribution successfully updated!'); // @translate
                            $this->prepareContributionEmail($response->getContent());
                        }
                    } else {
                        $this->messenger()->addWarning('No change.'); // @translate
                        $response = true;
                    }
                    if ($response) {
                        $eventManager = $this->getEventManager();
                        $eventManager->trigger('contribute.submit', $this, [
                            'contribution' => $contribution,
                            'resource' => $resource,
                            'data' => $data,
                        ]);
                        return $this->redirect()->toUrl($currentUrl);
                    }
                } else {
                    // The only error for now is a missing template, and it
                    // should not occurs since it is checked above.
                    $this->messenger()->addError('Contribution not submitted: a template is required.'); // @translate
                    $this->messenger()->addFormErrors($form);
                }
            } else {
                $this->messenger()->addError('An error occurred: check your input.'); // @translate
                $this->messenger()->addFormErrors($form);
            }
        }

        /** @var \Contribute\View\Helper\ContributionFields $contributionFields */
        $contributionFields = $this->viewHelpers()->get('contributionFields');
        $fields = $contributionFields($resource, $contribution);

        // Only items can have a sub resource template for medias.
        if (in_array($resourceName, ['contributions', 'items']) && $contributive->contributiveMedia()) {
            $resourceTemplateMedia = $contributive->contributiveMedia()->template();
            foreach ([] /*$contribution->medias() */ as $contributionMedia) {
                // TODO Match resource medias and contribution (for now only allowed until submission).
                $fieldsByMedia = $contributionFields(null, $contributionMedia, $resourceTemplateMedia, true);
            }
            // Add a list of fields without values for new media.
            $fieldsMediaBase = $contributionFields(null, null, $contributive->contributiveMedia()->template(), true);
        } else {
            $fieldsByMedia = [];
            $fieldsMediaBase = [];
        }

        return new ViewModel([
            'site' => $site,
            'user' => $user,
            'form' => $form,
            'resource' => $resource,
            'contribution' => $contribution,
            'fields' => $fields,
            'fieldsByMedia' => $fieldsByMedia,
            'fieldsMediaBase' => $fieldsMediaBase,
        ]);
    }

    public function deleteConfirmAction()
    {
        throw new \Omeka\Mvc\Exception\PermissionDeniedException('The delete confirm action is currently unavailable');
    }

    public function deleteAction()
    {
        $response = $this->api()->delete('contributions', $this->params('id'));
        if ($response) {
            $this->messenger()->addSuccess('Contribution successfully deleted'); // @translate
        }
        return $this->redirect()->toRoute('site/guest/contribution', ['action' => 'show'], true);
    }

    protected function prepareContributionEmail(ContributionRepresentation $contribution): self
    {
        $emails = $this->settings()->get('contribute_notify', []);
        if (empty($emails)) {
            return $this;
        }

        $user = $this->identity();
        if ($user) {
            $message = '<p>' . new Message(
                'User %1$s has edited resource #%2$s (%3$s).', // @translate
                '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                '<a href="' . $contribution->resource()->adminUrl('show', true) . '#contribution">' . $contribution->resource()->id() . '</a>',
                $contribution->resource()->displayTitle()
            ) . '</p>';
        } else {
            $message = '<p>' . new Message(
                'A user has edited resource #%1$d (%2$s).', // @translate
                '<a href="' . $contribution->resource()->adminUrl('show', true) . '#contribution">' . $contribution->resource()->id() . '</a>',
                $contribution->resource()->displayTitle()
            ) . '</p>';
        }
        $this->sendContributionEmail($emails, $this->translate('[Omeka Contribution] New contribution'), $message); // @translate
        return $this;
    }

    /**
     * Prepare the proposal for saving.
     *
     * The check is done comparing the keys of original values and the new ones.
     *
     * @todo Factorize with \Contribute\Admin\ContributeController::validateAndUpdateContribution() and \Contribute\View\Helper\ContributionFields
     */
    protected function prepareProposal(array $proposal, ?AbstractResourceEntityRepresentation $resource = null, ?bool $isSubTemplate = false): ?array
    {
        $isSubTemplate = (bool) $isSubTemplate;

        // It's not possible to change the resource template of a resource in
        // public side.
        // A resource can be corrected only with a resource template (require
        // editable or fillable keys).
        if ($resource) {
            $resourceTemplate = $resource->resourceTemplate();
        } elseif (isset($proposal['template'])) {
            $resourceTemplate = $proposal['template'] ?? null;
            $resourceTemplate = $this->api()->searchOne('resource_templates', is_numeric($resourceTemplate) ? ['id' => $resourceTemplate] : ['label' => $resourceTemplate])->getContent();
        } else {
            $resourceTemplate = null;
        }
        if (!$resourceTemplate) {
            return null;
        }

        // The contribution requires a resource template in allowed templates.
        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        $contributive = $this->contributiveData($resourceTemplate, $isSubTemplate);
        $resourceTemplate = $contributive->template();
        if (!$contributive->isContributive()) {
            return null;
        }

        $result = [
            'template' => $resourceTemplate->id(),
        ];

        // Clean data.
        unset($proposal['template']);
        foreach ($proposal as &$values) {
            // Manage specific posts.
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $value['@value'] = $this->cleanString($value['@value']);
                }
                if (isset($value['@resource'])) {
                    $value['@resource'] = (int) $value['@resource'];
                }
                if (isset($value['@uri'])) {
                    $value['@uri'] = $this->cleanString($value['@uri']);
                }
                if (isset($value['@label'])) {
                    $value['@label'] = $this->cleanString($value['@label']);
                }
            }
        }
        unset($values, $value);

        $propertyIds = $this->propertyIdsByTerms();
        $customVocabBaseTypes = $this->viewHelpers()->get('customVocabBaseType')();

        // Process only editable keys.

        // Process editable properties first.
        // TODO Remove whitelist/blacklist since a resource template is required (but take care of updated template).
        $matches = [];
        switch ($contributive->editableMode()) {
            case 'whitelist':
                $proposalEditableTerms = array_keys(array_intersect_key($proposal, $contributive->editableProperties()));
                break;
            case 'blacklist':
                $proposalEditableTerms = array_keys(array_diff_key($proposal, $contributive->editableProperties()));
                break;
            case 'all':
            default:
                $proposalEditableTerms = array_keys($proposal);
                break;
        }

        foreach ($proposalEditableTerms as $term) {
            /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
            $values = $resource ? $resource->value($term, ['all' => true]) : [];
            foreach ($values as $index => $value) {
                if (!isset($proposal[$term][$index])) {
                    continue;
                }
                $type = $value->type();
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }

                $typeColon = strtok($type, ':');
                $baseType = null;
                $uriLabels = [];
                if ($typeColon === 'customvocab') {
                    $customVocabId = (int) substr($type, 12);
                    $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                    $uriLabels = $this->customVocabUriLabels($customVocabId);
                }

                switch ($type) {
                    case 'literal':
                    case $typeColon === 'numeric':
                    case $typeColon === 'customvocab' && $baseType === 'literal':
                        if (!isset($proposal[$term][$index]['@value'])) {
                            continue 2;
                        }
                        $result[$term][] = [
                            'original' => [
                                '@value' => $value->value(),
                            ],
                            'proposed' => [
                                '@value' => $proposal[$term][$index]['@value'],
                            ],
                        ];
                        break;
                    case $typeColon === 'resource':
                    case $typeColon === 'customvocab' && $baseType === 'resource':
                        if (!isset($proposal[$term][$index]['@resource'])) {
                            continue 2;
                        }
                        $vr = $value->valueResource();
                        $result[$term][] = [
                            'original' => [
                                '@resource' => $vr ? $vr->id() : null,
                            ],
                            'proposed' => [
                                '@resource' => (int) $proposal[$term][$index]['@resource'] ?: null,
                            ],
                        ];
                        break;
                    case $typeColon === 'customvocab' && $baseType === 'uri':
                        $proposedValue['@label'] = $uriLabels[$proposal[$term][$index]['@uri'] ?? ''] ?? '';
                        // No break.
                    case 'uri':
                        if (!isset($proposal[$term][$index]['@uri'])) {
                            continue 2;
                        }
                        $proposal[$term][$index] += ['@label' => ''];
                        $result[$term][] = [
                            'original' => [
                                '@uri' => $value->uri(),
                                '@label' => $value->value(),
                            ],
                            'proposed' => [
                                '@uri' => $proposal[$term][$index]['@uri'],
                                '@label' => $proposal[$term][$index]['@label'],
                            ],
                        ];
                        break;
                    case $typeColon === 'valuesuggest':
                    case $typeColon === 'valuesuggestall':
                        if (!isset($proposal[$term][$index]['@uri'])) {
                            continue 2;
                        }
                        if (!preg_match('~^<a href="(.+)" target="_blank">\s*(.+)\s*</a>$~', $proposal[$term][$index]['@uri'], $matches)) {
                            continue 2;
                        }
                        if (!filter_var($matches[1], FILTER_VALIDATE_URL)) {
                            continue 2;
                        }
                        $proposal[$term][$index]['@uri'] = $matches[1];
                        $proposal[$term][$index]['@label'] = $matches[2];
                        $result[$term][] = [
                            'original' => [
                                '@uri' => $value->uri(),
                                '@label' => $value->value(),
                            ],
                            'proposed' => [
                                '@uri' => $proposal[$term][$index]['@uri'],
                                '@label' => $proposal[$term][$index]['@label'],
                            ],
                        ];
                        break;
                    default:
                        // Nothing to do.
                        continue 2;
                }
            }
        }

        // Append fillable properties.
        switch ($contributive->fillableMode()) {
            case 'whitelist':
                $proposalFillableTerms = array_keys(array_intersect_key($proposal, $contributive->fillableProperties()));
                break;
            case 'blacklist':
                $proposalFillableTerms = array_diff_key($proposal, $contributive->fillableProperties());
                break;
            case 'all':
            default:
                $proposalFillableTerms = array_keys($proposal);
                break;
        }

        foreach ($proposalFillableTerms as $term) {
            if (!isset($propertyIds[$term])) {
                continue;
            }

            $propertyId = $propertyIds[$term];
            $type = null;
            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
            }

            $baseType = null;
            $uriLabels = [];
            if (substr((string) $typeTemplate, 0, 12) === 'customvocab:') {
                $customVocabId = (int) substr($typeTemplate, 12);
                $baseType = $customVocabBaseTypes[$customVocabId] ?? 'literal';
                $uriLabels = $this->customVocabUriLabels($customVocabId);
            }

            foreach ($proposal[$term] as $index => $proposedValue) {
                /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
                $values = $resource ? $resource->value($term, ['all' => true]) : [];
                if (isset($values[$index])) {
                    continue;
                }

                if ($typeTemplate) {
                    $type = $typeTemplate;
                } elseif (array_key_exists('@uri', $proposedValue)) {
                    $type = 'uri';
                } elseif (array_key_exists('@resource', $proposedValue)) {
                    $type = 'resource';
                } elseif (array_key_exists('@value', $proposedValue)) {
                    $type = 'literal';
                } else {
                    $type = 'unknown';
                }

                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }

                $typeColon = strtok($type, ':');
                switch ($type) {
                    case 'literal':
                    case $typeColon === 'numeric':
                    case $typeColon === 'customvocab' && $baseType === 'literal':
                        if (!isset($proposedValue['@value']) || $proposedValue['@value'] === '') {
                            continue 2;
                        }
                        $result[$term][] = [
                            'original' => [
                                '@value' => null,
                            ],
                            'proposed' => [
                                '@value' => $proposedValue['@value'],
                            ],
                        ];
                        break;
                    case $typeColon === 'resource':
                    case $typeColon === 'customvocab' && $baseType === 'resource':
                        if (!isset($proposedValue['@resource']) || !(int) $proposedValue['@resource']) {
                            continue 2;
                        }
                        $result[$term][] = [
                            'original' => [
                                '@resource' => null,
                            ],
                            'proposed' => [
                                '@resource' => (int) $proposedValue['@resource'],
                            ],
                        ];
                        break;
                    case $typeColon === 'customvocab' && $baseType === 'uri':
                        $proposedValue['@label'] = $uriLabels[$proposedValue['@uri'] ?? ''] ?? '';
                        // No break.
                    case 'uri':
                        if (!isset($proposedValue['@uri']) || $proposedValue['@uri'] === '') {
                            continue 2;
                        }
                        $proposedValue += ['@label' => ''];
                        $result[$term][] = [
                            'original' => [
                                '@uri' => null,
                                '@label' => null,
                            ],
                            'proposed' => [
                                '@uri' => $proposedValue['@uri'],
                                '@label' => $proposedValue['@label'],
                            ],
                        ];
                        break;
                    case $typeColon === 'valuesuggest':
                    case $typeColon === 'valuesuggestall':
                        if (!isset($proposedValue['@uri']) || $proposedValue['@uri'] === '') {
                            continue 2;
                        }
                        if (!preg_match('~^<a href="(.+)" target="_blank">\s*(.+)\s*</a>$~', $proposal[$term][$index]['@uri'], $matches)) {
                            continue 2;
                        }
                        if (!filter_var($matches[1], FILTER_VALIDATE_URL)) {
                            continue 2;
                        }
                        $proposedValue['@uri'] = $matches[1];
                        $proposedValue['@label'] = $matches[2];
                        $result[$term][] = [
                            'original' => [
                                '@uri' => null,
                                '@label' => null,
                            ],
                            'proposed' => [
                                '@uri' => $proposedValue['@uri'],
                                '@label' => $proposedValue['@label'],
                            ],
                        ];
                        break;
                    default:
                        // Nothing to do.
                        continue 2;
                }
            }
        }

        return $result;
    }

    /**
     * Get the list of uris and labels of a specific custom vocab.
     *
     * @see \CustomVocab\DataType\CustomVocab::getUriForm()
     */
    protected function customVocabUriLabels(int $customVocabId): array
    {
        static $uriLabels = [];
        if (!isset($uriLabels[$customVocabId])) {
            $uris = $this->api()->searchOne('custom_vocabs', ['id' => $customVocabId], ['returnScalar' => 'uris'])->getContent();
            $uris = array_map('trim', preg_split("/\r\n|\n|\r/", (string) $uris));
            $matches = [];
            $values = [];
            foreach ($uris as $uri) {
                if (preg_match('/^(\S+) (.+)$/', $uri, $matches)) {
                    $values[$matches[1]] = $matches[2];
                } elseif (preg_match('/^(.+)/', $uri, $matches)) {
                    $values[$matches[1]] = '';
                }
            }
            $uriLabels[$customVocabId] = $values;
        }
        return $uriLabels[$customVocabId];
    }

    /**
     * Trim and normalize end of lines of a string.
     */
    protected function cleanString($string): string
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], trim((string) $string));
    }

    /**
     * Helper to return a message of error as normal view.
     */
    protected function viewError403(): ViewModel
    {
        // TODO Return a normal page instead of an exception.
        // throw new \Omeka\Api\Exception\PermissionDeniedException('Forbidden access.');
        $message = 'Forbidden access.'; // @translate
        $this->getResponse()
            ->setStatusCode(\Laminas\Http\Response::STATUS_CODE_403);
        $view = new ViewModel([
            'message' => $message,
        ]);
        return $view
            ->setTemplate('error/403');
    }
}
