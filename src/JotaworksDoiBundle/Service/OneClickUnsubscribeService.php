<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
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
 * Configurable RFC 8058 one-click unsubscribe (2.4.0, idempotent since 2.4.1).
 *
 * Decision per click: rule of the email, else the global setting of the integration (default "block").
 * "auto" picks "segments" for segment (list) emails, "campaign" for emails sent by a campaign, else "block".
 *
 * Results per click:
 * - applied: the effect was performed (optionally a contact field is set first).
 * - already_applied: the effect is already in place (double click, contact not in the segments any more,
 *   no active campaign member, DNC reason 1 exists) - nothing is done, the fallback is NOT used.
 * - The fallback (then block) is used only when the mode does not apply to the email at all (no segment email,
 *   no campaign found, no fixed segments). If an action throws, the contact is blocked (EntityManager reset if
 *   Doctrine closed it), so no unsubscribe gets lost.
 */
class OneClickUnsubscribeService
{
    public const LOG_BUNDLE = 'jwdoi';
    public const LOG_OBJECT = 'email';
    public const LOG_ACTION = 'oneclick_unsubscribe';

    public const RESULT_APPLIED         = 'applied';
    public const RESULT_ALREADY_APPLIED = 'already_applied';

    /** Window around email_stats.date_sent to find the campaign log row of a send. */
    private const CAMPAIGN_LOG_WINDOW_SECONDS = 3600;

    /** An identical already_applied entry for the same stat within this time is not written again. */
    private const ALREADY_APPLIED_LOG_SECONDS = 86400;

