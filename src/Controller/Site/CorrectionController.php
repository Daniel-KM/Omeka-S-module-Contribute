<?php
namespace Correction\Controller\Site;

use Omeka\Form\ResourceForm;
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
            ['o-module-correction:accessed' => true],
            [],
            ['isPartial' => true]
        );

        $correctionName = 'correction_' . $resourceId . '_' . $token->id();
        $correction = $this->settings()->get($correctionName, []);

        $form = $this->getForm(ResourceForm::class);
        $form->setAttribute('action', $this->url()->fromRoute(null, [], ['query' => ['token' => $token->token()]], true));
        $form->setAttribute('enctype', 'multipart/form-data');
        $form->setAttribute('id', 'edit-item');

        if ($this->getRequest()->isPost()) {
            $data = $this->params()->fromPost();
            $correction = $data;
            $form->setData($data);
            if ($form->isValid()) {
                // $fileData = $this->getRequest()->getFiles()->toArray();
                // $fileData = [];
                // Resource is not updated.
                $this->settings()->set($correctionName, $data);
                // $response = $this->api($form)->update('resources', $this->params('id'), $data, $fileData);
                // if ($response) {
                //     $this->messenger()->addSuccess('Resource successfully updated'); // @translate
                //     return $this->redirect()->toUrl($response->getContent()->url());
                // }
            } else {
                $this->messenger()->addFormErrors($form);
            }
        }

        $view = new ViewModel;
        $view->setVariable('form', $form);
        $view->setVariable('resource', $resource);
        $view->setVariable('correction', $correction);
        return $view;
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
