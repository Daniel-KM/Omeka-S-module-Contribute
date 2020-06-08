<?php
namespace Contribute\Controller\Site;

use Contribute\Api\Representation\ContributionRepresentation;
use Contribute\Form\ContributeForm;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
// use Omeka\Form\ResourceForm;
use Omeka\Stdlib\Message;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class ContributeController extends AbstractActionController
{
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

        $token = $this->checkToken($resource);
        if (!$token && !($user && $settings->get('contribute_without_token'))) {
            return $this->viewError403();
        }

        if ($token) {
            $contribution = $api
                ->searchOne('contributions', ['resource_id' => $resourceId, 'token_id' => $token->id()])
                ->getContent();
            $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
        } else {
            $contribution = $api
                ->searchOne('contributions', ['resource_id' => $resourceId, 'email' => $user->getEmail(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                ->getContent();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        }

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        $fields = $this->prepareFields($resource, $contribution);

        $contributive = $this->contributiveData($resource);
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
                $proposal = $this->prepareProposal($resource, $data);
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
                        $this->messenger()->addSuccess('Contributions successfully submitted!'); // @translate
                        $this->prepareContributionEmail($response->getContent());
                    }
                } elseif ($proposal !== $contribution->proposal()) {
                    $data = [
                        'o-module-contribute:reviewed' => false,
                        'o-module-contribute:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->update('contributions', $contribution->id(), $data, [], ['isPartial' => true]);
                    if ($response) {
                        $this->messenger()->addSuccess('Contributions successfully submitted!'); // @translate
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

        return new ViewModel([
            'form' => $form,
            'resource' => $resource,
            'contribution' => $contribution,
            'fields' => $fields,
        ]);
    }

    protected function prepareContributionEmail(ContributionRepresentation $contribution)
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
     * Get all fields that are updatable for this resource.
     *
     * The order is the one of the resource template, else the order of terms in
     * the database (Dublin Core first, bibo, foaf, then specific terms).
     *
     * Some contributions may not have the matching fields: it means that the
     * config changed, so the values are no more editable, so they are skipped.
     *
     * The output is similar than $resource->values(), but may contain empty
     * properties, and four more keys, editable, fillable, datatype and
     * contributions.
     *
     * <code>
     * array(
     *   {term} => array(
     *     'template_property' => {ResourceTemplatePropertyRepresentation},
     *     'property' => {PropertyRepresentation},
     *     'alternate_label' => {label},
     *     'alternate_comment' => {comment},
     *     'editable' => {bool}
     *     'fillable' => {bool}
     *     'datatypes' => {array}
     *     'values' => array(
     *       {ValueRepresentation}, …
     *     ),
     *     'contributions' => array(
     *       array(
     *         'type' => {string},
     *         'original' => array(
     *           'value' => {ValueRepresentation},
     *           '@value' => {string},
     *           '@resource' => {int}
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ),
     *         'proposed' => array(
     *           '@value' => {string},
     *           '@resource' => {int}
     *           '@uri' => {string},
     *           '@label' => {string},
     *         ),
     *       ), …
     *     ),
     *   ),
     * )
     * </code>
     *
     * @return array
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param ContributionRepresentation $contribution
     * @return array
     */
    protected function prepareFields(AbstractResourceEntityRepresentation $resource, ContributionRepresentation $contribution = null)
    {
        $fields = [];

        $defaultField = [
            'template_property' => null,
            'property' => null,
            'alternate_label' => null,
            'alternate_comment' => null,
            'editable' => false,
            'fillable' => false,
            'datatypes' => [],
            'values' => [],
            'contributions' => [],
        ];

        /** @var \Contribute\Mvc\Controller\Plugin\ContributiveData $contributive */
        $contributive = $this->contributiveData($resource);
        $values = $resource->values();
        $resourceTemplate = $resource->resourceTemplate();
        $propertyIds = $this->propertyIdsByTerms();

        // The default template is used when there is no template or when the
        // used one is not configured. $contributive has info about that.

        // List the fields for the resource when there is a resource template.
        if ($contributive->hasTemplate()) {
            // List the resource template fields first.
            foreach ($contributive->template()->resourceTemplateProperties() as $templateProperty) {
                $property = $templateProperty->property();
                $term = $property->term();
                $fields[$term] = [
                    'template_property' => $templateProperty,
                    'property' => $property,
                    'alternate_label' => $templateProperty->alternateLabel(),
                    'alternate_comment' => $templateProperty->alternateComment(),
                    'editable' => $contributive->isTermEditable($term),
                    'fillable' => $contributive->isTermFillable($term),
                    'datatypes' => $contributive->datatypeTerm($term),
                    'values' => isset($values[$term]['values']) ? $values[$term]['values'] : [],
                    'contributions' => [],
                ];
            }

            // When the resource template is configured, the remaining values
            // are never editable, since they are not in the resource template.
            // TODO Make the properties that are not in a template editable? Currently no.
            if (!$contributive->useDefaultProperties()) {
                foreach ($values as $term => $valueInfo) {
                    if (!isset($fields[$term])) {
                        $fields[$term] = $valueInfo;
                        $fields[$term]['editable'] = false;
                        $fields[$term]['fillable'] = false;
                        $fields[$term]['datatypes'] = [];
                        $fields[$term]['contributions'] = [];
                        $fields[$term] = array_replace($defaultField, $fields[$term]);
                    }
                }
            }
        }

        // Append default fields from the main config, with or without template.
        if ($contributive->useDefaultProperties()) {
            $api = $this->api();
            // Append the values of the resource.
            foreach ($values as $term => $valueInfo) {
                if (!isset($fields[$term])) {
                    $fields[$term] = $valueInfo;
                    $fields[$term]['template_property'] = null;
                    $fields[$term]['editable'] = $contributive->isTermEditable($term);
                    $fields[$term]['fillable'] = $contributive->isTermFillable($term);
                    $fields[$term]['datatypes'] = $contributive->datatypeTerm($term);
                    $fields[$term]['contributions'] = [];
                    $fields[$term] = array_replace($defaultField, $fields[$term]);
                }
            }

            // Append the fillable fields.
            if ($contributive->fillableMode() !== 'blacklist') {
                foreach ($contributive->fillableProperties() as $term => $propertyId) {
                    if (!isset($fields[$term])) {
                        $fields[$term] = [
                            'template_property' => null,
                            'property' => $api->read('properties', $propertyId)->getContent(),
                            'alternate_label' => null,
                            'alternate_comment' => null,
                            'editable' => $contributive->isTermEditable($term),
                            'fillable' => true,
                            'datatypes' => $contributive->datatypeTerm($term),
                            'values' => [],
                            'contributions' => [],
                        ];
                    }
                }
            }
        }

        // Initialize contributions with existing values, then append contributions.
        foreach ($fields as $term => $field) {
            /** @var \Omeka\Api\Representation\ValueRepresentation $value */
            foreach ($field['values'] as $value) {
                // Method value() is label or value depending on type.
                $type = $value->type();
                // TODO No need to check if the datatype is managed?
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    $val = null;
                    $res = null;
                    $uri = $value->uri();
                    $label = $value->value();
                } elseif (strtok($type, ':') === 'resource') {
                    $vr = $value->valueResource();
                    $val = null;
                    $res = $vr ? $vr->id() : null;
                    $uri = null;
                    $label = null;
                } else {
                    $val = $value->value();
                    $res = null;
                    $uri = null;
                    $label = null;
                }
                $fields[$term]['contributions'][] = [
                    // The type cannot be changed.
                    'type' => $type,
                    'original' => [
                        'value' => $value,
                        '@value' => $val,
                        '@resource' => $res,
                        '@uri' => $uri,
                        '@label' => $label,
                    ],
                    'proposed' => [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ],
                ];
            }
        }

        if (!$contribution) {
            return $fields;
        }

        $proposals = $contribution->proposal();

        // Clean old proposals.
        foreach ($proposals as $term => $termProposal) {
            foreach ($termProposal as $key => $proposal) {
                if (isset($proposal['proposed']['@uri'])) {
                    $proposal['original']['@uri'] = $this->cleanString($proposal['original']['@uri']);
                    $proposal['original']['@label'] = $this->cleanString($proposal['original']['@label']);
                    if (($proposal['original']['@uri'] === '' && $proposal['proposed']['@uri'] === '')
                        && ($proposal['original']['@label'] === '' && $proposal['proposed']['@label'] === '')
                    ) {
                        unset($proposals[$term][$key]);
                    }
                } elseif (isset($proposal['proposed']['@resource'])) {
                    $proposal['original']['@resource'] = (int) $proposal['original']['@resource'];
                    if (!$proposal['original']['@resource'] && !$proposal['proposed']['@resource']) {
                        unset($proposals[$term][$key]);
                    }
                } else {
                    $proposal['original']['@value'] = $this->cleanString($proposal['original']['@value']);
                    if ($proposal['original']['@value'] === '' && $proposal['proposed']['@value'] === '') {
                        unset($proposals[$term][$key]);
                    }
                }
            }
        }
        $proposals = array_filter($proposals);
        if (!count($proposals)) {
            return $fields;
        }

        // Fill the proposed contributions, according to the original value.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributions'] as &$fieldContribute) {
                $proposed = null;
                $type = $fieldContribute['type'];
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@uri'])
                            && $proposal['original']['@uri'] === $fieldContribute['original']['@uri']
                            && $proposal['original']['@label'] === $fieldContribute['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribute['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif (strtok($type, ':') === 'resource') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@resource'])
                            && (int) $proposal['original']['@resource']
                            && $proposal['original']['@resource'] === $fieldContribute['original']['@resource']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribute['proposed'] = [
                        '@value' => null,
                        '@resource' => (int) $proposed['@resource'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['original']['@value'])
                            && $proposal['original']['@value'] === $fieldContribute['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribute['proposed'] = [
                        '@value' => $proposed['@value'],
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldContribute);

        // Fill the proposed contribute, according to the existing values: some
        // contributions may have been accepted or the resource updated, so check
        // if there are remaining contributions that were validated.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributions'] as &$fieldContribute) {
                $proposed = null;
                $type = $fieldContribute['type'];
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@uri'])
                            && $proposal['proposed']['@uri'] === $fieldContribute['original']['@uri']
                            && $proposal['proposed']['@label'] === $fieldContribute['original']['@label']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribute['proposed'] = [
                        '@value' => null,
                        '@resource' => null,
                        '@uri' => $proposed['@uri'],
                        '@label' => $proposed['@label'],
                    ];
                } elseif (strtok($type, ':') === 'resource') {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@resource'])
                            && (int) $proposal['proposed']['@resource']
                            && $proposal['proposed']['@resource'] === $fieldContribute['original']['@resource']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribute['proposed'] = [
                        '@value' => null,
                        '@resource' => (int) $proposed['@resource'],
                        '@uri' => null,
                        '@label' => null,
                    ];
                } else {
                    foreach ($proposals[$term] as $keyProposal => $proposal) {
                        if (isset($proposal['proposed']['@value'])
                            && $proposal['proposed']['@value'] === $fieldContribute['original']['@value']
                        ) {
                            $proposed = $proposal['proposed'];
                            break;
                        }
                    }
                    if (is_null($proposed)) {
                        continue;
                    }
                    $fieldContribute['proposed'] = [
                        '@value' => $proposed['@value'],
                        '@resource' => null,
                        '@uri' => null,
                        '@label' => null,
                    ];
                }
                unset($proposals[$term][$keyProposal]);
            }
        }
        unset($field, $fieldContribute);

        // Append only remaining contributions that are fillable.
        // Other ones are related to an older config.
        $proposals = array_intersect_key(array_filter($proposals), $contributive->fillableProperties());
        foreach ($proposals as $term => $termProposal) {
            $propertyId = $propertyIds[$term];
            if (!isset($propertyIds[$term])) {
                continue;
            }
            $typeTemplate = null;
            if ($resourceTemplate) {
                $resourceTemplateProperty = $resourceTemplate->resourceTemplateProperty($propertyId);
                if ($resourceTemplateProperty) {
                    $typeTemplate = $resourceTemplateProperty->dataType();
                }
            }
            foreach ($termProposal as $proposal) {
                if ($typeTemplate) {
                    $type = $typeTemplate;
                } elseif (isset($proposal['proposed']['@uri'])) {
                    $type = 'uri';
                } elseif (isset($proposal['proposed']['@resource'])) {
                    $type = 'resource';
                } else {
                    $type = 'literal';
                }
                if (!$contributive->isTermDatatype($term, $type)) {
                    continue;
                }
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => null,
                            '@uri' => $proposal['proposed']['@uri'],
                            '@label' => $proposal['proposed']['@label'],
                        ],
                    ];
                } elseif (strtok($type, ':') === 'resource') {
                    $fields[$term]['contributions'][] = [
                        'type' => $type,
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => null,
                            '@resource' => (int) $proposal['proposed']['@resource'],
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                } else {
                    $fields[$term]['contributions'][] = [
                        'type' => 'literal',
                        'original' => [
                            'value' => null,
                            '@resource' => null,
                            '@value' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                        'proposed' => [
                            '@value' => $proposal['proposed']['@value'],
                            '@resource' => null,
                            '@uri' => null,
                            '@label' => null,
                        ],
                    ];
                }
            }
        }

        return $fields;
    }

    /**
     * Prepare the proposal for saving.
     *
     * The check is done comparing the keys of original values and the new ones.
     *
     * @todo Manage all types of data, in particular custom vocab.
     * @todo Factorize with \Contribute\Admin\ContributeController::validateContribute()
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $proposal
     * @return array
     */
    protected function prepareProposal(AbstractResourceEntityRepresentation $resource, array $proposal)
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
        $contributive = $this->contributiveData($resource);

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
            $values = $resource->value($term, ['all' => true, 'default' => []]);
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
                    case in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']);
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
        $resourceTemplate = $resource->resourceTemplate();
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
                $values = $resource->value($term, ['all' => true, 'default' => []]);
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
                    case in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall']);
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
     * @return \Zend\View\Model\ViewModel
     */
    protected function viewError403()
    {
        // TODO Return a normal page instead of an exception.
        // throw new \Omeka\Api\Exception\PermissionDeniedException('Forbidden access.');
        $message = 'Forbidden access.'; // @translate
        $this->getResponse()
            ->setStatusCode(\Zend\Http\Response::STATUS_CODE_403);
        $view = new ViewModel;
        return $view
            ->setTemplate('error/403')
            ->setVariable('message', $message);
    }
}
