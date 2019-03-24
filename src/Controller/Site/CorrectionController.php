<?php
namespace Correction\Controller\Site;

use Correction\Form\CorrectionForm;
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
        $resource = $api
            ->searchOne($resourceName, ['id' => $resourceId])
            ->getContent();
        if (empty($resource)) {
            return $this->notFoundAction();
        }

        // Check the token and rights.
        $token = $this->params()->fromQuery('token');
        if (empty($token)) {
            return $this->viewError403();
        }

        /** @var \Correction\Api\Representation\CorrectionTokenRepresentation $token */
        $token = $api
            ->searchOne('correction_tokens', ['token' => $token, 'resource_id' => $resourceId])
            ->getContent();
        if (empty($token)) {
            return $this->viewError403();
        }

        // Update the token with last accessed time.
        $api->update(
            'correction_tokens',
            $token->id(),
            ['o-module-correction:accessed' => 'now'],
            [],
            ['isPartial' => true]
        );

        // TODO Add a message for expiration.
        if ($token->isExpired()) {
            return $this->viewError403();
        }

        $settings = $this->settings();
        $corrigible = $settings->get('correction_properties', []);

        $correction = $api
            ->searchOne('corrections', ['resource_id' => $resourceId, 'token_id' => $token->id()])
            ->getContent();

        $currentUrl = $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true);

        $form = $this->getForm(CorrectionForm::class);
        $form->setAttribute('action', $currentUrl);
        $form->setAttribute('enctype', 'multipart/form-data');
        $form->setAttribute('id', 'edit-resource');

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $form->setData($data);
            // TODO There is no check currently (html form), except the csrf.
            if ($form->isValid()) {
                // TODO Manage file data.
                // $fileData = $this->getRequest()->getFiles()->toArray();
                $proposal = $corrigible
                    ? array_intersect_key($data, array_flip($corrigible))
                    : array_diff_key($data, ['csrf' => null, 'correct-resource-submit' => null]);
                $proposal = $this->cleanProposal($proposal);
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
                } elseif ($proposal !== $correction->proposal()) {
                    $data = [
                        'o-module-correction:reviewed' => false,
                        'o-module-correction:proposal' => $proposal,
                    ];
                    $response = $this->api($form)->update('corrections', $correction->id(), $data, [], ['isPartial' => true]);
                } else {
                    $this->messenger()->addWarning('No change.'); // @translate
                }
                if ($response) {
                    $this->messenger()->addSuccess('Corrections successfully submitted!'); // @translate
                    return $this->redirect()->toUrl($currentUrl);
                }
            } else {
                $this->messenger()->addError('An error occurred: check your input.'); // @translate
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        $view->setVariable('resource', $resource);
        $view->setVariable('correction', $correction);
        $view->setVariable('corrigible', $corrigible);
        return $view;
    }

    protected function cleanProposal($proposal)
    {
        foreach ($proposal as &$values) {
            foreach ($values as &$value) {
                $value['@value'] = trim($value['@value']);
            }
        }
        return $proposal;
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
}
