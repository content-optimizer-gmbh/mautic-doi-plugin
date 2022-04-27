<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\JotaworksDoiBundle\Controller;

use Mautic\AssetBundle\Entity\Asset;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CoreBundle\Controller\FormController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Cookie;
use Mautic\LeadBundle\Event\ContactIdentificationEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\JotaworksDoiBundle\Event\DoiSuccessful;
use MauticPlugin\JotaworksDoiBundle\Helper\LeadHelper;
use MauticPlugin\JotaworksDoiBundle\Helper\Base64Helper;
use MauticPlugin\JotaworksDoiBundle\DoiEvents;
            
/**
 * Class DoiController.
 */
class DoiController extends FormController
{

    /**
     * @param string $enc
     * @return JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function indexAction($enc = false)
    {   

            $encryptionHelper = $this->get('mautic.helper.encryption');
            //@var \Mautic\LeadBundle\Model\LeadModel $leadModel */
            $leadModel = $this->getModel('lead');
            $eventDispatcher = $this->get('event_dispatcher');
            $auditLogModel = $this->get('mautic.core.model.auditlog');
            $ipLookupHelper = $this->get('mautic.helper.ip_lookup');

            //Get doi parameters
            if(!$enc)
            {
                http_response_code(400);
                die();
            }
            
            //get base64 string
            $base64 = Base64Helper::prepare_base64_url_decode($enc);
            //decrypt string
            $config = $encryptionHelper->decrypt($base64,true);
            if(!$config ||!is_array($config))
            {
                http_response_code(401);
                die();
            }
            
            //params
            $leadId = $config['lead_id'];
            $lead       = $leadModel->getEntity($leadId);
            if(!$lead)
            {
                http_response_code(400);
                die();
            }            

            $leadEmail = $lead !== null ? $lead->getEmail() : null;

            $url = $config['url'];
            $addTags    = (!empty($config['add_tags'])) ? $config['add_tags'] : [];
            $removeTags = (!empty($config['remove_tags'])) ? $config['remove_tags'] : [];
            
            $addTo      = (!empty($config['addToLists'])) ? $config['addToLists']: [];
            $removeFrom = (!empty($config['removeFromLists'])) ? $config['removeFromLists']: [];
            $leadFieldUpdate = (!empty($config['leadFieldUpdate'])) ? $config['leadFieldUpdate']: [];
            $leadFieldUpdateBefore = (!empty($config['leadFieldUpdateBefore'])) ? $config['leadFieldUpdateBefore']: [];


            //log doi to audit log
            $ip = $ipLookupHelper->getIpAddressFromRequest();
            $config['leadEmail'] = $leadEmail;
            $log = [
                'bundle'    => 'lead',
                'object'    => 'doi',
                'objectId'  => $leadId,
                'action'    => 'confirm_doi',
                'details'   => $config,
                'ipAddress' => $ip,
            ];
            $auditLogModel->writeToLog($log);            

            // Change Tags (if any)
            if(!empty($addTags)|| !empty($removeTags)){
                $leadModel->modifyTags($lead, $addTags, $removeTags);
            }

            // Change Lists (if any)
            if (!empty($addTo)) {
                $leadModel->addToLists($lead, $addTo);
            }

            if (!empty($removeFrom)) {
                $leadModel->removeFromLists($lead, $removeFrom);
            }       

            if( !empty($leadFieldUpdate) )
            {
                LeadHelper::leadFieldUpdate($leadFieldUpdate, $leadModel, $lead, $ip );               
            }

            //remove from do not contact after valid doi 
            $model = $this->getModel('email');
            $model->removeDoNotContact($leadEmail);            
                        
            //identify lead in mautic 
            $clickthrough = ['leadId' => $leadId];
    
            $event = new ContactIdentificationEvent($clickthrough);
            $eventDispatcher->dispatch(LeadEvents::ON_CLICKTHROUGH_IDENTIFICATION, $event);
            
            $doiEvent = new DoiSuccessful($lead, $config);
            $eventDispatcher->dispatch($doiEvent, DoiEvents::DOI_SUCCESSFUL);
    
            //track page hit in mautic 
            $this->request->request->set('page_url', $url);
            $this->request->query->set('page_url', $url);            
            
            $model = $this->getModel('page');
            $model->hitPage(null, $this->request);

            // Redirect to doi sucess page 
            return $this->redirect($url, 301);            


    }

}
