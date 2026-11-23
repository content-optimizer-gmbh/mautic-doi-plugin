<?php

return [
    'name'        => 'JotaWorks Double-Opt-In',
    'description' => 'Adds a robust and flexible way to add a double-opt-in process (DOI) to any form in Mautic.',
    'version'     => '2.3.0',
    'author'      => 'Sebastian Fahrenkrog (Content Optimizer GmbH)',
    'parameters' => [
        // Messenger transport for the delayed DOI confirmation (link scanner protection).
        // sync:// ignores the delay. For the protection to work set an async DSN in
        // config/local.php, e.g. 'doctrine://default?queue_name=jotaworks_doi', and run
        // a worker: bin/console messenger:consume jotaworks_doi (see README.md).
        'messenger_dsn_jotaworks_doi' => 'sync://',
    ],
    'services' => [
        'events' => [
            'jw.mautic.email.formbundle.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\FormSubscriber::class,
                'arguments' => [
                    '@jw.doi.email.service',
                ],
                'tags'      => ['kernel.event_subscriber']
            ],
            // 2.3.0: bait link {jw_nothuman} in every sent mail when the link scanner protection is on
            'jw.doi.linkscanner.bait.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\LinkScannerBaitSubscriber::class,
                'arguments' => [
                    '@jw.doi.delayed_confirmation',
                    '@jw.doi.email.service',
                ],
                'tags'      => ['kernel.event_subscriber']
            ],
            'jw.mautic.campaign.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\CampaignSubscriber::class,
                'arguments' => [
                    '@jw.doi.email.service',
                ],
                'tags'      => ['kernel.event_subscriber']
            ],
            'jw.mautic.email.report.doi' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\DoiReportSubscriber::class,
                'arguments' => [
                    '@mautic.lead.reportbundle.fields_builder',
                ],
                'tags'      => ['kernel.event_subscriber']
            ],
            'jw.mautic.webhook.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\WebhookSubscriber::class,
                'arguments' => [
                    '@mautic.webhook.model.webhook',
                ],
                'tags'      => ['kernel.event_subscriber']
            ],
            'jw.mautic.queue.subscriber' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\QueueSubscriber::class,
                'arguments' => [
                    '@monolog.logger.mautic',
                    '@jw.doi.actionhelper',
                    '@jw.doi.nothumanclickhelper'
                ],
                'tags'      => ['kernel.event_subscriber']
            ]
        ],
        'forms' => [
            'jw.mautic.form.type.jw_emailsend_list' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Form\Type\EmailSendType::class,
                'arguments' => [
                    '@router',
                    '@translator'
                ],
                'tags'      => ['form.type']
            ],
        ],
        'helpers' => [
            'jw.doi.actionhelper' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Helper\DoiActionHelper::class,
                'arguments' => [
                    '@event_dispatcher',
                    '@mautic.helper.ip_lookup',
                    '@mautic.page.model.page',
                    '@mautic.email.model.email',
                    '@mautic.core.model.auditlog',
                    '@mautic.lead.model.lead',
                    '@request_stack'
                ]
            ],
            'jw.doi.nothumanclickhelper' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Helper\NotHumanClickHelper::class,
                'arguments' => ['@mautic.helper.paths']
            ],
            'jw.doi.email.service' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Service\DoiEmailService::class,
                'arguments' => [
                    '@router',
                    '@mautic.helper.encryption',
                    '@mautic.email.model.email',
                    '@mautic.lead.model.lead',
                    '@mautic.tracker.contact',
                    '@event_dispatcher'
                ]
            ]
        ],
        'other' => [
            // Link scanner protection (2.2.0): settings check + dispatch of the delayed confirmation
            'jw.doi.delayed_confirmation' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Service\DelayedConfirmationService::class,
                'arguments' => [
                    '@mautic.helper.integration',
                    '@messenger.bus.default',
                    '@mautic.helper.ip_lookup',
                    '@request_stack',
                    '@monolog.logger.mautic'
                ]
            ],
            // Handles DelayedDoiConfirmationMessage (transport jotaworks_doi, see DependencyInjection/)
            'jw.doi.delayed_confirmation.handler' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\MessageHandler\DelayedDoiConfirmationHandler::class,
                'arguments' => [
                    '@jw.doi.actionhelper',
                    '@jw.doi.nothumanclickhelper',
                    '@request_stack',
                    '@monolog.logger.mautic'
                ],
                'tag'       => 'messenger.message_handler'
            ]
        ],
        'integrations' => [
            // Name must match the file name JotaworksDoiIntegration.php (IntegrationHelper looks up mautic.integration.<name>)
            'mautic.integration.jotaworksdoi' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Integration\JotaworksDoiIntegration::class,
                'arguments' => [
                    'event_dispatcher',
                    'mautic.helper.cache_storage',
                    'doctrine.orm.entity_manager',
                    'request_stack',
                    'router',
                    'translator',
                    'monolog.logger.mautic',
                    'mautic.helper.encryption',
                    'mautic.lead.model.lead',
                    'mautic.lead.model.company',
                    'mautic.helper.paths',
                    'mautic.core.model.notification',
                    'mautic.lead.model.field',
                    'mautic.plugin.model.integration_entity',
                    'mautic.lead.model.dnc',
                    'mautic.lead.field.fields_with_unique_identifier',
                ],
            ]
        ]
    ],
    'routes' => [
        'public' => [
            'jotaworks_doiauth_index' => [
                'path'       => '/doi/{enc}',
                'controller' => 'MauticPlugin\JotaworksDoiBundle\Controller\DoiController::indexAction',
                'defaults'   => [
                    '_controller' => 'MauticPlugin\JotaworksDoiBundle\Controller\DoiController::indexAction'
                ]
            ],
            'jotaworks_doiauth_nothuman' => [
                'path'       => '/nothuman/{hash}',
                'controller' => 'MauticPlugin\JotaworksDoiBundle\Controller\DoiController::nothumanAction',
                'defaults'   => [
                    '_controller' => 'MauticPlugin\JotaworksDoiBundle\Controller\DoiController::nothumanAction'
                ]
            ]
        ]
    ]
];
