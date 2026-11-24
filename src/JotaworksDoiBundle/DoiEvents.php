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
    const DOI_STARTED = 'mautic.doi_started';

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
     *     'form_id',
     *     'submission_id',   // since 2.5.0
     * ];
     *
     * Since 2.5.0 DoiSuccessful::getFormId() and ::getSubmissionId() return both IDs
     * (null for links sent before 2.5.0).
     *
     * @var string
     */
    const DOI_SUCCESSFUL = 'mautic.doi_successful';

    /**
     * Campaign action event when DOI email is triggered.
     */
    const ON_CAMPAIGN_TRIGGER_ACTION = 'jotaworks.doi.on.campaign.trigger.action';
}
