<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\LeadEventLog;
use Mautic\LeadBundle\Entity\LeadEventLogRepository;
use Mautic\LeadBundle\Event\LeadTimelineEvent;
use Mautic\LeadBundle\LeadEvents;
use MauticPlugin\JotaworksDoiBundle\Service\OneClickUnsubscribeService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Contact timeline: one entry per one-click unsubscribe (lead_event_log, bundle jwdoi).
 */
class OneClickTimelineSubscriber implements EventSubscriberInterface
{
    public const EVENT_TYPE = 'jwdoi.oneclick_unsubscribe';

    public function __construct(
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private RouterInterface $router,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LeadEvents::TIMELINE_ON_GENERATE => ['onTimelineGenerate', 0]];
    }

    public function onTimelineGenerate(LeadTimelineEvent $event): void
    {
        $typeName = $this->translator->trans('jw.doi.unsubscribe.timeline.type');
        $event->addEventType(self::EVENT_TYPE, $typeName);
        if (!$event->isApplicable(self::EVENT_TYPE)) {
            return;
        }

        /** @var LeadEventLogRepository $repo */
        $repo   = $this->em->getRepository(LeadEventLog::class);
        $result = $repo->getEvents(
            $event->getLead(),
            OneClickUnsubscribeService::LOG_BUNDLE,
            OneClickUnsubscribeService::LOG_OBJECT,
            OneClickUnsubscribeService::LOG_ACTION,
            $event->getQueryOptions()
        );
        $event->addToCounter(self::EVENT_TYPE, $result);
        if ($event->isEngagementCount()) {
            return;
        }

        foreach ($result['results'] ?? [] as $row) {
            $props = is_string($row['properties']) ? (json_decode($row['properties'], true) ?: []) : (array) $row['properties'];
            $label = $this->translator->trans('jw.doi.unsubscribe.timeline.label', [
                '%email%' => $props['email_name'] ?? ('#'.$row['object_id']),
                '%mode%'  => $this->translator->trans('jw.doi.unsubscribe.mode.'.($props['mode'] ?? 'block')),
            ]);
            if (!empty($props['fallback_used'])) {
                $label .= ' '.$this->translator->trans('jw.doi.unsubscribe.timeline.fallback');
            }
            if ('already_applied' === ($props['result'] ?? null)) {
                $label .= ' '.$this->translator->trans('jw.doi.unsubscribe.timeline.already');
            }
            if (!empty($props['field']) && null !== ($props['field_value'] ?? null)) {
                $label .= ' '.$this->translator->trans('jw.doi.unsubscribe.timeline.field', [
                    '%field%' => $props['field'],
                    '%value%' => $props['field_value'],
                ]);
            }
            $event->addEvent([
                'event'      => self::EVENT_TYPE,
                'eventId'    => self::EVENT_TYPE.$row['id'],
                'eventLabel' => $row['object_id'] ? [
                    'label' => $label,
                    'href'  => $this->router->generate('mautic_email_action', ['objectAction' => 'view', 'objectId' => $row['object_id']]),
                ] : $label,
                'eventType'  => $typeName,
                'timestamp'  => $row['date_added'],
                'extra'      => $props,
                'icon'       => 'ri-mail-close-line',
                'contactId'  => $row['lead_id'],
            ]);
        }
    }
}
