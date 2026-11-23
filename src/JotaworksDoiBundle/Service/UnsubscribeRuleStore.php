<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\JotaworksDoiBundle\Entity\UnsubscribeRule;

/**
 * Reads and writes the per-email one-click rules (table jw_doi_unsubscribe_rule) and keeps the
 * values submitted in the email form until the email is saved (a new email has no id before).
 */
class UnsubscribeRuleStore
{
    /** @var array<int, array{mode: string, segments: int[], fallback: string}> keyed by spl_object_id of the email */
    private array $pending = [];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function find(?int $emailId): ?UnsubscribeRule
    {
        if (!$emailId) {
            return null;
        }

        return $this->em->find(UnsubscribeRule::class, $emailId);
    }

    /**
     * @param array{mode: string, segments: int[], fallback: string} $values
     */
    public function remember(Email $email, array $values): void
    {
        $this->pending[spl_object_id($email)] = $values;
    }

    /**
     * @return array{mode: string, segments: int[], fallback: string}|null
     */
    public function takePending(Email $email): ?array
    {
        $key    = spl_object_id($email);
        $values = $this->pending[$key] ?? null;
        unset($this->pending[$key]);

        return $values;
    }

    /**
     * Stores the rule; a rule that only repeats the global settings removes the row.
     *
     * @param array{mode: string, segments: int[], fallback: string} $values
     */
    public function save(int $emailId, array $values): void
    {
        $rule  = $this->find($emailId);
        $isNew = null === $rule;
        $rule ??= new UnsubscribeRule($emailId);
        $rule->setMode($values['mode'])
            ->setSegmentIds(UnsubscribeRule::MODE_FIXED_SEGMENTS === $values['mode'] ? $values['segments'] : [])
            ->setFallback($values['fallback'])
            ->touch();

        if ($rule->isEmpty()) {
            if (!$isNew) {
                $this->em->remove($rule);
                $this->em->flush();
            }

            return;
        }

        $this->em->persist($rule);
        $this->em->flush();
    }

    /** Copies the rule of an email to its clone; returns false when the source has none. */
    public function copy(int $fromEmailId, int $toEmailId): bool
    {
        $source = $this->find($fromEmailId);
        if (!$source || $this->find($toEmailId)) {
            return false;
        }

        $this->save($toEmailId, [
            'mode'     => $source->getMode(),
            'segments' => $source->getSegmentIds(),
            'fallback' => $source->getFallback(),
        ]);

        return true;
    }

    public function delete(int $emailId): void
    {
        $rule = $this->find($emailId);
        if ($rule) {
            $this->em->remove($rule);
            $this->em->flush();
        }
    }
}
