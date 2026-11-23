<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\JotaworksDoiBundle\Service\DelayedConfirmationService;
use MauticPlugin\JotaworksDoiBundle\Service\DoiEmailService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Bait link in every sent mail (2.3.0): with the link scanner protection on, each real send gets an invisible link
 * /nothuman/<stat hash> and the token {jw_nothuman}. A link scanner requests it, a human does not; the hit consumer
 * (mautic_docker patch scanner-guard) then marks clicks of that recipient as scanner clicks and triggers no decision.
 * The marker is the same as for the DOI bait (doi_<hash>.log), only the hash is the mail's stat hash.
 */
class LinkScannerBaitSubscriber implements EventSubscriberInterface
{
    public const TOKEN = '{jw_nothuman}';

    public function __construct(
        private DelayedConfirmationService $delayedConfirmation,
        private DoiEmailService $doiEmailService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // before Mautic converts links to trackables (priority 0), so the bait stays untracked
        return [EmailEvents::EMAIL_ON_SEND => ['onEmailSend', 300]];
    }

    public function onEmailSend(EmailSendEvent $event): void
    {
        $idHash = $event->getIdHash();
        if ($event->isInternalSend() || !$idHash || !$this->delayedConfirmation->isEnabled()) {
            return;
        }
        $url = $this->doiEmailService->buildClickBaitUrl($idHash);
        $event->addToken(self::TOKEN, $url);
        $content = $event->getContent();
        if ('' === $content || str_contains($content, self::TOKEN) || str_contains($content, '/nothuman/')) {
            return;
        }
        $event->setContent($this->inject($content, $url));
    }

    /** Hidden, untracked link right after <body> (or at the start): first in the mail, where scanners begin. */
    private function inject(string $content, string $url): string
    {
        $bait = '<a href="'.htmlspecialchars($url, ENT_QUOTES).'" data-mautic-disable-tracking="true" aria-hidden="true"'
            .' tabindex="-1" style="display:none;font-size:0;line-height:0;max-height:0;overflow:hidden">&#8203;</a>';
        $count = 0;
        $content = preg_replace('/(<body[^>]*>)/i', '$1'.$bait, $content, 1, $count) ?? $content;

        return $count ? $content : $bait.$content;
    }
}
