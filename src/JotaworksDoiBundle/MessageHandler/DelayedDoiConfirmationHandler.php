<?php

namespace MauticPlugin\JotaworksDoiBundle\MessageHandler;

use MauticPlugin\JotaworksDoiBundle\DTO\DelayedDoiConfirmationMessage;
use MauticPlugin\JotaworksDoiBundle\Helper\DoiActionHelper;
use MauticPlugin\JotaworksDoiBundle\Helper\NotHumanClickHelper;
use MauticPlugin\JotaworksDoiBundle\Integration\JotaworksDoiIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles a delayed DOI confirmation (replacement for the 1.4.x QueueSubscriber).
 *
 * If the bait link of the same DOI mail was requested shortly before or after the click
 * (window: clickedAt - BAIT_LOOKBACK_SECONDS .. clickedAt + delay), a link scanner clicked
 * through the mail: the confirmation is discarded and the hits of that window are consumed,
 * so a later real click confirms. Bait hits older than the window are stale (a scanner only
 * requested the bait link) and are removed without effect. Otherwise the DOI actions are
 * applied with the request data of the original click.
 *
 * Registered in Config/config.php with the tag messenger.message_handler.
 */
class DelayedDoiConfirmationHandler
{
    public function __construct(
        private DoiActionHelper $doiActionHelper,
        private NotHumanClickHelper $notHumanClickHelper,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DelayedDoiConfirmationMessage $message): void
    {
        $config  = $message->getConfig();
        $hash    = $config['hash'] ?? null;
        $from    = $message->getClickedAt() - JotaworksDoiIntegration::BAIT_LOOKBACK_SECONDS;
        $to      = $message->getClickedAt() + max(0, $message->getDelaySeconds());
        $context = [
            'lead_id'    => $config['lead_id'] ?? null,
            'hash'       => $hash,
            'clicked_at' => $message->getClickedAt(),
            'waited'     => time() - $message->getClickedAt(),
            'window'     => [$from, $to],
        ];

        if (!empty($hash)) {
            $stale = $this->notHumanClickHelper->removeHitsUntil($hash, $from - 1);
            if ($stale > 0) {
                $this->logger->info('JotaworksDoi: stale bait link hits ignored and removed', $context + ['removed' => $stale]);
            }

            if ($this->notHumanClickHelper->hasHitBetween($hash, $from, $to)) {
                // Consume the hits of this window only; later hits may belong to a later click.
                $this->notHumanClickHelper->removeHitsUntil($hash, $to);
                $this->logger->warning('JotaworksDoi: DOI confirmation discarded, bait link was requested (link scanner)', $context);

                return;
            }
        }

        $this->applyWithOriginalRequest($message);
        $this->logger->info('JotaworksDoi: delayed DOI confirmation applied', $context);
    }

    private function applyWithOriginalRequest(DelayedDoiConfirmationMessage $message): void
    {
        // {doi_timestamp} records the human click, not the time the worker applies the actions.
        $clickedAt = new \DateTimeImmutable('@'.$message->getClickedAt());

        // sync:// transport: still inside the confirm request, use it as it is.
        $currentRequest = $this->requestStack->getCurrentRequest();
        if (null !== $currentRequest) {
            $this->doiActionHelper->setRequest($currentRequest);
            $this->doiActionHelper->applyDoiActions($message->getConfig(), $clickedAt);

            return;
        }

        // Worker: rebuild the human click so IpLookupHelper (reads the request stack),
        // the audit log, {doi_ip} and the page hit get the visitor's data, not the worker's.
        $request = $this->buildRequest($message);
        $this->requestStack->push($request);
        $this->doiActionHelper->setRequest($request);

        try {
            $this->doiActionHelper->applyDoiActions($message->getConfig(), $clickedAt);
        } finally {
            $this->doiActionHelper->setRequest(null);
            $this->requestStack->pop();
        }
    }

    private function buildRequest(DelayedDoiConfirmationMessage $message): Request
    {
        $config = $message->getConfig();
        $server = array_filter(
            [
                'REMOTE_ADDR'          => $message->getIpAddress(),
                'HTTP_USER_AGENT'      => $message->getUserAgent(),
                'HTTP_REFERER'         => $message->getReferer(),
                'HTTP_ACCEPT_LANGUAGE' => $message->getAcceptLanguage(),
                'REQUEST_TIME'         => $message->getClickedAt(),
            ],
            static fn ($value): bool => null !== $value && '' !== $value
        );

        $url = !empty($config['url']) && is_string($config['url']) ? $config['url'] : '/';

        return Request::create($url, 'GET', [], [], [], $server);
    }
}
