<?php

namespace Cinderwatch\WordPress;

use Cinderwatch\CinderClient;
use Cinderwatch\Health\Inspector;
use Cinderwatch\Health\Probe;
use Closure;
use Throwable;

class CinderManager
{
    public function __construct(
        private readonly CinderClient $client,
        private readonly Inspector $inspector,
    ) {}

    public function client(): CinderClient
    {
        return $this->client;
    }

    public function health(): Inspector
    {
        return $this->inspector;
    }

    /**
     * @param  iterable<Probe>  $probes
     */
    public function probes(iterable $probes): void
    {
        $this->inspector->replace($probes);
    }

    /**
     * @param  Closure(Throwable): bool  $predicate
     */
    public function reportOnlyWhen(Closure $predicate): void
    {
        $this->client->reportOnlyWhen($predicate);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>|null  $fingerprint
     * @return array<string, mixed>|null
     */
    public function report(Throwable $throwable, array $context = [], ?bool $handled = null, ?array $fingerprint = null): ?array
    {
        return $this->client->report($throwable, $context, $handled, $fingerprint);
    }

    /**
     * Return null from the resolver to keep the default grouping.
     *
     * @param  Closure(Throwable): (list<string>|null)  $resolver
     */
    public function fingerprintUsing(Closure $resolver): void
    {
        $this->client->fingerprintUsing($resolver);
    }
}
