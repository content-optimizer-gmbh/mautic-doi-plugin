<?php

/*
 * @copyright   2019 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\LeadBundle\EventListener\ReportSubscriber;
use Mautic\LeadBundle\Report\FieldsBuilder;
use Mautic\ReportBundle\Event\ReportBuilderEvent;
use Mautic\ReportBundle\Event\ReportGeneratorEvent;
use Mautic\ReportBundle\Event\ReportDataEvent;
use Mautic\ReportBundle\ReportEvents;
use MauticPlugin\MauticCustomReportBundle\Entity\CustomCreatedContactLog;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use MauticPlugin\JotaworksDoiBundle\Integration\Config;

class DoiReportSubscriber implements EventSubscriberInterface
{
    const REPORT_NAME = 'jw.doi';

    /**
     * @var FieldsBuilder
     */
    private $fieldsBuilder;

    /**
     * @var Config
     */
    private $bundleConfig;

    /**
     * @param FieldsBuilder $fieldsBuilder
     */
    public function __construct(FieldsBuilder $fieldsBuilder, Config $bundleConfig)
    {
        $this->fieldsBuilder = $fieldsBuilder;
        $this->bundleConfig = $bundleConfig;
    }

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            ReportEvents::REPORT_ON_BUILD    => ['onReportBuilder', 0],
            ReportEvents::REPORT_ON_GENERATE => ['onReportGenerate', 0],
            ReportEvents::REPORT_ON_DISPLAY  => ['onReportDisplay', 0],            
        ];
    }

    /**
     * Add available tables and columns to the report builder lookup.
     *
     * @param ReportBuilderEvent $event
     */
    public function onReportBuilder(ReportBuilderEvent $event)
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }

        if (!$event->checkContext([self::REPORT_NAME])) {
            return;
        }

        $columns = $this->fieldsBuilder->getLeadFieldsColumns('l.');


        $addColumns = [
            'al.ip_address' => [
                'label' => 'jw.mautic.report.doi.ip',
                'type'  => 'string',
            ],
            'al.date_added' => [
                'label' => 'jw.mautic.report.doi.date_added',
                'type'  => 'datetime',
            ],
            'al.details' => [
                'label' => 'jw.mautic.report.doi.details',
                'type'  => 'html',
            ],            
        ];

        $data = [
            'display_name' => 'jw.mautic.report.doi',
            'columns'      => array_merge($columns, $addColumns),
            'filters'      => $columns,
        ];
        $event->addTable(self::REPORT_NAME, $data, ReportSubscriber::GROUP_CONTACTS);

        unset($columns, $filters, $columns, $data);
    }

    /**
     * Initialize the QueryBuilder object to generate reports from.
     *
     * @param ReportGeneratorEvent $event
     */
    public function onReportGenerate(ReportGeneratorEvent $event)
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }

        if (!$event->checkContext([self::REPORT_NAME])) {
            return;
        }

        $qb = $event->getQueryBuilder();
        
        $qb->from(MAUTIC_TABLE_PREFIX.'audit_log', 'al');
        $qb->innerJoin('al',MAUTIC_TABLE_PREFIX.'leads', 'l', 'l.id = al.object_id AND l.email IS NOT NULL');


        $qb
                ->andWhere('al.object = :object')
                ->setParameter('object', 'doi');
    
        $qb->andWhere('al.bundle = :bundle')
                ->setParameter('bundle', 'lead');

        $qb->andWhere('al.action = :action')
        ->setParameter('action', 'confirm_doi');                
    

        $event->applyDateFilters($qb, 'date_added', 'al');

        $event->setQueryBuilder($qb);
    }

    public function onReportDisplay(ReportDataEvent $event)
    {
        if(!$this->bundleConfig->isPublished()) {
            return;
        }

        if (!$event->checkContext([self::REPORT_NAME])) {
            return;
        }

        $data = $event->getData();
        if ( isset($data[0]['details']) ) {

            foreach ($data as &$row) 
            {
                if (isset($row['details'])) {

                    $details = unserialize($row['details']);
                    $html = '';
                    foreach($details as $key => $value) 
                    {
                        if(is_array($value) && empty($value))
                        {
                            continue;
                        }

                        if(is_array($value))
                        {
                            $value = print_r($value,true);
                        }

                        $html .= $key.' '.$value.' / ';
                    }


                    $row['details'] = $html;
                }

            }

            $event->setData($data);
        }
    }    
}
