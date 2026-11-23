<?php

namespace MauticPlugin\JotaworksDoiBundle\DTO;

/**
 * Messenger message for a delayed DOI confirmation (link scanner protection).
 *
 * Lives in DTO/ on purpose: Mautic autowires every plugin class outside its
 * DEFAULT_EXCLUDES as a service, and a message with array arguments must not
 * become one.
 *
 * Carries the request data of the HUMAN click, because the worker that handles
 * the message has no request of its own (IP, user agent etc. would otherwise be
 * the worker's).
 */
final class DelayedDoiConfirmationMessage
{
    /**
     * @param array<string, mixed> $config decrypted DOI action config (lead_id, hash, url, tags ...)
     */
    public function __construct(
        private array $config,
        private int $clickedAt,
        private ?string $ipAddress = null,
        private ?string $userAgent = null,
        private ?string $referer = null,
        private ?string $acceptLanguage = null,
        private int $delaySeconds = 0
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getClickedAt(): int
    {
        return $this->clickedAt;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function getReferer(): ?string
    {
        return $this->referer;
    }

    public function getAcceptLanguage(): ?string
    {
        return $this->acceptLanguage;
    }

    /**
     * Delay configured when the click was queued; end of the bait hit window = clickedAt + delay.
     */
    public function getDelaySeconds(): int
    {
        return $this->delaySeconds;
    }
}
