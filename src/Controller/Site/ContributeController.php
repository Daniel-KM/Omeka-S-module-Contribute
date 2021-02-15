<?php declare(strict_types=1);

namespace Contribute\Controller\Site;

use Contribute\Api\Representation\ContributionRepresentation;
use Contribute\Form\ContributeForm;
use Laminas\Mvc\Controller\AbstractActionController;
// TODO Use the admin resource form, but there are some differences in features (validation by field, possibility to update the item before validate correction, anonymous, fields is more end user friendly and enough in most of the case), themes and security issues, so not sure it is simpler.
// use Omeka\Form\ResourceForm;
use Laminas\View\Model\ViewModel;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
use Omeka\Stdlib\Message;

class ContributeController extends AbstractActionController
{
    public function addAction()
    {
        $resourceType = $this->params('resource');

        $resourceTypeMap = [
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }
        // $resourceName = $resourceTypeMap[$resourceType];

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

        $currentUrl = $this->url()->fromRoute(null, [], true);

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');
        $form->get('submit')->setLabel('Add'); // @translate

        $contributive = $this->contributiveData();
        if (!$contributive->isContributive()) {
            $this->messenger()->addError('No resource can be added. Ask the administrator for more information.'); // @translate
        } elseif ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $proposal = $this->prepareProposal($data);
                // The resource isn’t updated, but the proposition of contribute
                // is saved for moderation.
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
                $this->messenger()->addError('An error occurred: check your input.'); // @translate
                $this->messenger()->addFormErrors($form);
            }
        }

        $contributionFields = $this->viewHelpers()->get('contributionFields');

        return new ViewModel([
            'site' => $this->currentSite(),
            'form' => $form,
            'resource' => null,
            'contribution' => null,
            'fields' => $contributionFields(),
        ]);
    }

    public function editAction()
    {
        $api = $this->api();
        $resourceType = $this->params('resource');
        $resourceId = $this->params('id');

        $resourceTypeMap = [
            'item' => 'items',
            'media' => 'media',
            'item-set' => 'item_sets',
        ];
        // Useless, because managed by route, but the config may be overridden.
        if (!isset($resourceTypeMap[$resourceType])) {
            return $this->notFoundAction();
        }
        $resourceName = $resourceTypeMap[$resourceType];

        // Allow to check if the resource is public for the user.
        /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource */
        $resource = $api
            ->searchOne($resourceName, ['id' => $resourceId])
            ->getContent();
        if (empty($resource)) {
            return $this->notFoundAction();
        }

        $settings = $this->settings();
        $user = $this->identity();
        $contributeMode = $settings->get('contribute_mode');

        $token = $this->checkToken($resource);
        if (!$token
            && (
                !in_array($contributeMode, ['user', 'open'])
                || ($contributeMode === 'user' && !$user)
            )
        ) {
            return $this->viewError403();
        }

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

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        $contributive = $this->contributiveData($resource->resourceTemplate());
        if (!$contributive->isContributive()) {
            $this->messenger()->addError('This resource cannot be edited. Ask the administrator for more information.'); // @translate
        } elseif ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'edit-resource-submit' => null]);
                $proposal = $this->prepareProposal($data, $resource);
                // The resource isn’t updated, but the proposition of contribute
                // is saved for moderation.
                $response = null;
                if (empty($contribution)) {
                    $data = [
                        'o:resource' => ['o:id' => $resourceId],
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
                        $this->messenger()->addSuccess('Contribution successfully submitted!'); // @translate
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
                $this->messenger()->addError('An error occurred: check your input.'); // @translate
                $this->messenger()->addFormErrors($form);
            }
        }

        $contributionFields = $this->viewHelpers()->get('contributionFields');

        return new ViewModel([
            'site' => $this->currentSite(),
            'form' => $form,
            'resource' => $resource,
            'contribution' => $contribution,
            'fields' => $contributionFields($resource, $contribution),
        ]);
    }

    protected function prepareContributionEmail(ContributionRepresentation $contribution): void
    {
        $emails = $this->settings()->get('contribute_notify', []);
        if (empty($emails)) {
            return;
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
    }

    /**
     * Prepare the proposal for saving.
     *
     * The check is done comparing the keys of original values and the new ones.
     *
     * @todo Manage all types of data, in particular custom vocab.
     * @todo Factorize with \Contribute\Admin\ContributeController::validateContribution()
     *
     * @param array $proposal
     * @param AbstractResourceEntityRepresentation|null $resource
     * @return array
     */
    protected function prepareProposal(array $proposal, AbstractResourceEntityRepresentation $resource = null)
    {
        $result = [];

        // Clean data.
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

        // Process only editable keys.
        $resourceTemplate = $resource ? $resource->resourceTemplate() : null;
        $contributive = $this->contributiveData($resourceTemplate);

        // Process editable properties first.
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
                switch ($type) {
                    case 'literal':
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
                    case strtok($type, ':') === 'resource':
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
                    case in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']):
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
        $propertyIds = $this->propertyIdsByTerms();
        foreach ($proposalFillableTerms as $term) {
            if (!isset($propertyIds[$term])) {
                continue;
            }
            $propertyId = $propertyIds[$term];
            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
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
                } elseif (array_key_exists('@uri', $proposedValue)) {
                    $type = 'resource';
                } else {
                    $type = 'literal';
                }
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                switch ($type) {
                    case 'literal':
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
                    case strtok($type, ':') === 'resource':
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
                    case in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']):
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
     * Trim and normalize end of lines of a string.
     *
     * @param string $string
     * @return string
     */
    protected function cleanString($string)
    {
        return str_replace(["\r\n", "\n\r", "\r"], ["\n", "\n", "\n"], trim($string));
    }

    /**
     * Helper to return a message of error as normal view.
     *
     * @return \Laminas\View\Model\ViewModel
     */
    protected function viewError403()
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
