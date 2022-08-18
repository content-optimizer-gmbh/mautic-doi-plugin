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
use MauticPlugin\JotaworksDoiBundle\QueueEvents;
use MauticPlugin\JotaworksDoiBundle\Helper\DoiActionHelper;
use Mautic\QueueBundle\Queue\QueueConsumerResults;
use Symfony\Bridge\Monolog\Logger;
use Mautic\QueueBundle\Event\QueueConsumerEvent;

/**
 * Class FormSubscriber.
 */
class QueueSubscriber implements EventSubscriberInterface
{

    /**
     * @var Logger
     */
    private $logger;


    protected $secondsToWait = 1*60;

    /**
     * QueueSubscriber constructor.
     * 
     */
    public function __construct(Logger $logger, DoiActionHelper $doiActionHelper, $notHumanClickHelper)
    {
        $this->logger = $logger;        
        $this->doiActionHelper = $doiActionHelper;
        $this->notHumanClickHelper = $notHumanClickHelper; 
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            QueueEvents::DOI_SUCCESSFUL => ['onDoiSuccessful']
        ];
    }

    /**
     * Checks if some process was clicking the click bait link 
     * and reset the click bait marker for the doi job
     */
    private function checkIfDoiCancel($config)
    {
        //get doi code
        $hash = $config['hash'];
        if(!$hash)
        {
            return false;
        }

        if( $this->notHumanClickHelper->isRunning($hash) ) 
        {
            //remove click marker for running doi process
            $this->notHumanClickHelper->reset($hash);

            return true;
        }

        return false; 
    }

    /**
     * Checks if the queue worker should process this job or need to wait
     */
    private function waitForProcessingTime($doiActivationTime) 
    {
        $now = time();
        $processingStartTime = $doiActivationTime + $this->secondsToWait;
        if( $now >= $processingStartTime ) 
        {
            return true;
        }

        return false;
    }
 
    public function onDoiSuccessful(QueueConsumerEvent $event) 
    {
        
        $payload = $event->getPayload();
        $config = $payload['config'];
        $doiActivationTime = $payload['doiActivationTime'];
        $request = isset( $payload['request'] ) ? $payload['request'] : null;

        // Also reject messages when processing causes any other exception.
        try {

            //check if event should be processed now or defer processing
            if( !$this->waitForProcessingTime($doiActivationTime) )
            {
                //we defer processing this doi confirmation until we waited for xx minutes
                $event->setResult(QueueConsumerResults::TEMPORARY_REJECT);
                return;
            }

            //check if doi should be canceled (some process clicked on the bait link)
            if( $this->checkIfDoiCancel($config) ) 
            {
                //we remove doi from working queue without setting any thing 
                $event->setResult(QueueConsumerResults::ACKNOWLEDGE);
                return;
            }
        
            //execute all pending doi actions for successful doi confirmation
            $this->doiActionHelper->setRequest($request);
            $this->doiActionHelper->applyDoiActions($config);            

            $event->setResult(QueueConsumerResults::ACKNOWLEDGE);

        } catch (\Exception $e) {

            $event->setResult(QueueConsumerResults::TEMPORARY_REJECT);

            // Log the exception with event payload as context.
            if ($this->logger) {

                $logPayload = $payload;
                unset($logPayload['request']);
                $this->logger->addError(
                    'QUEUE CONSUMER ERROR => TEMPORARY_REJECT Details: ('.QueueEvents::DOI_SUCCESSFUL.'): '.$e->getMessage(),
                    $logPayload
                );
            }
        }

    }
}
