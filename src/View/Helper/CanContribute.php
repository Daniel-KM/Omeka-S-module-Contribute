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
        $user = $view->identity();
        $setting = $view->plugin('setting');
        $contributeModes = $setting('contribute_modes') ?: [];
        foreach ($contributeModes as $contributeMode) switch ($contributeMode) {
            case 'open':
                return true;
            case 'user':
                if ($user) {
                    return true;
                }
                continue 2;
            case 'user_token':
                if ($user && $skipRequireToken) {
                    return true;
                }
                continue 2;
            case 'token':
                if ($skipRequireToken) {
                    return true;
                }
                continue 2;
            case 'user_role':
                if ($user && in_array($user->getRole(), $setting('contribute_filter_user_roles', []) ?: [])) {
                    return true;
                }
                continue 2;
            case 'auth_cas':
                if ($user && $this->isCasUser && $this->isCasUser($user)) {
                    return true;
                }
                continue 2;
            case 'auth_ldap':
                if ($user && $this->isLdapUser && $this->isLdapUser($user)) {
                    return true;
                }
                continue 2;
            case 'auth_sso':
                if ($user && $this->isSsoUser && $this->isSsoUser($user)) {
                    return true;
                }
                continue 2;
            case 'user_email':
                // The check is not cumulative, so there are early returns.
                if (!$user) {
                    continue 2;
                }
                $email = $user->getEmail();
                $patterns = $setting('contribute_filter_user_emails');
                foreach ($patterns as $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern) {
                        $isRegex = mb_substr($pattern, 0, 1) === '~' && mb_substr($pattern, -1) === '~';
                        if ($isRegex && preg_match($pattern, $email)) {
                            return true;
                        } elseif ($email === $pattern) {
                            return true;
                        }
                    }
                }
                continue 2;
            case 'user_settings':
                // The check is cumulative, so there are early breaks.
                if (!$user) {
                    continue 2;
                }
                $patterns = $setting('contribute_filter_user_settings');
                $userSetting = $view->plugin('userSetting');
                foreach ($patterns as $userSettingKey => $pattern) {
                    $pattern = trim($pattern);
                    if ($pattern === '') {
                        continue 3;
                    }
                    $userSettingValues = $userSetting($userSettingKey);
                    if ($userSettingValues === null || $userSettingValues === '' || $userSettingValues === []) {
                        continue 3;
                    }
                    if (is_scalar($userSettingValues)) {
                        $userSettingValues = [$userSettingValues];
                    }
                    $isRegex = mb_substr($pattern, 0, 1) === '~' && mb_substr($pattern, -1) === '~';
                    if ($isRegex) {
                        if (!array_filter($userSettingValues, fn ($v) => preg_match($pattern, $v))) {
                            continue 3;
                        }
                    } else {
                        if (!in_array($pattern, $userSettingValues)) {
                            continue 3;
                        }
                    }
                }
                return true;
            default;
                continue 2;
        }
        return false;
    }
}
