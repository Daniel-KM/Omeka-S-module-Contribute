<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use Laminas\View\Helper\AbstractHelper;

class CanContributeNewResource extends AbstractHelper
{
    /**
     * Check if the visitor or user can contribute a new resource.
     */
    public function __invoke(bool $skipRequireToken = false): bool
    {
        $view = $this->getView();
        $setting = $view->plugin('setting');
        $contributeMode = $setting('contribute_mode') ?: 'user';
        $user = $view->identity();
        switch ($contributeMode) {
            default:
            case 'user':
                return (bool) $user;
            case 'user_token':
                return $user && $skipRequireToken;
            case 'role':
                return $user && in_array($user->getRole(), $setting('contribute_roles', []) ?: []);
            case 'token':
                return $skipRequireToken;
            case 'open':
                return true;
        }
    }
}
