<?php

return [
    'name'        => 'Jotaworks Doi',
    'description' => 'Plugin which provides form doi actions',
    'version'     => '1.0',
    'author'      => 'Jotaworks',
    'services' => [
        'events' => [
            'jw.mautic.email.formbundle.subscriber' => [
                'class' => \MauticPlugin\JotaworksDoiBundle\EventListener\FormSubscriber::class,
                'arguments' => [
                    'mautic.helper.ip_lookup',
                    'mautic.core.model.auditlog',
                    'mautic.helper.core_parameters',
                    'mautic.factory'
                ],                
            ],
            'jw.mautic.email.report.doi' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\EventListener\DoiReportSubscriber::class,
                'arguments' => [
                    'mautic.lead.reportbundle.fields_builder',
                ],
            ],            
        ],        
        'forms' => [
            'jw.mautic.form.type.jw_emailsend_list' => [
                'class'     => \MauticPlugin\JotaworksDoiBundle\Form\Type\EmailSendType::class,
                'arguments' => ['mautic.factory','translator']
,            ],
        ],
    ],
    'routes' => [
        'public' => [
            'jotaworks_doiauth_index' => [
                'path'       => '/doi/{enc}',
                'controller' => 'JotaworksDoiBundle:Doi:index'
            ]
        ]
    ]    
];
