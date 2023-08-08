<?php

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\WebhookBundle\Event\WebhookBuilderEvent;
use Mautic\WebhookBundle\Model\WebhookModel;
use Mautic\WebhookBundle\WebhookEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

use MauticPlugin\JotaworksDoiBundle\DoiEvents;
use MauticPlugin\JotaworksDoiBundle\Event\DoiStarted;
use MauticPlugin\JotaworksDoiBundle\Event\DoiSuccessful;
use MauticPlugin\JotaworksDoiBundle\Integration\Config;

class WebhookSubscriber implements EventSubscriberInterface
{
    /**
     * @var WebhookModel
     */
    private $webhookModel;

    /**
     * @var Config
     */
    private $bundleConfig;

    public function __construct(WebhookModel $webhookModel, Config $bundleConfig)
    {
        $this->webhookModel = $webhookModel;
        $this->bundleConfig = $bundleConfig;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            DoiEvents::DOI_STARTED       => ['onDoiStarted', 0],
            DoiEvents::DOI_SUCCESSFUL       => ['onDoiSuccessful', 0],
            WebhookEvents::WEBHOOK_ON_BUILD => ['onWebhookBuild', 0],
        ];
    }

    /**
     * Add event triggers and actions.
     */
    public function onWebhookBuild(WebhookBuilderEvent $event)
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }

        $doiStarted = [
            'label'       => 'jw.doi.webhook.event.doi_started',
            'description' => 'jw.doi.webhook.event.doi_started_desc',
        ];

        $event->addEvent(DoiEvents::DOI_STARTED, $doiStarted);
        
        $doiSuccessful = [
            'label'       => 'jw.doi.webhook.event.doi_successful',
            'description' => 'jw.doi.webhook.event.doi_successful_desc',
        ];

        $event->addEvent(DoiEvents::DOI_SUCCESSFUL, $doiSuccessful);
    }

    /**
     * Just dispatches all data to our webhook.
     *
     * @param \Mautic\LeadBundle\Entity\Lead $lead
     * @param array $config
     * @return void
     */
    public function onDoiStarted(DoiStarted $event): void
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }

        $this->webhookModel->queueWebhooksByType(
            DoiEvents::DOI_STARTED,
            [
                'lead' => $event->lead->convertToArray(),
                'config' => $event->config,
            ],
        );
    }
    
    /**
     * Just dispatches all data to our webhook.
     *
     * @param \Mautic\LeadBundle\Entity\Lead $lead
     * @param array $config
     * @return void
     */
    public function onDoiSuccessful(DoiSuccessful $event): void
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }
        
        $this->webhookModel->queueWebhooksByType(
            DoiEvents::DOI_SUCCESSFUL,
            [
                'lead' => $event->lead->convertToArray(),
                'config' => $event->config,
            ],
        );
    }
}