<?php
namespace Contribute\Controller\Site;

use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;

class GuestBoardController extends AbstractActionController
{
    public function indexAction()
    {
        $params = $this->params()->fromRoute();
        $params['action'] = 'show';
        return $this->forward()->dispatch(__CLASS__, $params);
    }

    public function showAction()
    {
        $user = $this->identity();

        if (isset($user)) {
            $query = $this->params()->fromQuery();
            $query['user_id'] = $user->getId();

            $contributions = $this->api()->search('contributions', $query)->getContent();

            $view = new ViewModel;
            return $view
                ->setTemplate('guest/site/guest/contribution')
                ->setVariable('site', $this->currentSite())
                ->setVariable('contributions', $contributions);
        }
    }
}
