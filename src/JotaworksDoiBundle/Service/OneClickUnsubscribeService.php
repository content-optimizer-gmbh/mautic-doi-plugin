<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Membership\MembershipManager;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\EmailBundle\Entity\Email;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\DoNotContact;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\JotaworksDoiBundle\Entity\UnsubscribeRule;
use MauticPlugin\JotaworksDoiBundle\Integration\JotaworksDoiIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Configurable RFC 8058 one-click unsubscribe (2.4.0).
 *
 * Decision per click: rule of the email, else the global setting of the integration. "auto" picks
 * "segments" for segment (list) emails, "campaign" for emails sent by a campaign, else "block".
 * A mode that does not fit the email uses the fallback; if that does not fit either, or any action
 * fails, the contact is blocked like Mautic core does, so no unsubscribe gets lost.
 */
class OneClickUnsubscribeService
{
    public const LOG_BUNDLE = 'jwdoi';
    public const LOG_OBJECT = 'email';
    public const LOG_ACTION = 'oneclick_unsubscribe';

    public const OPTIN_FIELD   = 'optin_status';
    public const OPTIN_REVOKED = 'Widerrufen';

    /** Window around email_stats.date_sent to find the campaign log row of a send. */
    private const CAMPAIGN_LOG_WINDOW_SECONDS = 3600;

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private UnsubscribeRuleStore $rules,
        private EmailModel $emailModel,
        private LeadModel $leadModel,
        private FieldModel $fieldModel,
        private CampaignModel $campaignModel,
        private MembershipManager $membershipManager,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Performs the configured action for a validated one-click request and writes the timeline entry.
     *
     * @return array<string, mixed> what was done (also stored as properties of the timeline entry)
     */
    public function unsubscribe(Stat $stat): array
    {
        $email = $stat->getEmail();
        $lead  = $stat->getLead();
        [$mode, $fallback, $ruleSource, $fixedSegments] = $this->resolveSettings($email);

        $result = [
            'requested_mode' => $mode,
            'rule'           => $ruleSource,
            'mode'           => null,
            'fallback_used'  => false,
            'segment_ids'    => [],
            'campaign_id'    => null,
            'stat_id'        => (int) $stat->getId(),
            'error'          => null,
        ];

        if (!$lead instanceof Lead) {
            // Same as core: without a contact there is nothing to block.
            $this->emailModel->setDoNotContact($stat, $this->dncComment(), DoNotContact::UNSUBSCRIBED);
            $result['mode'] = UnsubscribeRule::MODE_BLOCK;
            $this->logger->notice('JotaworksDoi: one-click unsubscribe without contact', $result);

            return $result;
        }

        if (UnsubscribeRule::MODE_AUTO === $mode) {
            $mode = $this->resolveAuto($stat, $email);
        }

        try {
            $done = $this->apply($mode, $stat, $lead, $email, $fixedSegments);
            if (null === $done) {
                $result['fallback_used'] = true;
                $done = $this->apply($fallback, $stat, $lead, $email, $fixedSegments)
                    ?? $this->apply(UnsubscribeRule::MODE_BLOCK, $stat, $lead, $email, $fixedSegments);
            }
        } catch (\Throwable $e) {
            $this->logger->error('JotaworksDoi: one-click action failed, falling back to block', [
                'mode'      => $mode,
                'stat_id'   => $result['stat_id'],
                'exception' => $e->getMessage(),
            ]);
            $result['error']         = substr($e->getMessage(), 0, 500);
            $result['fallback_used'] = true;
            $done = $this->apply(UnsubscribeRule::MODE_BLOCK, $stat, $lead, $email, $fixedSegments);
        }

        $result = array_merge($result, $done ?? []);
        $this->writeTimelineEntry($lead, $email, $result);
        $this->logger->notice('JotaworksDoi: one-click unsubscribe', ['lead_id' => $lead->getId(), 'email_id' => $email?->getId()] + $result);

        return $result;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int[]} mode, fallback, "email"|"global", fixed segment ids
     */
    public function resolveSettings(?Email $email): array
    {
        $global   = $this->getGlobalSettings();
        $mode     = $global['mode'];
        $fallback = $global['fallback'];
        $source   = 'global';
        $segments = [];

        $rule = $this->rules->find($email?->getId());
        if ($rule) {
            if (UnsubscribeRule::MODE_GLOBAL !== $rule->getMode()) {
                $mode   = $rule->getMode();
                $source = 'email';
            }
            if (UnsubscribeRule::MODE_GLOBAL !== $rule->getFallback()) {
                $fallback = $rule->getFallback();
            }
            $segments = $rule->getSegmentIds();
        }

        return [$mode, $fallback, $source, $segments];
    }

