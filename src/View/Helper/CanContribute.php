<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use CAS\Mvc\Controller\Plugin\IsCasUser;
use Laminas\View\Helper\AbstractHelper;
use Ldap\Mvc\Controller\Plugin\IsLdapUser;
use SingleSignOn\Mvc\Controller\Plugin\IsSsoUser;

class CanContribute extends AbstractHelper
{
    /**
     * @var \CAS\Mvc\Controller\Plugin\IsCasUser
     */
    protected $isCasUser;

    /**
     * @var \Ldap\Mvc\Controller\Plugin\IsLdapUser
     */
    protected $isLdapUser;

    /**
     * @var \SingleSignOn\Mvc\Controller\Plugin\IsSsoUser
     */
    protected $isSsoUser;

    public function __construct(
        ?IsCasUser $isCasUser,
        ?IsLdapUser $isLdapUser,
        ?IsSsoUser $isSsoUser
    ) {
        $this->isCasUser = $isCasUser;
        $this->isLdapUser = $isLdapUser;
        $this->isSsoUser = $isSsoUser;
    }

    /**
     * Check if the visitor or user can contribute a new resource.
     */
    public function __invoke(bool $skipRequireToken = false): bool
    {
        /**
         * @var \Omeka\Entity\User $user
         */
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
            case 'auth_cas':
                return $user && $this->isCasUser && $this->isCasUser($user);
            case 'auth_ldap':
                return $user && $this->isLdapUser && $this->isLdapUser($user);
            case 'auth_sso':
                return $user && $this->isSsoUser && $this->isSsoUser($user);
            case 'email_regex':
                $pattern = (string) $setting('contribute_email_regex');
                return $user && $pattern && preg_match($pattern, $user->getEmail());
            case 'filter_user_settings':
                // The check is cumulative, so there are early returns.
                if (!$user) {
                    return false;
                }
                $patterns = $setting('contribute_filter_user_settings');
                $userSetting = $view->plugin('userSetting');
                foreach ($patterns as $userSettingKey => $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern === '') {
                        return false;
                    }
                    $userSettingValue = $userSetting($userSettingKey);
                    if (!is_scalar($userSettingValue)) {
                        return false;
                    }
                    $userSettingValue = trim((string) $userSettingValue);
                    if ($userSettingValue === '') {
                        return false;
                    }
                    if (mb_substr($pattern, 0, 1) === '~' && mb_substr($pattern, -1) === '~') {
                        if (!preg_match($pattern, $userSettingValue)) {
                            return false;
                        }
                    } else {
                        if ($userSettingValue !== $pattern) {
                            return false;
                        }
                    }
                }
                return true;
            case 'token':
                return $skipRequireToken;
            case 'open':
                return true;
        }
    }
}
