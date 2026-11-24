<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\FormBundle\Event as Events;
use Mautic\FormBundle\FormEvents;
use MauticPlugin\JotaworksDoiBundle\Service\DoiEmailService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class FormSubscriber implements EventSubscriberInterface
{
    private DoiEmailService $doiEmailService;

    public function __construct(DoiEmailService $doiEmailService)
    {
        $this->doiEmailService = $doiEmailService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_ON_BUILD => ['onFormBuilder', 0],
            FormEvents::ON_EXECUTE_SUBMIT_ACTION => ['onFormSubmitActionSendEmail', 0],
        ];
    }

    public function onFormBuilder(Events\FormBuilderEvent $event): void
    {
        $action = [
            'group' => 'mautic.email.actions',
            'label' => 'jw.mautic.email.form.action.sendemail.lead',
            'description' => 'jw.mautic.email.form.action.sendemail.lead.descr',
            'formType' => \MauticPlugin\JotaworksDoiBundle\Form\Type\EmailSendType::class,
            'formTypeOptions' => ['update_select' => 'formaction_properties_email'],
            'formTheme' => '@JotaworksDoi/FormTheme/EmailSendList/emailsend_list_row.html.twig',
            'eventName' => FormEvents::ON_EXECUTE_SUBMIT_ACTION,
            'allowCampaignForm' => true,
        ];

        $event->addSubmitAction('jw.email.send.lead', $action);
    }

    public function onFormSubmitActionSendEmail(Events\SubmissionEvent $event): void
    {
        if (!$event->checkContext('jw.email.send.lead')) {
            return;
        }

        $config = $event->getActionConfig();
        $lead = $event->getSubmission()->getLead();
        $tokens = $event->getTokens();
        $form = $event->getForm();
        $submissionId = $event->getSubmission()->getId();
        $formId = $form->getId();

        $data = [
            'lead_id' => $lead->getId(),
            'url' => $this->doiEmailService->preparePostUrl($config['post_url'] ?? '', $tokens),
            'add_tags' => $config['add_campaign_doi_success_tags'] ?? [],
            'remove_tags' => $config['remove_tags_doi_success_tags'] ?? [],
            'addToLists' => $config['add_campaign_doi_success_lists'] ?? [],
            'removeFromLists' => $config['remove_campaign_doi_success_lists'] ?? [],
            'leadFieldUpdate' => $config['lead_field_update'] ?? '',
            'form_id' => $formId,
            // 2.5.0: lets DOI_SUCCESSFUL listeners find the exact submission
            'submission_id' => $submissionId,
            'hash' => md5(uniqid()),
        ];

        if (!$this->doiEmailService->shouldDoiProcessStart($lead, $data, $submissionId)) {
            return;
        }

        $this->doiEmailService->leadFieldUpdate($config, $lead);
        $this->doiEmailService->sendDoiEmail($lead, $config, $data, $tokens, $submissionId);
        $this->doiEmailService->fireWebhookEvent($lead, $data);
    }
}
