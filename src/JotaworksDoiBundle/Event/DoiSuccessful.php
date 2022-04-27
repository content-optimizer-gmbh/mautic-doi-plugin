<?php

namespace MauticPlugin\JotaworksDoiBundle\Event;

class DoiSuccessful extends \Symfony\Contracts\EventDispatcher\Event
{
    /**
     * Undocumented variable
     *
     * @var \Mautic\LeadBundle\Entity\Lead $lead, array $config
     */
    public $lead;

    /**
     * Undocumented variable
     *
     * @var array
     */
    public $config;

    public function __construct(\Mautic\LeadBundle\Entity\Lead $lead, array $config) {
        $this->lead = $lead;
        $this->config = $config;
    }
}
