<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;
use Mautic\LeadBundle\Helper\CustomFieldHelper;

class LeadHelper {

    public static function leadFieldUpdate($leadFieldUpdate, $leadModel, $lead, $ip = null ) {

        if(empty($leadFieldUpdate))
        {
            return;
        }

        //get lead field configs 
        $leadValueConfigs = explode(',',$leadFieldUpdate );   
        
        //get current lead fields and values
        $leadFields = $lead->getFields(true);

        $leadValues = [];
        foreach($leadValueConfigs as $leadValueConfig)
        {
            list($leadFieldAlias, $leadFieldValue) = array_merge( explode( '=', $leadValueConfig ), array( true ) );

            // we replace tokens if any
            if($leadFieldAlias && $leadFieldValue)
            {

                //if $leadFieldValue is token then replace with current lead field value
                if( preg_match('/\{.*\}/', $leadFieldValue) )
                {
                    $tokenAlias = str_replace(['{','}'],'',$leadFieldValue);
                    if(isset($leadFields[ $tokenAlias ])) {
                        $leadFieldValue = $leadFields[$tokenAlias]['normalizedValue'];
                    }
                }

                $timestring = date("d.m.Y H:i:s");
                
                //generate token 
                $token = openssl_random_pseudo_bytes(16);
                bin2hex($token);

                $leadFieldValue = str_replace('{doi_ip}', $ip, $leadFieldValue);
                $leadFieldValue = str_replace('{doi_timestamp}', $timestring, $leadFieldValue);
                $leadFieldValue = str_replace('{tokenid}', $token, $leadFieldValue);

                $leadValues[$leadFieldAlias] = $leadFieldValue;
            }

        }

        if(!empty($leadValues) && !empty($leadFields)){
            $leadModel->setFieldValues($lead, CustomFieldHelper::fieldsValuesTransformer($leadFields, $leadValues), false);
            $leadModel->saveEntity($lead); 
        }

    }

}