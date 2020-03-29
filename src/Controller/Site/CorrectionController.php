<?php
namespace Correction\Controller\Site;

use Correction\Form\CorrectionForm;
use Omeka\Api\Representation\AbstractResourceEntityRepresentation;
// use Omeka\Form\ResourceForm;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class CorrectionController extends AbstractActionController
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

        $token = $this->checkToken($resource);
        if (!$token) {
            return $this->viewError403();
        }

        $editable = $this->getResourceTemplateSetting($resource);

        $correction = $api
            ->searchOne('corrections', ['resource_id' => $resourceId, 'token_id' => $token->id()])
            ->getContent();

        $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);

        $form = $this->getForm(CorrectionForm::class);
        $form->setAttribute('action', $currentUrl);
        $form->setAttribute('enctype', 'multipart/form-data');
        $form->setAttribute('id', 'edit-resource');

        // $settings = $this->settings();
        // $corrigible = $this->fetchProperties($settings->get('correction_properties_corrigible', []));
        // $fillable = $this->fetchProperties($settings->get('correction_properties_fillable', []));
        $corrigible = $this->fetchProperties($editable['corrigible']);
        $fillable = $this->fetchProperties($editable['fillable']);

        if (empty($corrigible) && empty($fillable)) {
            $this->messenger()->addError('No metadata can be corrected. Ask the publisher for more information.'); // @translate
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
                // The resource isn’t updated, but the proposition of correction
                // is saved for moderation.
                $response = null;
                if (empty($correction)) {
                    $data = [
                        'o:resource' => ['o:id' => $resourceId],
                        'o-module-correction:token' => ['o:id' => $token->id()],
                        'o:email' => $token->email(),
                        'o-module-correction:reviewed' => false,
                        'o-module-correction:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->create('corrections', $data);
                    if ($response) {
                        $this->messenger()->addSuccess('Corrections successfully submitted!'); // @translate
                    }
                } elseif ($proposal !== $correction->proposal()) {
                    $data = [
                        'o-module-correction:reviewed' => false,
                        'o-module-correction:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->update('corrections', $correction->id(), $data, [], ['isPartial' => true]);
                    if ($response) {
                        $this->messenger()->addSuccess('Corrections successfully submitted!'); // @translate
                    }
                } else {
                    $this->messenger()->addWarning('No change.'); // @translate
                    $response = true;
                }
                if ($response) {
                    $eventManager = $this->getEventManager();
                    $eventManager->trigger('correction.submit', $this, [
                        'correction' => $correction,
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
            'correction' => $correction,
            'corrigible' => $corrigible,
            'fillable' => $fillable,
        ]);
    }

    /**
     * Prepare the proposal for saving.
     *
     * The form and this method must use the same keys.
     *
     * @param AbstractResourceEntityRepresentation $resource
     * @param array $proposal
     * @return array
     */
    protected function prepareProposal(AbstractResourceEntityRepresentation $resource, array $proposal)
    {
        // Clean data.
        foreach ($proposal as &$values) {
            // Manage specific posts.
            if (!is_array($values)) {
                continue;
            }
            foreach ($values as &$value) {
                if (isset($value['@value'])) {
                    $value['@value'] = trim($value['@value']);
                }
                if (isset($value['@uri'])) {
                    $value['@uri'] = trim($value['@uri']);
                }
                if (isset($value['@label'])) {
                    $value['@label'] = trim($value['@label']);
                }
            }
        }
        unset($values, $value);

        // Filter data.
        $editable = $this->getResourceTemplateSetting($resource);
        $corrigible = $editable['corrigible'];
        $fillable = $editable['fillable'];
        $proposalCorrigible = array_intersect_key($proposal, array_flip($corrigible));
        $result = [];
        foreach ($corrigible as $term) {
            // TODO Manage all types of data, in particular custom vocab and value suggest.
            /** @var \Omeka\Api\Representation\ValueRepresentation[] $values */
            $values = $resource->value($term, [/*'type' => 'literal',*/ 'all' => true, 'default' => []]);

            $proposedValues = isset($proposalCorrigible[$term]) ? $proposalCorrigible[$term] : [];
            // Don't save corrigible and fillable twice.
            if (in_array($term, $fillable)) {
                unset($fillable[array_search($term, $fillable)]);
            }

            // First, save original values (literal only) and the matching corrections.
            // TODO Check $key and order of values.
            $key = 0;
            foreach ($values as $value) {
                if (!isset($proposedValues[$key])) {
                    continue;
                }

                if ($value->type() != "literal" && $value->type() != "uri") {
                    continue;
                }

                if ($value->type() == 'literal') {
                    $result[$term][] = [
                        'original' => ['@value' => $value->value()],
                        'proposed' => $proposedValues[$key],
                    ];
                } elseif ($value->type() == 'uri') {
                    $result[$term][] = [
                        'original' => ['@label' => $value->value(), '@uri' => $value->uri()],
                        'proposed' => $proposedValues[$key],
                    ];
                }
                // Remove the proposed value from the list of proposed values in order to keep only new corrections to append.
                unset($proposedValues[$key]);
                ++$key;
            }

            // Second, save remaining corrections (no more original or appended).
            foreach ($proposedValues as $proposedValue) {
                if ($proposedValue === '') {
                    continue;
                }
                if (array_key_exists("@uri", $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@uri' => '','@label'=>''],
                        'proposed' => $proposedValue,
                    ];
                } elseif (array_key_exists("@value", $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@value' => ''],
                        'proposed' => $proposedValue,
                    ];
                }
            }
        }

        // Third, save remaining fillable properties.
        $proposalFillable = array_intersect_key($proposal, array_flip($fillable));
        foreach ($fillable as $term) {
            if (!isset($proposalFillable[$term])) {
                continue;
            }
            foreach ($proposalFillable[$term] as $proposedValue) {
                if ($proposedValue === '') {
                    continue;
                }
                if (array_key_exists('@uri', $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@uri' => '', '@label'=>''],
                        'proposed' => $proposedValue,
                    ];
                } elseif (array_key_exists('@value', $proposedValue)) {
                    $result[$term][] = [
                        'original' => ['@value' => ''],
                        'proposed' => $proposedValue,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * List all selected properties by term.
     *
     * @todo Get all properties in one query.
     *
     * @param array $terms
     * @return \Omeka\Api\Representation\\PropertyRepresentation[]
     */
    protected function fetchProperties(array $terms)
    {
        $result = [];
        // Normally, all properties are cached by Doctrine.
        $api = $this->api();
        foreach ($terms as $term) {
            // Use searchOne() to avoid issue when a vocabulary is removed.
            $property = $api->searchOne('properties', ['term' => $term])->getContent();
            if ($property) {
                $result[$term] = $property;
            }
        }
        return $result;
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
        $response = $this->getResponse();
        $response->setStatusCode(\Zend\Http\Response::STATUS_CODE_403);
        $view = new ViewModel;
        $view->setTemplate('error/403');
        $view->setVariable('message', $message);
        return $view;
    }

    protected function getResourceTemplateSetting($resource)
    {
        $settings = $this->settings();
        $api = $this->api();

        $result = [
            'corrigible' => [],
            'fillable' => [],
        ];

        $resourceTemplate = $resource->resourceTemplate();
        if ($resourceTemplate) {
            $correctionPartMap = $this->resourceTemplateCorrectionPartMap($resourceTemplate->id());
            foreach ($correctionPartMap['corrigible'] as $term) {
                $property = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($property) {
                    $result['corrigible'][$property->id()] = $term;
                }
            }
            foreach ($correctionPartMap['fillable'] as $term) {
                $property = $api->searchOne('properties', ['term' => $term])->getContent();
                if ($property) {
                    $result['fillable'][$property->id()] = $term;
                }
            }
        }

        if (!count($result['corrigible']) && !count($result['fillable'])) {
            $result['corrigible'] = $settings->get('correction_properties_corrigible', []);
            $result['fillable'] = $settings->get('correction_properties_fillable', []);
        }

        return $result;
    }
}
