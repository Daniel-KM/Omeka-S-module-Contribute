<?php
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
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Contribute\Controller\Admin\Contribution' => Controller\Admin\ContributionController::class,
            'Contribute\Controller\Site\Contribute' => Controller\Site\ContributeController::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'checkToken' => Mvc\Controller\Plugin\CheckToken::class,
            'contributiveData' => Mvc\Controller\Plugin\ContributiveData::class,
            'resourceTemplateContributionPartMap' => Mvc\Controller\Plugin\ResourceTemplateContributionPartMap::class,
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
                        'type' => \Zend\Router\Http\Segment::class,
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
                        'type' => \Zend\Router\Http\Segment::class,
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
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'contribution' => [
                        'type' => \Zend\Router\Http\Literal::class,
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
                                'type' => \Zend\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Zend\Router\Http\Segment::class,
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
            'contribute_notify' => [],
            'contribute_template_editable' => null,
            'contribute_properties_editable_mode' => 'all',
            'contribute_properties_editable' => [],
            'contribute_properties_fillable_mode' => 'all',
            'contribute_properties_fillable' => [],
            'contribute_properties_datatype' => [
                'literal',
                'uri',
            ],
            'contribute_property_queries' => [],
            'contribute_without_token' => false,
            // Days.
            'contribute_token_duration' => 60,
            // Where the config of resource templates are stored.
            'contribute_resource_template_data' => [],
        ],
    ],
];
