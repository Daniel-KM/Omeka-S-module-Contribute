<?php declare(strict_types=1);
namespace Contribute;

return [
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
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'linkContribute' => Service\ViewHelper\LinkContributeFactory::class,
            'contributionFields' => Service\ViewHelper\ContributionFieldsFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ContributeForm::class => Form\ContributeForm::class,
            Form\Element\ArrayQueryTextarea::class => Form\Element\ArrayQueryTextarea::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Contribute\Controller\Admin\Contribution' => Controller\Admin\ContributionController::class,
            'Contribute\Controller\Site\Contribute' => Controller\Site\ContributeController::class,
            'Contribute\Controller\Site\GuestBoard' => Controller\Site\GuestBoardController::class,
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
                'class' => 'contributions far fa-edit',
                'route' => 'admin/contribution',
                // 'resource' => Controller\Admin\ContributionController::class,
                // 'privilege' => 'browse',
                'pages' => [
                    [
                        'route' => 'admin/contribution/id',
                        'controller' => Controller\Admin\ContributionController::class,
                        'visible' => false,
                    ],
                    [
                        'route' => 'admin/annotation/default',
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
                    'contribute' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            'route' => '/:resource/add',
                            'constraints' => [
                                'resource' => 'item|media|item-set',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Contribute\Controller\Site',
                                'controller' => 'contribute',
                                'resource' => 'item',
                                'action' => 'add',
                            ],
                        ],
                    ],
                    'contribute-id' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            // TODO Use controller delegator or override the default site route?
                            // Overrides core public site resources only for edit.
                            'route' => '/:resource/:id/edit',
                            'constraints' => [
                                'resource' => 'item|media|item-set',
                                'id' => '\d+',
                            ],
                            'defaults' => [
                                '__NAMESPACE__' => 'Contribute\Controller\Site',
                                'controller' => 'contribute',
                                'action' => 'edit',
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
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/contribution',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Contribute\Controller\Site',
                                        'controller' => 'GuestBoard',
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
            'contribute_mode' => false,
            'contribute_notify' => [],
            'contribute_template_default' => null,
            'contribute_properties_editable_mode' => 'all',
            'contribute_properties_editable' => [],
            'contribute_properties_fillable_mode' => 'all',
            'contribute_properties_fillable' => [],
            'contribute_properties_datatype' => [
                'literal',
                'uri',
            ],
            'contribute_property_queries' => [],
            // Days.
            'contribute_token_duration' => 60,
        ],
    ],
];
