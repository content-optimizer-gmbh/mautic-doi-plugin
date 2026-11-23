<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\EventListener;

use Mautic\CoreBundle\CoreEvents;
use Mautic\CoreBundle\Event\CustomContentEvent;
use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailEvent;
use MauticPlugin\JotaworksDoiBundle\Service\UnsubscribeRuleStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stores the one-click rule of an email after save, copies it to clones saved without the form,
 * removes it with the email, and renders the "Bei Abmeldung" section in the email editor
 * (tab "Advanced", hook email.settings.advanced).
 */
class UnsubscribeRuleEmailSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UnsubscribeRuleStore $rules,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            EmailEvents::EMAIL_POST_SAVE       => ['onEmailPostSave', 0],
            EmailEvents::EMAIL_POST_DELETE     => ['onEmailPostDelete', 0],
            CoreEvents::VIEW_INJECT_CUSTOM_CONTENT => ['onCustomContent', 0],
        ];
    }

    public function onEmailPostSave(EmailEvent $event): void
    {
        $email = $event->getEmail();
        $id    = (int) $email->getId();
        if (!$id) {
            return;
        }

        $values = $this->rules->takePending($email);
        if (null !== $values) {
            $this->rules->save($id, $values);

            return;
        }

        $clonedId = $email->getClonedId();
        if ($event->isNew() && $clonedId && $this->rules->copy($clonedId, $id)) {
            $this->logger->info('JotaworksDoi: one-click rule copied to cloned email', ['from' => $clonedId, 'to' => $id]);
        }
    }

    public function onEmailPostDelete(EmailEvent $event): void
    {
        $email = $event->getEmail();
        $id    = (int) ($email->deletedId ?? $email->getId());
        if ($id) {
            $this->rules->delete($id);
        }
    }

    public function onCustomContent(CustomContentEvent $event): void
    {
        if (!$event->checkContext('@MauticEmail/Email/form.html.twig', 'email.settings.advanced')) {
            return;
        }
        $vars = $event->getVars();
        if (!isset($vars['form']->children['jw_unsub_mode'])) {
            return;
        }
        $event->addTemplate('@JotaworksDoi/Email/unsubscribe_rule.html.twig', ['form' => $vars['form']]);
    }
}