    /**
     * Global settings from the features area of the integration (also when unpublished).
     *
     * @return array{mode: string, fallback: string}
     */
    public function getGlobalSettings(): array
    {
        $settings    = [];
        $integration = $this->integrationHelper->getIntegrationObject(JotaworksDoiIntegration::INTEGRATION_NAME);
        if ($integration instanceof JotaworksDoiIntegration && $entity = $integration->getIntegrationSettings()) {
            $settings = $entity->getFeatureSettings();
            $settings = is_array($settings) ? $settings : [];
        }

        $mode     = (string) ($settings[JotaworksDoiIntegration::FEATURE_ONECLICK_MODE] ?? '');
        $fallback = (string) ($settings[JotaworksDoiIntegration::FEATURE_ONECLICK_FALLBACK] ?? '');

        return [
            'mode'     => in_array($mode, JotaworksDoiIntegration::ONECLICK_MODES, true) ? $mode : UnsubscribeRule::MODE_AUTO,
            'fallback' => in_array($fallback, JotaworksDoiIntegration::ONECLICK_FALLBACKS, true) ? $fallback : UnsubscribeRule::MODE_BLOCK,
        ];
    }

    private function resolveAuto(Stat $stat, ?Email $email): string
    {
        if ($this->isListEmail($email)) {
            return UnsubscribeRule::MODE_SEGMENTS;
        }

        return null !== $this->findCampaignId($stat) ? UnsubscribeRule::MODE_CAMPAIGN : UnsubscribeRule::MODE_BLOCK;
    }

    /**
     * @param int[] $fixedSegments
     *
     * @return array<string, mixed>|null null when the mode does not apply to this email
     */
    private function apply(string $mode, Stat $stat, Lead $lead, ?Email $email, array $fixedSegments): ?array
    {
        switch ($mode) {
            case UnsubscribeRule::MODE_SEGMENTS:
                if (!$this->isListEmail($email)) {
                    return null;
                }
                $ids = array_values(array_map(static fn (LeadList $list): int => (int) $list->getId(), $email->getLists()->toArray()));

                return ['mode' => $mode, 'segment_ids' => $ids, 'removed_from' => $this->removeFromSegments($lead, $ids)];

            case UnsubscribeRule::MODE_FIXED_SEGMENTS:
                if ([] === $fixedSegments) {
                    return null;
                }

                return ['mode' => $mode, 'segment_ids' => $fixedSegments, 'removed_from' => $this->removeFromSegments($lead, $fixedSegments)];

            case UnsubscribeRule::MODE_CAMPAIGN:
                $campaignId = $this->findCampaignId($stat);
                $campaign   = $campaignId ? $this->campaignModel->getEntity($campaignId) : null;
                if (!$campaign instanceof Campaign) {
                    return null;
                }
                // isExit=false: manually_removed=1 without exit date. The segment rebuild does not add the
                // contact again; a form submission or a "change campaign" action (manual adds) can.
                $this->membershipManager->removeContact($lead, $campaign, false);

                return ['mode' => $mode, 'campaign_id' => (int) $campaign->getId()];

            case UnsubscribeRule::MODE_BLOCK:
                // optin_status first: DoNotContact::addDncForContact() detaches the new DNC entity, a later
                // LeadModel::saveEntity() of the same contact would persist it a second time (duplicate row).
                $this->setOptinRevoked($lead);
                $this->emailModel->setDoNotContact($stat, $this->dncComment(), DoNotContact::UNSUBSCRIBED);

                return ['mode' => $mode];
        }

        return null;
    }

    private function isListEmail(?Email $email): bool
    {
        return $email instanceof Email && 'list' === $email->getEmailType() && $email->getLists()->count() > 0;
    }

