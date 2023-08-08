<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;

use Mautic\LeadBundle\Event\ContactIdentificationEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\JotaworksDoiBundle\Event\DoiSuccessful;
use MauticPlugin\JotaworksDoiBundle\Helper\LeadHelper;
use MauticPlugin\JotaworksDoiBundle\DoiEvents;
use MauticPlugin\JotaworksDoiBundle\Integration\Config;


class DoiActionHelper {

    protected $eventDispatcher;

    protected $ipLookupHelper;

    protected $pageModel;

    protected $emailModel;

    protected $auditLogModel;

    protected $leadModel;

    protected $request;

    /**
     * @var LeadHelper
     */
    private $leadHelper;

    /**
     * @var Config
     */
    private $bundleConfig;

    public function __construct($eventDispatcher, $ipLookupHelper, $pageModel, $emailModel, $auditLogModel, $leadModel, $request, LeadHelper $leadHelper, Config $bundleConfig) 
    {
        $this->eventDispatcher = $eventDispatcher;
        $this->ipLookupHelper = $ipLookupHelper;
        $this->pageModel = $pageModel;
        $this->emailModel = $emailModel;
        $this->auditLogModel = $auditLogModel;
        $this->leadModel = $leadModel;
        $this->request = $request->getCurrentRequest();
        $this->leadHelper = $leadHelper;
        $this->bundleConfig = $bundleConfig;
    }

    public function setRequest($request)
    {
        $this->request = $request;
    }    

    public function applyDoiActions($config, $confirmed = false) 
    {
        $this->updateLead($config);
        $this->identifyLead($config['lead_id']);
        $this->trackPageHit($config);
        $this->fireWebhook($config);

        if (!$confirmed) {
            $this->logDoiSuccess($config);
            $this->removeDNC($config['leadEmail']);
        }
    }

    public function fireWebhook($config) 
    {        
        $lead = $this->leadModel->getEntity($config['lead_id']);
        if(!$lead)
        {
            return;
        }
                
        $doiEvent = new DoiSuccessful($lead, $config);
        $this->eventDispatcher->dispatch($doiEvent, DoiEvents::DOI_SUCCESSFUL);
    }

    public function trackPageHit($config) 
    {

        if($this->request)
        {
            $lead = $this->leadModel->getEntity($config['lead_id']);
            if(!$lead)
            {
                return;
            }

            $this->request->request->set('page_url', $config['url']);
            $this->request->query->set('page_url', $config['url']);            
                    
            $this->pageModel->hitPage(null, $this->request, $code = '200', $lead );            
        }

    }

    public function identifyLead($leadId) 
    {
        $clickthrough = ['leadId' => $leadId ];
    
        $event = new ContactIdentificationEvent($clickthrough);
        $this->eventDispatcher->dispatch(LeadEvents::ON_CLICKTHROUGH_IDENTIFICATION, $event);
    }

    public function removeDNC($email)
    {
        $this->emailModel->removeDoNotContact($email);  
    }

    public function logDoiSuccess($config)
    {
        $ip = $this->ipLookupHelper->getIpAddressFromRequest();
        $log = [
            'bundle'    => 'lead',
            'object'    => 'doi',
            'objectId'  => $config['lead_id'],
            'action'    => 'confirm_doi',
            'details'   => $config,
            'ipAddress' => $ip,
        ];
        $this->auditLogModel->writeToLog($log); 
    }

    public function updateLead($config) {

        $addTags    = (!empty($config['add_tags'])) ? $config['add_tags'] : [];
        $removeTags = (!empty($config['remove_tags'])) ? $config['remove_tags'] : [];            
        $addTo      = (!empty($config['addToLists'])) ? $config['addToLists']: [];
        $removeFrom = (!empty($config['removeFromLists'])) ? $config['removeFromLists']: [];
        $leadFieldUpdate = (!empty($config['leadFieldUpdate'])) ? $config['leadFieldUpdate']: [];
        $leadFieldUpdateBefore = (!empty($config['leadFieldUpdateBefore'])) ? $config['leadFieldUpdateBefore']: [];

        $lead = $this->leadModel->getEntity($config['lead_id']);
        if(!$lead)
        {
            return;
        }

        // Change Tags (if any)
        if(!empty($addTags)|| !empty($removeTags)){
            $this->leadModel->modifyTags($lead, $addTags, $removeTags);
        }

        // Add to Lists (if any)
        if (!empty($addTo)) {
            $this->leadModel->addToLists($lead, $addTo);
        }

        // Remove from Lists (if any)
        if (!empty($removeFrom)) {
            $this->leadModel->removeFromLists($lead, $removeFrom);
        }       

        //Update lead value (if any)
        if( !empty($leadFieldUpdate) )
        {
            $ip = $this->ipLookupHelper->getIpAddressFromRequest();
            $this->leadHelper->leadFieldUpdate($leadFieldUpdate, $this->leadModel, $lead, $ip);
        }

        // Confirm the opt-in status
        $this->setSuccessStatus($lead, $config);
    }

    public function setSuccessStatus($lead, $config): void
    {
        $optinStatusField = $config['optinStatusField'];
        $optinSuccessValue = $config['optinSuccessValue'];

        // Update opt-in status field
        if (!empty($optinStatusField) && !empty($optinSuccessValue)) {
            $this->leadModel->setFieldValues($lead, [$optinStatusField => $optinSuccessValue], false);
            $this->leadModel->saveEntity($lead);
        }
    }

}