<?php

namespace MauticPlugin\JotaworksDoiBundle\Service;

use Mautic\EmailBundle\Model\EmailModel;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\JotaworksDoiBundle\DoiEvents;
use MauticPlugin\JotaworksDoiBundle\Entity\DoNotContact as DNC;
use MauticPlugin\JotaworksDoiBundle\Event\DoiStarted;
use MauticPlugin\JotaworksDoiBundle\Helper\Base64Helper;
use MauticPlugin\JotaworksDoiBundle\Helper\LeadHelper;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class DoiEmailService
{
    private RouterInterface $router;
    private $encryptionHelper;
    private EmailModel $emailModel;
    private LeadModel $leadModel;
    private ContactTracker $contactTracker;
    private EventDispatcherInterface $eventDispatcher;

    public function __construct(
        RouterInterface $router,
        $encryptionHelper,
        EmailModel $emailModel,
        LeadModel $leadModel,
        ContactTracker $contactTracker,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->router = $router;
        $this->encryptionHelper = $encryptionHelper;
        $this->emailModel = $emailModel;
        $this->leadModel = $leadModel;
        $this->contactTracker = $contactTracker;
        $this->eventDispatcher = $eventDispatcher;
    }

    public function sendDoiEmail(Lead $lead, array $config, array $data, array $tokens, int $submissionId = 0): bool
    {
        if (!$this->shouldEmailBeSent($lead)) {
            return false;
        }

        $emailId = (int) $config['email'];
        $email = $this->emailModel->getEntity($emailId);

        if ($email === null || !$email->isPublished()) {
            return false;
        }

        $currentLead = $this->contactTracker->getContact();
        if ($currentLead instanceof Lead) {
            $leadFields = $currentLead->getProfileFields();
            $currentLead = array_merge(
                ['id' => $currentLead->getId()],
                $leadFields
            );
        }

        $tokens['{doi_url}'] = $this->buildDoiConfirmUrl($data);
        $tokens['{doi_nothuman}'] = $this->buildClickBaitUrl($data['hash']);

        $options = [
            'source' => ['form', $submissionId],
            'tokens' => $tokens,
            'ignoreDNC' => true,
        ];

        if (empty($currentLead['email']) && !empty($currentLead['email_validate'])) {
            $currentLead['email'] = $currentLead['email_validate'];
        }

        $this->emailModel->sendEmail($email, $currentLead, $options);

        return true;
    }

    public function shouldEmailBeSent(Lead $lead): bool
    {
        foreach ($lead->getDoNotContact() as $dnc) {
            $reason = $dnc->getReason();
            $channel = $dnc->getChannel();

            if ($channel !== 'email') {
                continue;
            }

            if (DNC::UNSUBSCRIBE === $reason) {
                return true;
            }

            if (DNC::BOUNCED === $reason) {
                return false;
            }

            if (DNC::MANUAL === $reason) {
                return false;
            }
        }

        return true;
    }

    public function preparePostUrl(string $url, array $tokens): string
    {
        if (empty($tokens)) {
            return urldecode($url);
        }

        return str_replace(array_keys($tokens), array_values($tokens), urldecode($url));
    }

    public function buildDoiConfirmUrl(array $data): string
    {
        $encData = $this->encryptConfig($data);

        $doiUrl = $this->router->generate(
            'jotaworks_doiauth_index',
            ['enc' => $encData],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        return str_replace('|', '%7C', $doiUrl);
    }

    public function buildClickBaitUrl(string $hash): string
    {
        return $this->router->generate(
            'jotaworks_doiauth_nothuman',
            ['hash' => $hash],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
    }

    public function encryptConfig(array $data): string
    {
        $encData = $this->encryptionHelper->encrypt($data);

        return Base64Helper::prepare_base64_url_encode($encData);
    }

    public function fireWebhookEvent(Lead $lead, array $data): void
    {
        $doiEvent = new DoiStarted($lead, $data);
        $this->eventDispatcher->dispatch($doiEvent, DoiEvents::DOI_STARTED);
    }

    public function leadFieldUpdate(array $config, Lead $lead): void
    {
        if (empty($config['lead_field_update_before'])) {
            return;
        }

        LeadHelper::leadFieldUpdate($config['lead_field_update_before'], $this->leadModel, $lead);
    }

    public function shouldDoiProcessStart(Lead $lead, array $data, int $submissionId): bool
    {
        return true;
    }
}
