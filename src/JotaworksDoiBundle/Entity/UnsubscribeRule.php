<?php

declare(strict_types=1);

namespace MauticPlugin\JotaworksDoiBundle\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Per-email one-click unsubscribe rule (2.4.0), set in the email editor ("Bei Abmeldung").
 * Plain email id instead of an association: the email table stays untouched, the row is removed
 * by UnsubscribeRuleEmailSubscriber when the email is deleted.
 */
class UnsubscribeRule
{
    public const TABLE_NAME = 'jw_doi_unsubscribe_rule';

    public const MODE_GLOBAL         = '';
    public const MODE_AUTO           = 'auto';
    public const MODE_BLOCK          = 'block';
    public const MODE_SEGMENTS       = 'segments';
    public const MODE_CAMPAIGN       = 'campaign';
    public const MODE_FIXED_SEGMENTS = 'fixed_segments';

    private int $emailId;

    private string $mode = self::MODE_GLOBAL;

    /** @var int[] */
    private array $segmentIds = [];

    private string $fallback = self::MODE_GLOBAL;

    private \DateTimeInterface $dateAdded;

    private \DateTimeInterface $dateModified;

    public function __construct(int $emailId)
    {
        $this->emailId      = $emailId;
        $this->dateAdded    = new \DateTime();
        $this->dateModified = new \DateTime();
    }

    /**
     * @param ORM\ClassMetadata<self> $metadata
     */
    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);
        $builder->setTable(self::TABLE_NAME);
        $builder->createField('emailId', Types::INTEGER)
            ->columnName('email_id')
            ->makePrimaryKey()
            ->build();
        $builder->addNamedField('mode', Types::STRING, 'mode');
        $builder->addNamedField('segmentIds', Types::JSON, 'segment_ids');
        $builder->addNamedField('fallback', Types::STRING, 'fallback');
        $builder->addNamedField('dateAdded', Types::DATETIME_MUTABLE, 'date_added');
        $builder->addNamedField('dateModified', Types::DATETIME_MUTABLE, 'date_modified');
    }

    public function getEmailId(): int
    {
        return $this->emailId;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * @return int[]
     */
    public function getSegmentIds(): array
    {
        return array_values(array_map('intval', $this->segmentIds));
    }

    /**
     * @param array<int|string> $segmentIds
     */
    public function setSegmentIds(array $segmentIds): self
    {
        $this->segmentIds = array_values(array_unique(array_filter(array_map('intval', $segmentIds))));

        return $this;
    }

    public function getFallback(): string
    {
        return $this->fallback;
    }

    public function setFallback(string $fallback): self
    {
        $this->fallback = $fallback;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getDateModified(): \DateTimeInterface
    {
        return $this->dateModified;
    }

    public function touch(): self
    {
        $this->dateModified = new \DateTime();

        return $this;
    }

    /** True when the rule only repeats the global settings and needs no row. */
    public function isEmpty(): bool
    {
        return self::MODE_GLOBAL === $this->mode && self::MODE_GLOBAL === $this->fallback && [] === $this->segmentIds;
    }
}
