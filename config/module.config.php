<?php declare(strict_types=1);

namespace Contribute;

return [
    'service_manager' => [
        'factories' => [
            File\Contribution::class => Service\File\ContributionFactory::class,
        ],
    ],
    'api_adapters' => [
        'invokables' => [
            'contributions' => Api\Adapter\ContributionAdapter::class,
            'contribution_tokens' => Api\Adapter\TokenAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
    ],
    'media_ingesters' => [
        'factories' => [
            // This is an internal ingester.
            'contribution' => Service\Media\Ingester\ContributionFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'invokables' => [
            'contributionForm' => View\Helper\ContributionForm::class,
        ],
        'factories' => [
            'contributionFields' => Service\ViewHelper\ContributionFieldsFactory::class,
            'contributionLink' => Service\ViewHelper\ContributionLinkFactory::class,
            'contributionSearchFilters' => Service\ViewHelper\ContributionSearchFiltersFactory::class,
        ],
        'aliases' => [
            // @deprecated
            'linkContribute' => 'contributionLink',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\ArrayQueryTextarea::class => Form\Element\ArrayQueryTextarea::class,
            Form\ContributeForm::class => Form\ContributeForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        'factories' => [
            Form\QuickSearchForm::class => Service\Form\QuickSearchFormFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Contribute\Controller\Site\GuestBoard' => Controller\Site\GuestBoardController::class,
        ],
        'factories' => [
            'Contribute\Controller\Admin\Contribution' => Service\Controller\AdminContributionControllerFactory::class,
            'Contribute\Controller\Site\Contribution' => Service\Controller\SiteContributionControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'checkToken' => Mvc\Controller\Plugin\CheckToken::class,
            'contributiveData' => Mvc\Controller\Plugin\ContributiveData::class,
        ],
        'factories' => [
            'defaultSiteSlug' => Service\ControllerPlugin\DefaultSiteSlugFactory::class,
            'propertyIdsByTerms' => Service\ControllerPlugin\PropertyIdsByTermsFactory::class,
            'sendContributionEmail' => Service\ControllerPlugin\SendContributionEmailFactory::class,
        ],
    ],
    'navigation' => [
        'AdminResource' => [
            'contribution' => [
                'label' => 'Contributions', // @translate
                'class' => 'o-icon- contributions fa-edit',
                'route' => 'admin/contribution',
                // 'resource' => Controller\Admin\ContributionController::class,
                // 'privilege' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/contribution/default',
                        'controller' => Controller\Admin\ContributionController::class,
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/contribution/id',
                        'controller' => Controller\Admin\ContributionController::class,
                        'visible' => false,
                    ],
                ],
            ],
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'contribution' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:resource/add',
                            'constraints' => [
                                'resource' => 'contribution|item-set|item|media',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Contribute\Controller\Site',
                                'controller' => 'contribution',
                                'resource' => 'contribution',
                                'action' => 'add',
                            ],
                        ],
                    ],
                    'contribution-id' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            // TODO Use controller delegator or override the default site route?
                            // Overrides core public site resources for unused actions.
                            'route' => '/:resource/:id/:action',
                            'constraints' => [
                                'resource' => 'contribution|item-set|item|media',
                                'id' => '\d+',
                                // "show" can be used only for contribution, so use "view".
                                // "view" is always forwarded to "show".
                                // "add" is added to manage complex workflow. The id is useless for it.
                                'action' => 'add|view|edit|delete-confirm|delete|submit',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Contribute\Controller\Site',
                                'controller' => 'contribution',
                                'resource' => 'contribution',
                                // Use automatically the core routes, since it is not in the constraints.
                                'action' => 'show',
                            ],
                        ],
                    ],
                    'guest' => [
                        // The default values for the guest user route are kept
                        // to avoid issues for visitors when an upgrade of
                        // module Guest occurs or when it is disabled.
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/guest',
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'contribution' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/contribution[/:action]',
                                    'constraints' => [
                                        'action' => 'add|browse',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Contribute\Controller\Site',
                                        'controller' => 'guest-board',
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'contribution-id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/contribution/:id[/:action]',
                                    'constraints' => [
                                        // TODO Remove "view".
                                        'action' => 'show|view|edit|delete-confirm|delete|submit',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Contribute\Controller\Site',
                                        'controller' => 'guest-board',
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'contribution' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/contribution',
                            'defaults' => [
                                '__NAMESPACE__' => 'Contribute\Controller\Admin',
                                'controller' => 'contribution',
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:id[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'js_translate_strings' => [
        'Prepare tokens to edit selected', // @translate
        'Prepare tokens to edit all', // @translate
    ],
    'blocksdisposition' => [
        'views' => [
            /* No event currently.
             'item_set_show' => [
                'Contribute',
            ],
            */
            'item_show' => [
                'Contribute',
            ],
            /* No event currently.
             'media_show' => [
                'Contribute',
            ],
            */
            'item_browse' => [
                'Contribute',
            ],
        ],
    ],
    'contribute' => [
        'settings' => [
            'contribute_mode' => 'user',
            'contribute_roles' => [],
            'contribute_templates' => [
                // The id is set during install.
                'Contribution',
            ],
            'contribute_templates_media' => [
            ],
            // Days.
            'contribute_token_duration' => 60,
            'contribute_allow_update' => 'submission',
            'contribute_notify_recipients' => [],
            'contribute_author_emails' => [],
            'contribute_message_add' => '',
            'contribute_message_edit' => '',
            'contribute_author_confirmations' => [],
            'contribute_author_confirmation_subject' => '',
            'contribute_author_confirmation_body' => '',
            'contribute_reviewer_confirmation_subject' => '',
            'contribute_reviewer_confirmation_body' => '',
        ],
    ],
];
