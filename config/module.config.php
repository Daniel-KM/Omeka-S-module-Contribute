<?php
namespace Correction;

return [
    'api_adapters' => [
        'invokables' => [
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
    'controllers' => [
        'invokables' => [
            'Correction\Controller\Site\Correction' => Controller\Site\CorrectionController::class,
        ],
    ],
    'router' => [
        'routes' => [
            'site' => [
                'child_routes' => [
                    'correction' => [
                        'type' => \Zend\Router\Http\Segment::class,
                        'options' => [
                            // Overrides core public site resources only for edit.
                            'route' => '/:resource/:id/edit',
                            'constraints' => [
                                'resource' => 'item',
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
    ],
];
