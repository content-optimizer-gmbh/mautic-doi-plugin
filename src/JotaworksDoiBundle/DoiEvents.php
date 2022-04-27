<?php

/*
 * @copyright   2014 Mautic Contributors. All rights reserved
 * @author      Mautic
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\JotaworksDoiBundle;

/**
 * Class DoiEvents
 * Events available for JotaworksDoiBundle.
 */
final class DoiEvents
{
    /**
     * The mautic.doi_successful event is dispatched right before a lead is persisted.
     *
     * The event listener receives the following arguments:
     * 
     * @param \Mautic\LeadBundle\Entity\Lead $lead
     * @param array $config
     * 
     * $config is of the following format:
     * $data = [
     *     'lead_id',
     *     'url',
     *     'add_tags',
     *     'remove_tags',
     *     'addToLists',
     *     'removeFromLists',
     *     'leadFieldUpdate',
     * ];
     *
     * @var string
     */
    const DOI_SUCCESSFUL = 'mautic.doi_successful';
}
