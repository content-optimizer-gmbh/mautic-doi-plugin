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

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Mautic\FormBundle\Event as Events;
use Mautic\FormBundle\Exception\ValidationException;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\JotaworksDoiBundle\Entity\DoNotContact as DNC;
use MauticPlugin\JotaworksDoiBundle\Helper\LeadHelper;
use MauticPlugin\JotaworksDoiBundle\Helper\Base64Helper;
use MauticPlugin\JotaworksDoiBundle\Helper\DoiActionHelper;
use MauticPlugin\JotaworksDoiBundle\DoiEvents;
use MauticPlugin\JotaworksDoiBundle\Event\DoiStarted;
use MauticPlugin\JotaworksDoiBundle\Integration\Config;

/**
 * Class FormSubscriber.
 */
class FormSubscriber implements EventSubscriberInterface
{

    protected $router;
    
    protected $eventDispatcher;
    
    protected $encryptionHelper;
    
    protected $emailModel;
    
    protected $leadModel;
    
    protected $contactTracker;

    /**
     * @var LeadHelper
     */
    private $leadHelper;

    /**
     * @var DoiActionHelper
     */
    private $doiActionHelper;

    /**
     * @var Config
     */
    private $bundleConfig;


    /**
     * FormSubscriber constructor.
     *
     */
    public function __construct($router, $eventDispatcher, $encryptionHelper, $emailModel, $leadModel, ContactTracker $contactTracker, LeadHelper $leadHelper, DoiActionHelper $doiActionHelper, Config $bundleConfig)
    {
        $this->router = $router;
        $this->eventDispatcher = $eventDispatcher;
        $this->encryptionHelper = $encryptionHelper;
        $this->emailModel = $emailModel;
        $this->leadModel = $leadModel;
        $this->contactTracker = $contactTracker;
        $this->leadHelper = $leadHelper;
        $this->doiActionHelper = $doiActionHelper;
        $this->bundleConfig = $bundleConfig;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            FormEvents::FORM_ON_BUILD            => ['onFormBuilder', 0],
            FormEvents::ON_EXECUTE_SUBMIT_ACTION => [
                ['onFormSubmitActionSendEmail', 0]
            ],
        ];
    }
 
    /**
     * Add a simple email form.
     *
     * @param Events\FormBuilderEvent $event
     */
    public function onFormBuilder(Events\FormBuilderEvent $event)
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }
        
        // Send email to lead
        $action = [
            'group'           => 'mautic.email.actions',
            'label'           => 'jw.mautic.email.form.action.sendemail.lead',
            'description'     => 'jw.mautic.email.form.action.sendemail.lead.descr',
            'formType'        => \MauticPlugin\JotaworksDoiBundle\Form\Type\EmailSendType::class,
            'formTypeOptions' => ['update_select' => 'formaction_properties_email'],
            'formTheme'       => 'JotaworksDoiBundle:FormTheme\EmailSendList',
            'eventName'         => FormEvents::ON_EXECUTE_SUBMIT_ACTION,
            'allowCampaignForm' => true,            
        ];

        $event->addSubmitAction('jw.email.send.lead', $action);
    }

    private function leadFieldUpdate($config, $lead ) {

        if( empty($config['lead_field_update_before']) )
        {
            return;
        }

        $this->leadHelper->leadFieldUpdate($config['lead_field_update_before'], $this->leadModel, $lead );
    }   
    
    /**
     * We would like to send the doi email if: 
     * - there is no do not contact 
     * or
     * - if the do not contact is set by the user and not by a bounced mail or manually set
     */
    private function shouldEmailBeSended($lead): bool
    {
        $doNotContactStatus = $this->leadHelper->getDoNotContactStatus($lead->getId(), 'email');

        switch ($doNotContactStatus) {
            case DNC::BOUNCED:
                return false;
            case DNC::MANUAL:
                return false;
            default:
                return true;
        }
    }

    private function buildDoiConfirmUrl( $data ) 
    {
        $encData = $this->encryptConfig($data);

        $doiUrl = $this->router->generate(
            'jotaworks_doiauth_index',
            ['enc' => $encData],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return str_replace('|','%7C', $doiUrl);
    }

    private function buildClickBaitUrl( $hash )
    {
        $url = $this->router->generate(
            'jotaworks_doiauth_nothuman',
            ['hash' => $hash],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return $url;
    }

    /**
     * Notify external systems via webhook 
     */
    private function fireWebhookEvent($lead, $data) 
    {
        $doiEvent = new DoiStarted($lead, $data);
        $this->eventDispatcher->dispatch($doiEvent, DoiEvents::DOI_STARTED);
    }

    /**
     * Encrypt the doi action config for successful doi 
     */
    private function encryptConfig($data) 
    {
        $encData = $this->encryptionHelper->encrypt($data);
        return Base64Helper::prepare_base64_url_encode($encData);        
    }

    /**
     * Prepare doi success url and replace lead tokens in the url (if any)
     */
    private function preparePostUrl($url, $tokens)
    {
        return str_replace( array_keys($tokens), array_values($tokens), urldecode($url));
    }

    private function sendDoiEmail($lead, $config, $doidata, $tokens, $submissionId)
    {

        if (!$this->shouldEmailBeSended($lead) ) 
        {
            return false;
        } 

        $emailId    = (int) $config['email'];        
        $email      = $this->emailModel->getEntity($emailId);
        if ($email === null || !$email->isPublished()) {
            return false;
        }        
              
        //prepare vars (feels like i should refactor this part)
        $currentLead       = $this->contactTracker->getContact();
        if ($currentLead instanceof Lead) {

            //flatten the lead
            $lead        = $currentLead;
            $currentLead = [
                'id' => $lead->getId(),
            ];
            $leadFields = $lead->getProfileFields();

            $currentLead = array_merge($currentLead, $leadFields);
        }  
        
        //build doi url safe string
        $tokens['{doi_url}'] = $this->buildDoiConfirmUrl( $doidata );
        $tokens['{doi_nothuman}'] = $this->buildClickBaitUrl( $doidata['hash'] );

        $options = [
            'source'    => ['form', $submissionId ],
            'tokens'    => $tokens,
            //see function shouldEmailBeSended for criteria
            'ignoreDNC' => true,
        ];

        //if email address is empty we take email_validate as email address  
        if( !$currentLead['email'] && isset($currentLead['email_validate']) && $currentLead['email_validate'] )
        {
            $currentLead['email'] = $currentLead['email_validate'];
        }

        $this->emailModel->sendEmail($email, $currentLead, $options);
        
    }

    protected function shouldDoiProcessStart($lead, $data, $submissionId) 
    {
        //TODO: Implement rate limiting based on  
        // form submit table and see if: 
        //  - sender ip address + time reaches limit 
        //  - sender ip address + form id + time reaches limit 
        //  - email address + form id + time reaches limit
        // 
        // + make limits configurable in plugin settings 
        // OR: make this a generic anti form spam plugin!

        // Check if feature 'no_email_to_confirmed' is enabled
        if (in_array('no_email_to_confirmed', $this->bundleConfig->getSupportedFeatures())) {
            // Check DNC status of the lead
            $doNotContactStatus = $this->leadHelper->getDoNotContactStatus($lead->getId(), 'email');

            if ($doNotContactStatus === DNC::IS_CONTACTABLE) {
                // Check optin confirmation status of the lead
                if (count($data['treatAsConfirmed']) > 0 && $this->isLeadConfirmed($lead, $data)) {
                    // Run optin success actions and stop further processing
                    $this->doiActionHelper->applyDoiActions($data, true);
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param Events\SubmissionEvent $event
     */
    public function onFormSubmitActionSendEmail(Events\SubmissionEvent $event)
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }

        //only action if this is our form action
        if (!$event->checkContext('jw.email.send.lead')) {
            return;
        }

        $config    = $event->getActionConfig();
        $lead      = $event->getSubmission()->getLead(); 
        $tokens    = $event->getTokens();
        $form      = $event->getForm();
        $submissionId = $event->getSubmission()->getId();
        $formId     = $form->getId();
        $emailId    = (int) $config['email'];        
               
        //Build doi confirm url 
        $data = [
            'lead_id'  => $lead->getId(), 
            'url' => $this->preparePostUrl($config['post_url'], $tokens ),
            'treatAsConfirmed' => $config['treat_as_confirmed'],
            'add_tags' =>  $config['add_campaign_doi_success_tags'],
            'remove_tags' =>  $config['remove_tags_doi_success_tags'],
            'addToLists' =>  $config['add_campaign_doi_success_lists'],
            'removeFromLists' =>  $config['remove_campaign_doi_success_lists'],
            'optinStatusField' => $config['optin_status_field'],
            'optinSuccessValue' => $config['optin_success_value'],
            'leadFieldUpdate' => $config['lead_field_update'],
            'form_id' => $formId,
            'hash' => md5(uniqid())
        ];

        //Check if doi should start 
        if( !$this->shouldDoiProcessStart($lead, $data, $submissionId) )
        {
            return;
        } 
        
        //Update lead field (if configured)
        $this->leadFieldUpdate($config, $lead );                       
        
        //Send double optin email             
        $this->sendDoiEmail($lead, $config, $data, $tokens, $submissionId);

        //Notify mautic webhooks (if any)
        $this->fireWebhookEvent($lead, $data);

    }

    private function isLeadConfirmed($lead, $data): bool
    {
        $treatAsConfirmed = $data['treatAsConfirmed'];
        $optinSuccessTags = $data['add_tags'];
        $optinSuccessLists = $data['addToLists'];
        $optinStatusField = $data['optinStatusField'];
        $optinSuccessValue = $data['optinSuccessValue'];
        $confirmedCriterias = 0;

        // Get tags assigned to the lead
        $leadTags = [];
        foreach ($lead->getTags() as $tag) {
            $leadTags[] = $tag->getTag();
        }

        // Get lists (segments) assigned to the lead
        $leadLists = $this->leadHelper->getLeadLists($lead->getId());

        // Check if lead has all tags assigned
        if (in_array('tags', $treatAsConfirmed)) {
            $confirmedCriterias += (array_intersect($optinSuccessTags, $leadTags) === $optinSuccessTags) ? 1 : 0;
        }
                
        // Check if lead is member of all lists
        if (in_array('segments', $treatAsConfirmed)) {
            $confirmedCriterias += (array_intersect($optinSuccessLists, $leadLists) === $optinSuccessLists) ? 1 : 0;
        }

        // Check if opt-in status field of the lead has the correct value
        if (in_array('status_field', $treatAsConfirmed)) {
            if (!empty($optinStatusField)) {
                $confirmedCriterias += ($lead->getProfileFields()[$optinStatusField] === $optinSuccessValue) ? 1 : 0;
            } else {
                $confirmedCriterias += 1;
            }
        }

        // Check if contact meets all criterias of a successful opt-in
        return ($confirmedCriterias === count($treatAsConfirmed)) ? true : false;
    }

}