    /**
     * Removes the contact from the segments with manually_removed=1 (segment events fire).
     *
     * Core deletes the membership row instead when the contact was added manually; for a segment
     * with filters the next rebuild would add the contact again. In that case a row with
     * manually_removed=1 is written so the unsubscribe survives the rebuild.
     *
     * @param int[] $segmentIds
     *
     * @return int[] segments the contact was a member of
     */
    private function removeFromSegments(Lead $lead, array $segmentIds): array
    {
        $conn  = $this->em->getConnection();
        $table = MAUTIC_TABLE_PREFIX.'lead_lists_leads';
        $rows  = $conn->fetchAllAssociative(
            "SELECT leadlist_id, manually_added FROM {$table}
              WHERE lead_id = :lead AND leadlist_id IN (:ids) AND manually_removed = 0",
            ['lead' => $lead->getId(), 'ids' => $segmentIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );
        if ([] === $rows) {
            return [];
        }

        $memberOf = array_map(static fn (array $row): int => (int) $row['leadlist_id'], $rows);
        $this->leadModel->removeFromLists($lead, $memberOf, true);

        foreach ($rows as $row) {
            if (empty($row['manually_added'])) {
                continue;
            }
            $list = $this->em->find(LeadList::class, (int) $row['leadlist_id']);
            if ($list instanceof LeadList && !empty($list->getFilters())) {
                $conn->executeStatement(
                    "INSERT IGNORE INTO {$table} (leadlist_id, lead_id, date_added, manually_removed, manually_added)
                     VALUES (:list, :lead, :now, 1, 0)",
                    ['list' => $list->getId(), 'lead' => $lead->getId(), 'now' => gmdate('Y-m-d H:i:s')]
                );
            }
        }

        return $memberOf;
    }

    /**
     * Campaign that sent the email of this stat.
     *
     * 1. email_stats.source = campaign.event: source_id is the campaign event (action "Send email").
     * 2. Otherwise (e.g. DOI action doi.email.send, which records source form/0): the campaign log row of
     *    this contact whose event sends this email (campaign_events.channel/channel_id), closest to date_sent.
     */
    public function findCampaignId(Stat $stat): ?int
    {
        $conn = $this->em->getConnection();
        $p    = MAUTIC_TABLE_PREFIX;

        if ('campaign.event' === $stat->getSource() && $stat->getSourceId()) {
            $id = $conn->fetchOne("SELECT campaign_id FROM {$p}campaign_events WHERE id = :id", ['id' => (int) $stat->getSourceId()]);
            if ($id) {
                return (int) $id;
            }
        }

        $email    = $stat->getEmail();
        $lead     = $stat->getLead();
        $dateSent = $stat->getDateSent();
        if (!$email || !$lead || !$dateSent) {
            return null;
        }

        $sent = \DateTimeImmutable::createFromInterface($dateSent)->setTimezone(new \DateTimeZone('UTC'));
        $id   = $conn->fetchOne(
            "SELECT l.campaign_id FROM {$p}campaign_lead_event_log l
               JOIN {$p}campaign_events e ON e.id = l.event_id
              WHERE l.lead_id = :lead AND l.is_scheduled = 0 AND e.channel = 'email' AND e.channel_id = :email
                AND l.date_triggered BETWEEN :from AND :to
              ORDER BY ABS(TIMESTAMPDIFF(SECOND, l.date_triggered, :sent)) LIMIT 1",
            [
                'lead'  => $lead->getId(),
                'email' => $email->getId(),
                'from'  => $sent->modify('-'.self::CAMPAIGN_LOG_WINDOW_SECONDS.' seconds')->format('Y-m-d H:i:s'),
                'to'    => $sent->modify('+'.self::CAMPAIGN_LOG_WINDOW_SECONDS.' seconds')->format('Y-m-d H:i:s'),
                'sent'  => $sent->format('Y-m-d H:i:s'),
            ]
        );

        return $id ? (int) $id : null;
    }

    /** Sets optin_status to "Widerrufen" (block mode only); skipped with a warning if field or option is missing. */
    private function setOptinRevoked(Lead $lead): void
    {
        $field = $this->fieldModel->getEntityByAlias(self::OPTIN_FIELD);
        if (!$field) {
            $this->logger->warning('JotaworksDoi: one-click: contact field optin_status missing, not set');

            return;
        }

        $list   = $field->getProperties()['list'] ?? [];
        $values = is_array($list)
            ? array_map(static fn ($o) => is_array($o) ? (string) ($o['value'] ?? '') : (string) $o, $list)
            : explode('|', (string) $list);
        if (!in_array(self::OPTIN_REVOKED, $values, true)) {
            $this->logger->warning('JotaworksDoi: one-click: option "Widerrufen" missing in optin_status, not set');

            return;
        }

        if (self::OPTIN_REVOKED === $lead->getFieldValue(self::OPTIN_FIELD)) {
            return;
        }
        $this->leadModel->setFieldValues($lead, [self::OPTIN_FIELD => self::OPTIN_REVOKED], false);
        $this->leadModel->saveEntity($lead);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeTimelineEntry(Lead $lead, ?Email $email, array $result): void
    {
        try {
            $log = new LeadEventLog();
            $log->setLead($lead)
                ->setBundle(self::LOG_BUNDLE)
                ->setObject(self::LOG_OBJECT)
                ->setAction(self::LOG_ACTION)
                ->setObjectId($email?->getId())
                ->setProperties($result + ['email_name' => $email?->getName()]);
            $this->em->getRepository(LeadEventLog::class)->saveEntity($log);
        } catch (\Throwable $e) {
            $this->logger->error('JotaworksDoi: one-click timeline entry failed', ['exception' => $e->getMessage()]);
        }
    }

    private function dncComment(): string
    {
        return $this->translator->trans('mautic.email.dnc.unsubscribed');
    }
}
