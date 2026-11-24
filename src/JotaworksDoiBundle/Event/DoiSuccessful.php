<?php

namespace MauticPlugin\JotaworksDoiBundle\Event;

use Mautic\LeadBundle\Entity\Lead;

/**
 * Dispatched as DoiEvents::DOI_SUCCESSFUL when a contact confirms a DOI.
 *
 * Since 2.5.0 the event also carries the form and the submission that started the DOI,
 * so listeners can act on exactly that submission. Both are null for DOI links sent before
 * 2.5.0 (the submission ID was not part of the encrypted link) or when the DOI was not
 * started by a form.
 */
class DoiSuccessful extends \Symfony\Contracts\EventDispatcher\Event
{
    /**
     * @var Lead
     */
    public $lead;

    /**
     * Decrypted DOI configuration (lead_id, url, add_tags, remove_tags, addToLists,
     * removeFromLists, leadFieldUpdate, form_id, submission_id, hash).
     *
     * @var array
     */
    public $config;

    private ?int $formId;

    private ?int $submissionId;

    public function __construct(Lead $lead, array $config, ?int $formId = null, ?int $submissionId = null)
    {
        $this->lead         = $lead;
        $this->config       = $config;
        $this->formId       = $formId ?? self::positiveIntOrNull($config['form_id'] ?? null);
        $this->submissionId = $submissionId ?? self::positiveIntOrNull($config['submission_id'] ?? null);
    }

    public function getLead(): Lead
    {
        return $this->lead;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * ID of the form whose submission started the DOI, or null.
     */
    public function getFormId(): ?int
    {
        return $this->formId;
    }

    /**
     * ID of the form submission that started the DOI, or null (links sent before 2.5.0).
     */
    public function getSubmissionId(): ?int
    {
        return $this->submissionId;
    }

    /**
     * @param mixed $value
     */
    private static function positiveIntOrNull($value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
