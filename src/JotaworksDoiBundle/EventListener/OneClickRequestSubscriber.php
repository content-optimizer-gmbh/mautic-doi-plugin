<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\EmailBundle\Helper\MailHashHelper;
use Mautic\EmailBundle\Model\EmailModel;
use MauticPlugin\JotaworksDoiBundle\Service\OneClickUnsubscribeService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Intercepts only the RFC 8058 one-click POST (body List-Unsubscribe=One-Click) to the route
 * mautic_email_unsubscribe, before PublicController::unsubscribeAction() runs. Every other request
 * to that route (GET preference page, form posts) is left to core.
 *
 * Validation: unknown idHash -> 404, missing address or hash in the URL -> 400, address not the one
 * of the stat or wrong hash -> 403. If the plugin fails unexpectedly, the request falls through to
 * core, which blocks the contact (no unsubscribe gets lost).
 */
class OneClickRequestSubscriber implements EventSubscriberInterface
{
    public const ROUTE = 'mautic_email_unsubscribe';

    public function __construct(
        private OneClickUnsubscribeService $service,
        private EmailModel $emailModel,
        private CoreParametersHelper $coreParametersHelper,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // after the router (32) and the firewall (8), before the controller
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->isOneClick($request)) {
            return;
        }

        $idHash = (string) $request->attributes->get('idHash', '');
        $stat   = '' !== $idHash ? $this->emailModel->getEmailStatus($idHash) : null;
        if (!$stat) {
            $event->setResponse(new Response($this->translator->trans('mautic.email.stat_record.not_found'), Response::HTTP_NOT_FOUND));

            return;
        }

        $urlEmail   = (string) $request->attributes->get('urlEmail', '');
        $secretHash = (string) $request->attributes->get('secretHash', '');
        if ('' === $urlEmail || '' === $secretHash) {
            $event->setResponse(new Response('Bad Request', Response::HTTP_BAD_REQUEST));

            return;
        }

        if (!$this->addressMatches($urlEmail, $secretHash, (string) $stat->getEmailAddress())) {
            $this->logger->warning('JotaworksDoi: one-click unsubscribe with wrong address or hash', ['stat_id' => $stat->getId()]);
            $event->setResponse(new Response('Forbidden', Response::HTTP_FORBIDDEN));

            return;
        }

        try {
            $this->service->unsubscribe($stat);
        } catch (\Throwable $e) {
            // leave it to core (PublicController::oneClickUnsubscribe sets DNC reason 1)
            $this->logger->error('JotaworksDoi: one-click unsubscribe failed, handing over to core', ['exception' => $e->getMessage()]);

            return;
        }

        $event->setResponse(new Response($this->translator->trans('mautic.lead.do.not.contact_unsubscribed')));
    }

    /** Same condition as PublicController::unsubscribeAction(). */
    private function isOneClick(Request $request): bool
    {
        return self::ROUTE === $request->attributes->get('_route')
            && $request->isMethod(Request::METHOD_POST)
            && 'One-Click' === $request->get('List-Unsubscribe');
    }

    /** The URL address must be the address of the stat and its HMAC must match (MailHashHelper, secret_key). */
    private function addressMatches(string $urlEmail, string $secretHash, string $statAddress): bool
    {
        if ('' !== $statAddress && 0 !== strcasecmp($urlEmail, $statAddress)) {
            return false;
        }

        return hash_equals(
            MailHashHelper::getEmailHashForSecret($urlEmail, (string) $this->coreParametersHelper->get('secret_key')),
            $secretHash
        );
    }
}