    public function __construct(
        private IntegrationHelper $integrationHelper,
        private UnsubscribeRuleStore $rules,
        private EmailModel $emailModel,
        private LeadModel $leadModel,
        private FieldModel $fieldModel,
        private CampaignModel $campaignModel,
        private MembershipManager $membershipManager,
        private EntityManagerInterface $em,
        private ManagerRegistry $registry,
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
        $statId   = (int) $stat->getId();
        $email    = $stat->getEmail();
        $lead     = $stat->getLead();
        $settings = $this->resolveSettings($email);

        $result = [
            'requested_mode' => $settings['mode'],
            'rule'           => $settings['source'],
            'mode'           => null,
            'result'         => null,
            'fallback_used'  => false,
            'segment_ids'    => [],
            'campaign_id'    => null,
            'field'          => null,
            'field_value'    => null,
            'stat_id'        => $statId,
            'error'          => null,
        ];

        if (!$lead instanceof Lead) {
            // Same as core: without a contact there is nothing to block.
            $this->emailModel->setDoNotContact($stat, $this->dncComment(), DoNotContact::UNSUBSCRIBED);
            $result['mode']   = UnsubscribeRule::MODE_BLOCK;
            $result['result'] = self::RESULT_APPLIED;
            $this->logger->notice('JotaworksDoi: one-click unsubscribe without contact', $result);

            return $result;
        }

        $mode = $settings['mode'];
        if (UnsubscribeRule::MODE_AUTO === $mode) {
            $mode = $this->resolveAuto($stat, $email);
        }

        try {
            $done = $this->apply($mode, $stat, $lead, $email, $settings['segments'], $settings['field']);
            if (null === $done) {
                $result['fallback_used'] = true;
                $done = $this->apply($settings['fallback'], $stat, $lead, $email, $settings['segments'], $settings['field'])
                    ?? $this->apply(UnsubscribeRule::MODE_BLOCK, $stat, $lead, $email, $settings['segments'], $settings['field']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('JotaworksDoi: one-click action failed, falling back to block', [
                'mode'      => $mode,
                'stat_id'   => $statId,
                'exception' => $e->getMessage(),
            ]);
            $result['error']         = substr($e->getMessage(), 0, 500);
            $result['fallback_used'] = true;
            [$stat, $result['em_reset']] = $this->recover($statId);
            $lead  = $stat->getLead();
            $email = $stat->getEmail();
            // minimal safe path: block only, no contact field
            $done = $this->apply(UnsubscribeRule::MODE_BLOCK, $stat, $lead, $email, [], null);
        }

        $result = array_merge($result, $done ?? []);
        $this->writeTimelineEntry($lead, $email, $result);
        $this->logger->notice('JotaworksDoi: one-click unsubscribe', ['lead_id' => $lead->getId(), 'email_id' => $email?->getId()] + $result);

        return $result;
    }

    /**
     * Effective settings: rule of the email over the global settings.
     *
     * @return array{mode: string, fallback: string, source: string, segments: int[], field: array{alias: string, value: string}|null}
     */
    public function resolveSettings(?Email $email): array
    {
        $global   = $this->getGlobalSettings();
        $settings = [
            'mode'     => $global['mode'],
            'fallback' => $global['fallback'],
            'source'   => 'global',
            'segments' => [],
            'field'    => $global['field'],
        ];

        $rule = $this->rules->find($email?->getId());
        if (!$rule) {
            return $settings;
        }
        if (UnsubscribeRule::MODE_GLOBAL !== $rule->getMode()) {
            $settings['mode']   = $rule->getMode();
            $settings['source'] = 'email';
        }
        if (UnsubscribeRule::MODE_GLOBAL !== $rule->getFallback()) {
            $settings['fallback'] = $rule->getFallback();
        }
        $settings['segments'] = $rule->getSegmentIds();

        if (UnsubscribeRule::FIELD_NONE === $rule->getFieldAlias()) {
            $settings['field'] = null;
        } elseif ('' !== $rule->getFieldAlias()) {
            $settings['field'] = $this->fieldSetting($rule->getFieldAlias(), $rule->getFieldValue());
        }

        return $settings;
    }

    /**
     * Global settings from the features area of the integration (also when unpublished).
     *
     * @return array{mode: string, fallback: string, field: array{alias: string, value: string}|null}
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
            'mode'     => in_array($mode, JotaworksDoiIntegration::ONECLICK_MODES, true) ? $mode : UnsubscribeRule::MODE_BLOCK,
            'fallback' => in_array($fallback, JotaworksDoiIntegration::ONECLICK_FALLBACKS, true) ? $fallback : UnsubscribeRule::MODE_BLOCK,
            'field'    => $this->fieldSetting(
                (string) ($settings[JotaworksDoiIntegration::FEATURE_ONECLICK_FIELD] ?? ''),
                (string) ($settings[JotaworksDoiIntegration::FEATURE_ONECLICK_FIELD_VALUE] ?? '')
            ),
        ];
    }

    /**
     * @return array{alias: string, value: string}|null null = no field is set (alias or value empty)
     */
    private function fieldSetting(string $alias, string $value): ?array
    {
        if ('' === $alias || UnsubscribeRule::FIELD_NONE === $alias || '' === $value) {
            return null;
        }

        return ['alias' => $alias, 'value' => $value];
    }

    private function resolveAuto(Stat $stat, ?Email $email): string
    {
        if ($this->isListEmail($email)) {
            return UnsubscribeRule::MODE_SEGMENTS;
        }

        return null !== $this->findCampaignId($stat) ? UnsubscribeRule::MODE_CAMPAIGN : UnsubscribeRule::MODE_BLOCK;
    }

    /**
     * @param int[]                                     $fixedSegments
     * @param array{alias: string, value: string}|null $field
     *
     * @return array<string, mixed>|null null when the mode does not apply to this email
     */
    private function apply(string $mode, Stat $stat, Lead $lead, ?Email $email, array $fixedSegments, ?array $field): ?array
    {
        switch ($mode) {
            case UnsubscribeRule::MODE_SEGMENTS:
                if (!$this->isListEmail($email)) {
                    return null;
                }
                $ids = array_values(array_map(static fn (LeadList $list): int => (int) $list->getId(), $email->getLists()->toArray()));

                return $this->applySegments($mode, $lead, $ids, $field);

            case UnsubscribeRule::MODE_FIXED_SEGMENTS:
                if ([] === $fixedSegments) {
                    return null;
                }

                return $this->applySegments($mode, $lead, $fixedSegments, $field);

            case UnsubscribeRule::MODE_CAMPAIGN:
                $campaignId = $this->findCampaignId($stat);
                $campaign   = $campaignId ? $this->campaignModel->getEntity($campaignId) : null;
                if (!$campaign instanceof Campaign) {
                    return null;
                }
                $done = ['mode' => $mode, 'campaign_id' => (int) $campaign->getId()];
                if (!$this->isActiveCampaignMember($lead, (int) $campaign->getId())) {
                    return $done + ['result' => self::RESULT_ALREADY_APPLIED];
                }
                $done += $this->setField($lead, $field);
                // isExit=false: manually_removed=1 without exit date. The segment rebuild does not add the
                // contact again; a form submission or a "change campaign" action (manual adds) can.
                $this->membershipManager->removeContact($lead, $campaign, false);

                return $done + ['result' => self::RESULT_APPLIED];

            case UnsubscribeRule::MODE_BLOCK:
                if ($this->hasUnsubscribeDnc($lead)) {
                    return ['mode' => $mode, 'result' => self::RESULT_ALREADY_APPLIED];
                }
                // Field first: DoNotContact::addDncForContact() detaches the new DNC entity, a later
                // LeadModel::saveEntity() of the same contact would persist it a second time (duplicate row).
                $done = ['mode' => $mode] + $this->setField($lead, $field);
                $this->emailModel->setDoNotContact($stat, $this->dncComment(), DoNotContact::UNSUBSCRIBED);

                return $done + ['result' => self::RESULT_APPLIED];
        }

        return null;
    }

    /**
     * @param int[]                                     $segmentIds
     * @param array{alias: string, value: string}|null $field
     *
     * @return array<string, mixed>
     */
    private function applySegments(string $mode, Lead $lead, array $segmentIds, ?array $field): array
    {
        $done = ['mode' => $mode, 'segment_ids' => $segmentIds];
        $rows = $this->activeMemberships($lead, $segmentIds);
        if ([] === $rows) {
            return $done + ['result' => self::RESULT_ALREADY_APPLIED];
        }
        $done += $this->setField($lead, $field);

        return $done + ['removed_from' => $this->removeFromSegments($lead, $rows), 'result' => self::RESULT_APPLIED];
    }

    private function isListEmail(?Email $email): bool
    {
        return $email instanceof Email && 'list' === $email->getEmailType() && $email->getLists()->count() > 0;
    }

    /**
     * @param int[] $segmentIds
     *
     * @return list<array<string, mixed>> membership rows (leadlist_id, manually_added) without manually_removed
     */
    private function activeMemberships(Lead $lead, array $segmentIds): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT leadlist_id, manually_added FROM '.MAUTIC_TABLE_PREFIX.'lead_lists_leads
              WHERE lead_id = :lead AND leadlist_id IN (:ids) AND manually_removed = 0',
            ['lead' => $lead->getId(), 'ids' => $segmentIds],
            ['ids' => ArrayParameterType::INTEGER]
        );
    }

