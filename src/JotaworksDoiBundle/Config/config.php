<?php

return [
    'name'        => 'Jotaworks Doi',
    'description' => 'Plugin which provides form doi actions',
    'version'     => '1.3',
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
