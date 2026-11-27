<?php

namespace Cinderwatch\WordPress\Health;

use Cinderwatch\Health\HealthSnapshot;
use Cinderwatch\Health\Wire\SnapshotSerializer;

/**
 * Persists the most recent snapshot in a single transient that is
 * overwritten on every save and expires after a day.
 */
class SnapshotTransient
{
    public const NAME = 'cinder_health_snapshot';

    private const EXPIRES_AFTER_SECONDS = 86400;

    public function __construct(
        private readonly SnapshotSerializer $serializer = new SnapshotSerializer,
    ) {}

    public function save(HealthSnapshot $snapshot): void
    {
        set_transient(self::NAME, $this->serializer->encode($snapshot), self::EXPIRES_AFTER_SECONDS);
    }

    public function load(): ?HealthSnapshot
    {
        return $this->serializer->decode(get_transient(self::NAME));
    }
}
