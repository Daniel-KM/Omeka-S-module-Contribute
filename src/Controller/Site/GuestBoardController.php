<?php declare(strict_types=1);

namespace Contribute\Controller\Site;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;

class GuestBoardController extends AbstractActionController
{
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'browse';
        return $this->forward()->dispatch('Contribute\Controller\Site\GuestBoard', $params);
    }

    public function browseAction()
    {
        // TODO Clarify how to show anonymous deposit via token.

        $user = $this->identity();
        if (!$user) {
            return $this->redirect()->toRoute('top', [], true);
        }

        $query = $this->params()->fromQuery();
        $query['owner_id'] = $user->getId();

        // TODO Add the full browse mechanism for the display of contributions in guest board.
        if (!isset($query['per_page'])) {
            $query['per_page'] = 100;
        }

        $contributions = $this->api()->search('contributions', $query)->getContent();

        $view = new ViewModel([
            'site' => $this->currentSite(),
            'user' => $user,
            'contributions' => $contributions,
            'space' => 'guest',
        ]);
        return $view
            ->setTemplate('guest/site/guest/contribution-browse');
    }

    public function showAction()
    {
        $params = $this->params()->fromRoute();
        $params['controller'] = 'Contribute\Controller\Site\Contribution';
        $params['__CONTROLLER__'] = 'contribution';
        $params['resource'] = 'contribution';
        $params['space'] = 'guest';
        return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
    }

    /**
     * @deprecated Use show.
     */
    public function viewAction()
    {
        $params = $this->params()->fromRoute();
        $params['controller'] = 'Contribute\Controller\Site\Contribution';
        $params['__CONTROLLER__'] = 'contribution';
        $params['resource'] = 'contribution';
        $params['action'] = 'show';
        $params['space'] = 'guest';
        return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
    }

    public function addAction()
    {
        $params = $this->params()->fromRoute();
        $params['controller'] = 'Contribute\Controller\Site\Contribution';
        $params['__CONTROLLER__'] = 'contribution';
        $params['resource'] = 'contribution';
        $params['space'] = 'guest';
        return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
   }

   public function editAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Contribute\Controller\Site\Contribution';
       $params['__CONTROLLER__'] = 'contribution';
       $params['resource'] = 'contribution';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
   }

   public function deleteConfirmAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Contribute\Controller\Site\Contribution';
       $params['__CONTROLLER__'] = 'contribution';
       $params['resource'] = 'contribution';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
   }

   public function deleteAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Contribute\Controller\Site\Contribution';
       $params['__CONTROLLER__'] = 'contribution';
       $params['resource'] = 'contribution';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
   }

   public function submitAction()
   {
       $params = $this->params()->fromRoute();
       $params['controller'] = 'Contribute\Controller\Site\Contribution';
       $params['__CONTROLLER__'] = 'contribution';
       $params['resource'] = 'contribution';
       $params['space'] = 'guest';
       return $this->forward()->dispatch('Contribute\Controller\Site\Contribution', $params);
   }
}
