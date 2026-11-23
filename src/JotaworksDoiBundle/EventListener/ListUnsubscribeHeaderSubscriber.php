<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\LeadBundle\Entity\Lead;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * RFC 8058 header (2.4.0): every real send of an email entity gets the https one-click URI in
 * List-Unsubscribe and "List-Unsubscribe-Post: List-Unsubscribe=One-Click". Core adds the https URI
 * only when the body contains {unsubscribe_url}, but always adds the mailto and the Post header.
 *
 * Runs before ProcessUnsubscribeSubscriber (priority 0), which appends the mailto. The URI is built
 * exactly like MailHelper::getUnsubscribeHeader(), so MailHelper::getCustomHeaders() finds it via
 * str_contains() and adds no duplicate, with or without the core patch JOTAWORKS-LISTUNSUB-PATCH.
 */
class ListUnsubscribeHeaderSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private CoreParametersHelper $coreParametersHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EmailEvents::EMAIL_ON_SEND => ['onEmailSend', 10]];
    }

    public function onEmailSend(EmailSendEvent $event): void
    {
        $helper = $event->getHelper();
        $email  = $event->getEmail();
        $idHash = $event->getIdHash();
        if (!$helper || !$email || !$idHash || $event->isInternalSend() || $email->getSendToDnc()
            || $this->coreParametersHelper->get('disable_unsubscribe_link_header')) {
            return;
        }

        $address = $this->recipientAddress($helper->getLead());
        if (!$address) {
            return;
        }

        $uri = '<'.$this->router->generate(
            'mautic_email_unsubscribe',
            ['idHash' => $idHash, 'urlEmail' => $address, 'secretHash' => MailHashHelper::getEmailHashForSecret($address, (string) $this->coreParametersHelper->get('secret_key'))],
            UrlGeneratorInterface::ABSOLUTE_URL
        ).'>';

        $existing = $event->getTextHeaders()['List-Unsubscribe'] ?? '';
        if (!str_contains($existing, $uri)) {
            $event->addTextHeader('List-Unsubscribe', '' !== $existing ? $uri.','.$existing : $uri);
        }
        $event->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    /** Same address source as MailHelper::getUnsubscribeHeader(). */
    private function recipientAddress(mixed $lead): ?string
    {
        if (is_array($lead) && isset($lead['email']) && is_string($lead['email']) && '' !== $lead['email']) {
            return $lead['email'];
        }
        if ($lead instanceof Lead && is_string($lead->getEmail()) && '' !== $lead->getEmail()) {
            return $lead->getEmail();
        }

        return null;
    }
}
