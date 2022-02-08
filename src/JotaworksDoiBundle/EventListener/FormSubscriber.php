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
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\CoreBundle\Model\AuditLogModel;
use Mautic\EmailBundle\Helper\MailHelper;
use Mautic\FormBundle\Event as Events;
use Mautic\FormBundle\Exception\ValidationException;
use Mautic\FormBundle\Form\Type\SubmitActionRepostType;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Mautic\CoreBundle\Factory\MauticFactory;
use Mautic\LeadBundle\Tracker\ContactTracker;

/**
 * Class FormSubscriber.
 */
class FormSubscriber implements EventSubscriberInterface
{

    /**
     * @var AuditLogModel
     */
    protected $auditLogModel;

    /**
     * @var IpLookupHelper
     */
    protected $ipLookupHelper;

    /**
     * @var CoreParametersHelper
     */
    protected $coreParametersHelper;

    /**
     * @var $factory
     */
    protected $factory;


    /**
     * FormSubscriber constructor.
     *
     * @param IpLookupHelper $ipLookupHelper
     * @param AuditLogModel  $auditLogModel
     */
    public function __construct(IpLookupHelper $ipLookupHelper, AuditLogModel $auditLogModel, CoreParametersHelper $coreParametersHelper, MauticFactory $factory)
    {
        $this->ipLookupHelper       = $ipLookupHelper;
        $this->auditLogModel        = $auditLogModel;
        $this->coreParametersHelper = $coreParametersHelper;
        $this->factory = $factory;
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

    /**
     * @param Events\SubmissionEvent $event
     */
    public function onFormSubmitActionSendEmail(Events\SubmissionEvent $event)
    {	    
        if (!$event->checkContext('jw.email.send.lead')) {
            return;
        }	    
	    
        $config    = $event->getActionConfig();
        $lead      = $event->getSubmission()->getLead();
        $leadEmail = $lead !== null ? $lead->getEmail() : null;
        $tokens    = $event->getTokens();
        $form       = $event->getForm();
        $emailId    = (int) $config['email'];

        /** @var \Mautic\EmailBundle\Model\EmailModel $emailModel */
        $emailModel = $this->factory->getModel('email');
        $email      = $emailModel->getEntity($emailId);
        
        /** @var \Mautic\LeadBundle\Model\LeadModel $leadModel */
        $leadModel = $this->factory->getModel('lead');
	$contactTracker = $this->factory->get(ContactTracker::class);
        
        //make sure the email still exists and is published
        if ($email === null || !$email->isPublished()) {
            return;
        }

        $currentLead       = $contactTracker->getContact();
        if ($currentLead instanceof Lead) {
            //flatten the lead
            $lead        = $currentLead;
            $currentLead = [
                'id' => $lead->getId(),
            ];
            $leadFields = $lead->getProfileFields();

            $currentLead = array_merge($currentLead, $leadFields);
        }        

        //Replace Token in url (if any)        
        $url = str_replace( array_keys($tokens), array_values($tokens), urldecode($config['post_url']));

        //Build doi confirm url 
        $encryptionHelper = $this->factory->get('mautic.helper.encryption');
        $data = [
            'lead_id'  => $lead->getId(), 
            'url' => $url,
            'add_tags' =>  $config['add_campaign_doi_success_tags'],
            'remove_tags' =>  $config['remove_tags_doi_success_tags'],
            'addToLists' =>  $config['add_campaign_doi_success_lists'],
            'removeFromLists' =>  $config['remove_campaign_doi_success_lists']
        ];
        
        $encData = $encryptionHelper->encrypt($data);
	$encData = str_replace(array('+', '/'), array('-', '_'), $encData);

         $doiUrl = $this->factory->get('router')->generate(
            'jotaworks_doiauth_index',
            ['enc' => $encData],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $tokens['{doi_url}'] = str_replace('|','%7C', $doiUrl);

        //Send email             
        if (isset($leadFields['email'])) {
            $options = [
                'source'    => ['form', $event->getSubmission()->getId()],
                'tokens'    => $tokens,
                //todo: make this a flag configurable in formular actions
                'ignoreDNC' => false,
            ];
            $emailModel->sendEmail($email, $currentLead, $options);
        } 

    }

}
