<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;
use Mautic\LeadBundle\Helper\CustomFieldHelper;

class LeadHelper {

    public static function leadFieldUpdate($leadFieldUpdate, $leadModel, $lead, $ip = null ) {

        if(empty($leadFieldUpdate))
        {
            return;
        }

        $leadValueConfigs = explode(',',$leadFieldUpdate );                    
                
        $leadFields = $lead->getFields(true);
        $leadValues = [];
        foreach($leadValueConfigs as $leadValueConfig)
        {
            list($leadFieldAlias, $leadFieldValue) = array_merge( explode( '=', $leadValueConfig ), array( true ) );

            if($leadFieldAlias && $leadFieldValue)
            {

                $timestring = date("d.m.Y H:i:s");
                $leadFieldValue = str_replace('%ip%', $ip, $leadFieldValue);
                $leadFieldValue = str_replace('%timestamp%', $timestring, $leadFieldValue);

                $leadValues[$leadFieldAlias] = $leadFieldValue;
            }

        }

        if(!empty($leadValues) && !empty($leadFields)){
            $leadModel->setFieldValues($lead, CustomFieldHelper::fieldsValuesTransformer($leadFields, $leadValues), false);
            $leadModel->saveEntity($lead); 
        }

    }

}