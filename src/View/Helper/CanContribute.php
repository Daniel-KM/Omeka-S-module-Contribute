<?php declare(strict_types=1);

namespace Contribute\View\Helper;

use AdvancedResourceTemplate\Api\Representation\ResourceTemplateRepresentation;
use CAS\Mvc\Controller\Plugin\IsCasUser;
use Laminas\View\Helper\AbstractHelper;
use Ldap\Mvc\Controller\Plugin\IsLdapUser;
use Omeka\Settings\Settings;
use SingleSignOn\Mvc\Controller\Plugin\IsSsoUser;

class CanContribute extends AbstractHelper
{
    /**
     * @var array
     */
    protected $contributeConfig;

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

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function __construct(
        array $contributeConfig,
        ?IsCasUser $isCasUser,
        ?IsLdapUser $isLdapUser,
        ?IsSsoUser $isSsoUser,
        Settings $settings
    ) {
        $this->contributeConfig = $contributeConfig;
        $this->isCasUser = $isCasUser;
        $this->isLdapUser = $isLdapUser;
        $this->isSsoUser = $isSsoUser;
        $this->settings = $settings;
    }

    /**
     * Check if the visitor or user can contribute a new resource.
     */
    public function __invoke(?ResourceTemplateRepresentation $resourceTemplate = null, bool $skipRequireToken = false): bool
    {
        /**
         * @var \Omeka\Entity\User $user
         */
        $view = $this->getView();
        $user = $view->identity();
        if ($resourceTemplate) {
            $contributable = $resourceTemplate->dataValue('contribute_template_contributable');
            if ($contributable !== 'global' && $contributable !== 'specific') {
                return false;
            }
            $isSpecificTemplate = $contributable=== 'specific';
        } else {
            $isSpecificTemplate = false;
        }
        $contributeModes = ($isSpecificTemplate
            ? $resourceTemplate->dataValue('contribute_modes')
            : $this->settings->get('contribute_modes')) ?: [];
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
                if ($user
                    && in_array(
                        $user->getRole(),
                        ($isSpecificTemplate
                            ? $resourceTemplate->dataValue('contribute_filter_user_roles')
                            : $this->settings->get('contribute_filter_user_roles', [])) ?: []
                    )
                ) {
                    return true;
                }
                continue 2;
            case 'auth_cas':
                if ($user && $this->isCasUser && $this->isCasUser->__invoke($user)) {
                    return true;
                }
                continue 2;
            case 'auth_ldap':
                if ($user && $this->isLdapUser && $this->isLdapUser->__invoke($user)) {
                    return true;
                }
                continue 2;
            case 'auth_sso':
                if ($user && $this->isSsoUser && $this->isSsoUser->__invoke($user)) {
                    return true;
                }
                continue 2;
            case 'user_email':
                // The check is not cumulative, so there are early returns.
                if (!$user) {
                    continue 2;
                }
                $email = $user->getEmail();
                $patterns = ($isSpecificTemplate
                    ? $resourceTemplate->dataValue('contribute_filter_user_emails')
                    : $this->settings->get('contribute_filter_user_emails')) ?: [];
                foreach ($patterns ?: [] as $pattern) {
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
                $patterns = ($isSpecificTemplate
                    ? $resourceTemplate->dataValue('contribute_filter_user_settings')
                    : $this->settings->get('contribute_filter_user_settings')) ?: [];
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
                        if (!array_filter($userSettingValues, fn ($v) => is_scalar($v) && preg_match($pattern, $v))) {
                            continue 3;
                        }
                    } else {
                        if (!in_array($pattern, $userSettingValues)) {
                            continue 3;
                        }
                    }
                }
                return true;
            default:
                continue 2;
        }
        return false;
    }
}
