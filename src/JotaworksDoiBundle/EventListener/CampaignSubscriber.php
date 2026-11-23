<?php

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\CampaignBundle\CampaignEvents;
use Mautic\CampaignBundle\Event\CampaignBuilderEvent;
use Mautic\CampaignBundle\Event\CampaignExecutionEvent;
use MauticPlugin\JotaworksDoiBundle\DoiEvents;
use MauticPlugin\JotaworksDoiBundle\Form\Type\EmailSendType;
use MauticPlugin\JotaworksDoiBundle\Service\DoiEmailService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CampaignSubscriber implements EventSubscriberInterface
{
    private DoiEmailService $doiEmailService;

    public function __construct(DoiEmailService $doiEmailService)
    {
        $this->doiEmailService = $doiEmailService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignEvents::CAMPAIGN_ON_BUILD => ['onCampaignBuild', 0],
            DoiEvents::ON_CAMPAIGN_TRIGGER_ACTION => ['onCampaignTriggerAction', 0],
        ];
    }

    public function onCampaignBuild(CampaignBuilderEvent $event): void
    {
        $event->addAction(
            'doi.email.send',
            [
                'label' => 'jw.mautic.email.campaign.send.doi',
                'description' => 'jw.mautic.email.campaign.send.doi.descr',
                'eventName' => DoiEvents::ON_CAMPAIGN_TRIGGER_ACTION,
                'formType' => EmailSendType::class,
                'formTypeOptions' => ['update_select' => 'campaignevent_properties_email'],
                'formTheme' => '@JotaworksDoi/FormTheme/EmailSendList/emailsend_list_row.html.twig',
                'channel' => 'email',
                'channelIdField' => 'email',
            ]
        );
    }

    public function onCampaignTriggerAction(CampaignExecutionEvent $event): void
    {
        if (!$event->checkContext('doi.email.send')) {
            return;
        }

        $config = $event->getConfig();
        $lead = $event->getLead();
        $tokens = [];

        $data = [
            'lead_id' => $lead->getId(),
            'url' => $this->doiEmailService->preparePostUrl($config['post_url'] ?? '', $tokens),
            'add_tags' => $config['add_campaign_doi_success_tags'] ?? [],
            'remove_tags' => $config['remove_tags_doi_success_tags'] ?? [],
            'addToLists' => $config['add_campaign_doi_success_lists'] ?? [],
            'removeFromLists' => $config['remove_campaign_doi_success_lists'] ?? [],
            'leadFieldUpdate' => $config['lead_field_update'] ?? '',
            'hash' => md5(uniqid()),
        ];

        $this->doiEmailService->leadFieldUpdate($config, $lead);
        $this->doiEmailService->sendDoiEmail($lead, $config, $data, $tokens, 0);
        $this->doiEmailService->fireWebhookEvent($lead, $data);

        $event->setResult(true);
    }
}
