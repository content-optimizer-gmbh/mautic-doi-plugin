<?php

namespace MauticPlugin\JotaworksDoiBundle\Service;

use Mautic\CoreBundle\Helper\IpLookupHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\JotaworksDoiBundle\DTO\DelayedDoiConfirmationMessage;
use MauticPlugin\JotaworksDoiBundle\Integration\JotaworksDoiIntegration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Link scanner protection (opt-in, plugin setting "Schutz vor Link-Scannern").
 *
 * Instead of applying the DOI actions right away, the confirmation is dispatched as a
 * delayed messenger message. The handler checks afterwards whether the hidden bait link
 * ({doi_nothuman}) of the same DOI mail was requested and discards the confirmation if so.
 */
class DelayedConfirmationService
{
    public function __construct(
        private IntegrationHelper $integrationHelper,
        private MessageBusInterface $bus,
        private IpLookupHelper $ipLookupHelper,
        private RequestStack $requestStack,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Enabled only when the integration is published AND the option is switched on.
     * Default (no settings saved yet): off, i.e. behaviour as before.
     */
    public function isEnabled(): bool
    {
        $settings = $this->getFeatureSettings();

        return null !== $settings && !empty($settings[JotaworksDoiIntegration::FEATURE_LINKSCANNER_PROTECTION]);
    }

    public function getDelaySeconds(): int
    {
        $settings = $this->getFeatureSettings() ?? [];
        $delay    = (int) ($settings[JotaworksDoiIntegration::FEATURE_LINKSCANNER_DELAY] ?? 0);

        if ($delay < 1) {
            return JotaworksDoiIntegration::DEFAULT_DELAY_SECONDS;
        }

        return min($delay, JotaworksDoiIntegration::MAX_DELAY_SECONDS);
    }

    /**
     * Queues the confirmation with the request data of the current (human) click.
     *
     * @param array<string, mixed> $config decrypted DOI action config
     */
    public function dispatch(array $config): void
    {
        $request = $this->requestStack->getCurrentRequest();
        $delay   = $this->getDelaySeconds();

        $message = new DelayedDoiConfirmationMessage(
            $config,
            time(),
            $this->ipLookupHelper->getIpAddressFromRequest(),
            $request?->headers->get('User-Agent'),
            $request?->headers->get('Referer'),
            $request?->headers->get('Accept-Language'),
            $delay
        );

        $this->logger->info('JotaworksDoi: DOI confirmation queued for link scanner check', [
            'lead_id' => $config['lead_id'] ?? null,
            'hash'    => $config['hash'] ?? null,
            'delay'   => $delay,
        ]);

        // DelayStamp is only honoured by async transports (doctrine, redis ...); sync:// handles it at once.
        $this->bus->dispatch($message, [new DelayStamp($delay * 1000)]);
    }

    /**
     * @return array<string, mixed>|null null when the integration is missing or unpublished
     */
    private function getFeatureSettings(): ?array
    {
        $integration = $this->integrationHelper->getIntegrationObject(JotaworksDoiIntegration::INTEGRATION_NAME);
        if (!$integration instanceof JotaworksDoiIntegration) {
            return null;
        }

        $entity = $integration->getIntegrationSettings();
        if (!$entity || !$entity->getIsPublished()) {
            return null;
        }

        $settings = $entity->getFeatureSettings();

        return is_array($settings) ? $settings : [];
    }
}
