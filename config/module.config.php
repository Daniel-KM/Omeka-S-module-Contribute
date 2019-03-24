<?php
namespace Correction;

return [
    'api_adapters' => [
        'invokables' => [
            'corrections' => Api\Adapter\CorrectionAdapter::class,
            'correction_tokens' => Api\Adapter\CorrectionTokenAdapter::class,
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
    ],
    'view_helpers' => [
        'invokables' => [
            'showCorrectionLink' => View\Helper\ShowCorrectionLink::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            'Correction\Controller\Admin\Correction' => Controller\Admin\CorrectionController::class,
            'Correction\Controller\Site\Correction' => Controller\Site\CorrectionController::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'defaultSiteSlug' => Service\ControllerPlugin\DefaultSiteSlugFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'correction' => [
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
                                '__NAMESPACE__' => 'Correction\Controller\Site',
                                'controller' => 'correction',
                                'action' => 'edit',
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'correction' => [
                        'type' => \Zend\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/correction',
                            'defaults' => [
                                '__NAMESPACE__' => 'Correction\Controller\Admin',
                                'controller' => 'correction',
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
                                        'id' => '\d+',
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
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
    'correction' => [
        'settings' => [
            'correction_properties' => [],
            'correction_token_duration' => 90,
        ],
    ],
];