    private function isActiveCampaignMember(Lead $lead, int $campaignId): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX.'campaign_leads WHERE lead_id = :lead AND campaign_id = :campaign AND manually_removed = 0',
            ['lead' => $lead->getId(), 'campaign' => $campaignId]
        );
    }

    private function hasUnsubscribeDnc(Lead $lead): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX."lead_donotcontact WHERE lead_id = :lead AND channel = 'email' AND reason = :reason",
            ['lead' => $lead->getId(), 'reason' => DoNotContact::UNSUBSCRIBED]
        );
    }

    /**
     * Removes the contact from the segments with manually_removed=1 (segment events fire).
     *
     * Core deletes the membership row instead when the contact was added manually; for a segment
     * with filters the next rebuild would add the contact again. In that case a row with
     * manually_removed=1 is written so the unsubscribe survives the rebuild.
     *
     * @param list<array<string, mixed>> $rows active memberships (activeMemberships())
     *
     * @return int[] segments the contact was removed from
     */
    private function removeFromSegments(Lead $lead, array $rows): array
    {
        $conn     = $this->em->getConnection();
        $table    = MAUTIC_TABLE_PREFIX.'lead_lists_leads';
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

    /**
     * Optional contact field (setting "Leadfeld setzen"), set before the action itself.
     *
     * @param array{alias: string, value: string}|null $field
     *
     * @return array<string, mixed> field and value that were set, or a warning when the field is unknown
     */
    private function setField(Lead $lead, ?array $field): array
    {
        if (null === $field) {
            return [];
        }
        if (!$this->fieldModel->getEntityByAlias($field['alias'])) {
            $this->logger->warning('JotaworksDoi: one-click: contact field missing, not set', ['field' => $field['alias']]);

            return ['field' => $field['alias'], 'field_value' => null, 'field_error' => 'field not found'];
        }

        $this->leadModel->setFieldValues($lead, [$field['alias'] => $field['value']], false);
        $this->leadModel->saveEntity($lead);

        // Mautic drops values it does not accept (e.g. a value that is no option of a select field)
        if ((string) $lead->getFieldValue($field['alias']) !== $field['value']) {
            $this->logger->warning('JotaworksDoi: one-click: contact field value not accepted', $field);

            return ['field' => $field['alias'], 'field_value' => null, 'field_error' => 'value not accepted'];
        }

        return ['field' => $field['alias'], 'field_value' => $field['value']];
    }

    /**
     * After a failed action: reset the EntityManager if Doctrine closed it, otherwise drop pending changes,
     * and reload the stat so the block fallback works on managed entities.
     *
     * @return array{0: Stat, 1: string} reloaded stat, "reset"|"cleared"
     */
    private function recover(int $statId): array
    {
        $how = 'cleared';
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
            $how = 'reset';
        } else {
            $this->em->clear();
        }
        if (!$this->em->isOpen()) {
            throw new \RuntimeException('JotaworksDoi: EntityManager still closed after reset');
        }
        $this->logger->warning('JotaworksDoi: one-click recovered EntityManager', ['how' => $how, 'stat_id' => $statId]);

        $stat = $this->em->find(Stat::class, $statId);
        if (!$stat instanceof Stat) {
            throw new \RuntimeException('JotaworksDoi: stat not found after EntityManager recovery');
        }

        return [$stat, $how];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function writeTimelineEntry(Lead $lead, ?Email $email, array $result): void
    {
        try {
            if (self::RESULT_ALREADY_APPLIED === $result['result'] && $this->hasRecentAlreadyApplied($lead, (int) $result['stat_id'])) {
                return;
            }
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

    private function hasRecentAlreadyApplied(Lead $lead, int $statId): bool
    {
        return (bool) $this->em->getConnection()->fetchOne(
            'SELECT 1 FROM '.MAUTIC_TABLE_PREFIX."lead_event_log
              WHERE lead_id = :lead AND bundle = :bundle AND action = :action AND date_added >= :since
                AND JSON_EXTRACT(properties, '$.stat_id') = :stat
                AND JSON_UNQUOTE(JSON_EXTRACT(properties, '$.result')) = :result LIMIT 1",
            [
                'lead'   => $lead->getId(),
                'bundle' => self::LOG_BUNDLE,
                'action' => self::LOG_ACTION,
                'since'  => gmdate('Y-m-d H:i:s', time() - self::ALREADY_APPLIED_LOG_SECONDS),
                'stat'   => $statId,
                'result' => self::RESULT_ALREADY_APPLIED,
            ]
        );
    }

    private function dncComment(): string
    {
        return $this->translator->trans('mautic.email.dnc.unsubscribed');
    }
}
