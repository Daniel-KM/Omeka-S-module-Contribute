<?php
namespace Contribute\Controller\Site;

use Contribute\Api\Representation\ContributeRepresentation;
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
            $contribute = $api
                ->searchOne('contributes', ['resource_id' => $resourceId, 'token_id' => $token->id()])
                ->getContent();
            $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);
        } else {
            $contribute = $api
                ->searchOne('contributes', ['resource_id' => $resourceId, 'email' => $user->getEmail(), 'sort_by' => 'id', 'sort_order' => 'desc'])
                ->getContent();
            $currentUrl = $this->url()->fromRoute(null, [], true);
        }

        /** @var \Contribute\Form\ContributeForm $form */
        $form = $this->getForm(ContributeForm::class)
            ->setAttribute('action', $currentUrl)
            ->setAttribute('enctype', 'multipart/form-data')
            ->setAttribute('id', 'edit-resource');

        $fields = $this->prepareFields($resource, $contribute);

        $editable = $this->editableData($resource);
        if (!$editable->isEditable()) {
            $this->messenger()->addError('This resource cannot be corrected. Ask the administrator for more information.'); // @translate
        } elseif ($this->getRequest()->isPost()) {
            $post = $this->params()->fromPost();
            $form->setData($post);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $data = $form->getData();
                $data = array_diff_key($post, ['csrf' => null, 'correct-resource-submit' => null]);
                $proposal = $this->prepareProposal($resource, $data);
                // The resource isn’t updated, but the proposition of contribute
                // is saved for moderation.
                $response = null;
                if (empty($contribute)) {
                    $data = [
                        'o:resource' => ['o:id' => $resourceId],
                        'o-module-contribute:token' => $token ? ['o:id' => $token->id()] : null,
                        'o:email' => $token ? $token->email() : $user->getEmail(),
                        'o-module-contribute:reviewed' => false,
                        'o-module-contribute:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->create('contributes', $data);
                    if ($response) {
                        $this->messenger()->addSuccess('Contributes successfully submitted!'); // @translate
                        $this->prepareContributeEmail($response->getContent());
                    }
                } elseif ($proposal !== $contribute->proposal()) {
                    $data = [
                        'o-module-contribute:reviewed' => false,
                        'o-module-contribute:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->update('contributes', $contribute->id(), $data, [], ['isPartial' => true]);
                    if ($response) {
                        $this->messenger()->addSuccess('Contributes successfully submitted!'); // @translate
                        $this->prepareContributeEmail($response->getContent());
                    }
                } else {
                    $this->messenger()->addWarning('No change.'); // @translate
                    $response = true;
                }
                if ($response) {
                    $eventManager = $this->getEventManager();
                    $eventManager->trigger('contribute.submit', $this, [
                        'contribute' => $contribute,
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
            'contribute' => $contribute,
            'fields' => $fields,
        ]);
    }

    protected function prepareContributeEmail(ContributeRepresentation $contribute)
    {
        $emails = $this->settings()->get('contribute_notify', []);
        if (empty($emails)) {
            return;
        }

        $user = $this->identity();
        if ($user) {
            $message = '<p>' . new Message(
                'User %1$s has corrected resource #%2$s (%3$s).', // @translate
                '<a href="' . $this->url()->fromRoute('admin/id', ['controller' => 'user', 'id' => $user->getId()], ['force_canonical' => true]) . '">' . $user->getName() . '</a>',
                '<a href="' . $contribute->resource()->adminUrl('show', true) . '#contribute">' . $contribute->resource()->id() . '</a>',
                $contribute->resource()->displayTitle()
            ) . '</p>';
        } else {
            $message = '<p>' . new Message(
                'A user has corrected resource #%1$d (%2$s).', // @translate
                '<a href="' . $contribute->resource()->adminUrl('show', true) . '#contribute">' . $contribute->resource()->id() . '</a>',
                $contribute->resource()->displayTitle()
            ) . '</p>';
        }
        $this->sendContributeEmail($emails, $this->translate('[Omeka Contribute] New contribute'), $message); // @translate
    }

    /**
     * Get all fields that are updatable for this resource.
     *
     * The order is the one of the resource template, else the order of terms in
     * the database (Dublin Core first, bibo, foaf, then specific terms).
     *
     * Some contributes may not have the matching fields: it means that the
     * config changed, so the values are no more editable, so they are skipped.
     *
     * The output is similar than $resource->values(), but may contain empty
     * properties, and four more keys, corrigible, fillable, datatype and
     * contributes.
     *
     * <code>
     * array(
     *   {term} => array(
     *     'template_property' => {ResourceTemplatePropertyRepresentation},
     *     'property' => {PropertyRepresentation},
     *     'alternate_label' => {label},
     *     'alternate_comment' => {comment},
     *     'corrigible' => {bool}
     *     'fillable' => {bool}
     *     'datatypes' => {array}
     *     'values' => array(
     *       {ValueRepresentation}, …
     *     ),
     *     'contributes' => array(
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
     * @param ContributeRepresentation $contribute
     * @return array
     */
    protected function prepareFields(AbstractResourceEntityRepresentation $resource, ContributeRepresentation $contribute = null)
    {
        $fields = [];

        $defaultField = [
            'template_property' => null,
            'property' => null,
            'alternate_label' => null,
            'alternate_comment' => null,
            'corrigible' => false,
            'fillable' => false,
            'datatypes' => [],
            'values' => [],
            'contributes' => [],
        ];

        /** @var \Contribute\Mvc\Controller\Plugin\EditableData $editable */
        $editable = $this->editableData($resource);
        $values = $resource->values();
        $resourceTemplate = $resource->resourceTemplate();
        $propertyIds = $this->propertyIdsByTerms();

        // The default template is used when there is no template or when the
        // used one is not configured. $editable has info about that.

        // List the fields for the resource when there is a resource template.
        if ($editable->hasTemplate()) {
            // List the resource template fields first.
            foreach ($editable->template()->resourceTemplateProperties() as $templateProperty) {
                $property = $templateProperty->property();
                $term = $property->term();
                $fields[$term] = [
                    'template_property' => $templateProperty,
                    'property' => $property,
                    'alternate_label' => $templateProperty->alternateLabel(),
                    'alternate_comment' => $templateProperty->alternateComment(),
                    'corrigible' => $editable->isTermCorrigible($term),
                    'fillable' => $editable->isTermFillable($term),
                    'datatypes' => $editable->datatypeTerm($term),
                    'values' => isset($values[$term]['values']) ? $values[$term]['values'] : [],
                    'contributes' => [],
                ];
            }

            // When the resource template is configured, the remaining values
            // are never editable, since they are not in the resource template.
            // TODO Make the properties that are not in a template editable? Currently no.
            if (!$editable->useDefaultProperties()) {
                foreach ($values as $term => $valueInfo) {
                    if (!isset($fields[$term])) {
                        $fields[$term] = $valueInfo;
                        $fields[$term]['corrigible'] = false;
                        $fields[$term]['fillable'] = false;
                        $fields[$term]['datatypes'] = [];
                        $fields[$term]['contributes'] = [];
                        $fields[$term] = array_replace($defaultField, $fields[$term]);
                    }
                }
            }
        }

        // Append default fields from the main config, with or without template.
        if ($editable->useDefaultProperties()) {
            $api = $this->api();
            // Append the values of the resource.
            foreach ($values as $term => $valueInfo) {
                if (!isset($fields[$term])) {
                    $fields[$term] = $valueInfo;
                    $fields[$term]['template_property'] = null;
                    $fields[$term]['corrigible'] = $editable->isTermCorrigible($term);
                    $fields[$term]['fillable'] = $editable->isTermFillable($term);
                    $fields[$term]['datatypes'] = $editable->datatypeTerm($term);
                    $fields[$term]['contributes'] = [];
                    $fields[$term] = array_replace($defaultField, $fields[$term]);
                }
            }

            // Append the fillable fields.
            if ($editable->fillableMode() !== 'blacklist') {
                foreach ($editable->fillableProperties() as $term => $propertyId) {
                    if (!isset($fields[$term])) {
                        $fields[$term] = [
                            'template_property' => null,
                            'property' => $api->read('properties', $propertyId)->getContent(),
                            'alternate_label' => null,
                            'alternate_comment' => null,
                            'corrigible' => $editable->isTermCorrigible($term),
                            'fillable' => true,
                            'datatypes' => $editable->datatypeTerm($term),
                            'values' => [],
                            'contributes' => [],
                        ];
                    }
                }
            }
        }

        // Initialize contributes with existing values, then append contributes.
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
                $fields[$term]['contributes'][] = [
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

        if (!$contribute) {
            return $fields;
        }

        $proposals = $contribute->proposal();

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

        // Fill the proposed contributes, according to the original value.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributes'] as &$fieldContribute) {
                $proposed = null;
                $type = $fieldContribute['type'];
                if (!$editable->isTermDatatype($term, $type)) {
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
        // contributes may have been accepted or the resource updated, so check
        // if there are remaining contributes that were validated.
        foreach ($fields as $term => &$field) {
            if (!isset($proposals[$term])) {
                continue;
            }
            foreach ($field['contributes'] as &$fieldContribute) {
                $proposed = null;
                $type = $fieldContribute['type'];
                if (!$editable->isTermDatatype($term, $type)) {
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

        // Append only remaining contributes that are fillable.
        // Other ones are related to an older config.
        $proposals = array_intersect_key(array_filter($proposals), $editable->fillableProperties());
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
                if (!$editable->isTermDatatype($term, $type)) {
                    continue;
                }
                if ($type === 'uri' || in_array(strtok($type, ':'), ['valuesuggest', 'valuesuggestall'])) {
                    $fields[$term]['contributes'][] = [
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
                    $fields[$term]['contributes'][] = [
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
                    $fields[$term]['contributes'][] = [
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
        $editable = $this->editableData($resource);

        // Process corrigible properties first.
        $matches = [];
        switch ($editable->corrigibleMode()) {
            case 'whitelist':
                $proposalCorrigibleTerms = array_keys(array_intersect_key($proposal, $editable->corrigibleProperties()));
                break;
            case 'blacklist':
                $proposalCorrigibleTerms = array_keys(array_diff_key($proposal, $editable->corrigibleProperties()));
                break;
            case 'all':
            default:
                $proposalCorrigibleTerms = array_keys($proposal);
                break;
        }
        foreach ($proposalCorrigibleTerms as $term) {
            /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
            $values = $resource->value($term, ['all' => true, 'default' => []]);
            foreach ($values as $index => $value) {
                if (!isset($proposal[$term][$index])) {
                    continue;
                }
                $type = $value->type();
                if (!$editable->isTermDatatype($term, $type)) {
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
        switch ($editable->fillableMode()) {
            case 'whitelist':
                $proposalFillableTerms = array_keys(array_intersect_key($proposal, $editable->fillableProperties()));
                break;
            case 'blacklist':
                $proposalFillableTerms = array_diff_key($proposal, $editable->fillableProperties());
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
                if (!$editable->isTermDatatype($term, $type)) {
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
