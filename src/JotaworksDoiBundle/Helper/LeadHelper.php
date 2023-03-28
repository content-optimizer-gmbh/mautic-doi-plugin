<?php

namespace MauticPlugin\JotaworksDoiBundle\Helper;

use Doctrine\DBAL\Connection;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Helper\CustomFieldHelper;

class LeadHelper {

    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function leadFieldUpdate($leadFieldUpdate, $leadModel, $lead, $ip = null ) {

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

    public function getDoNotContactStatus(int $contactId, string $channel): int
    {
        $q = $this->connection->createQueryBuilder();

        $q->select('dnc.reason')
            ->from(MAUTIC_TABLE_PREFIX.'lead_donotcontact', 'dnc')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('dnc.lead_id', ':contactId'),
                    $q->expr()->eq('dnc.channel', ':channel')
                )
            )
            ->setParameter('contactId', $contactId)
            ->setParameter('channel', $channel)
            ->setMaxResults(1);

        $status = $q->execute()->fetchColumn();

        if (false === $status) {
            return DoNotContact::IS_CONTACTABLE;
        }

        return (int) $status;
    }

    public function getLeadLists(int $contactId): array
    {
        $q = $this->connection->createQueryBuilder();

        $q->select('lead_lists_leads.leadlist_id')
            ->from(MAUTIC_TABLE_PREFIX.'lead_lists_leads')
            ->where(
                $q->expr()->andX(
                    $q->expr()->eq('lead_lists_leads.lead_id', ':contactId')
                )
            )
            ->setParameter('contactId', $contactId);

        $leadLists = $q->execute()->fetchFirstColumn();

        if (!is_array($leadLists)) {
            return [];
        }

        return $leadLists;
    }

}