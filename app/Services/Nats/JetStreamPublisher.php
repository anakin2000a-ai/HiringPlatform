<?php

namespace App\Services\Nats;

use Basis\Nats\Client;
use Exception;
use InvalidArgumentException;
use RuntimeException;

class JetStreamPublisher
{
    public function __construct(private readonly NatsClientFactory $factory) {}

    /**
     * Publish a payload to a JetStream subject and return the ACK array.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     * @throws InvalidArgumentException if the subject is not in the configured allowlist
     * @throws RuntimeException if JetStream is disabled
     * @throws Exception on publish failure
     */
    public function publish(string $subject, array $payload): array
    {
        if (! (bool) config('nats.jetstream.enabled', true)) {
            throw new RuntimeException('JetStream is disabled in configuration (nats.jetstream.enabled).');
        }

        $this->assertSubjectAllowed($subject);

        $json       = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $streamName = (string) config('nats.jetstream.stream');

        $client = $this->makeClient();
        $result = $client->getApi()->getStream($streamName)->put($subject, $json);

        return $this->normalizeAck($result);
    }

    private function makeClient(): Client
    {
        return $this->factory->make();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAck(mixed $ack): array
    {
        if (is_array($ack)) {
            return $ack;
        }

        if (is_object($ack) && method_exists($ack, 'toArray')) {
            return $ack->toArray();
        }

        return ['raw' => $ack];
    }

    private function assertSubjectAllowed(string $subject): void
    {
        $subjects = (array) config('nats.jetstream.subjects', []);

        if (empty($subjects)) {
            return;
        }

        foreach ($subjects as $pattern) {
            if ($this->matchesNatsSubject((string) $pattern, $subject)) {
                return;
            }
        }

        throw new InvalidArgumentException(
            "Subject [{$subject}] is not allowed. Configure allowed subjects in nats.jetstream.subjects."
        );
    }

    private function matchesNatsSubject(string $pattern, string $subject): bool
    {
        $patternTokens = explode('.', $pattern);
        $subjectTokens = explode('.', $subject);

        $pi = 0;
        $si = 0;

        while ($pi < count($patternTokens) && $si < count($subjectTokens)) {
            $tok = $patternTokens[$pi];

            if ($tok === '>') {
                return true;
            }

            if ($tok === '*') {
                $pi++;
                $si++;
                continue;
            }

            if ($tok !== $subjectTokens[$si]) {
                return false;
            }

            $pi++;
            $si++;
        }

        if ($pi < count($patternTokens) && $patternTokens[$pi] === '>') {
            return true;
        }

        return $pi === count($patternTokens) && $si === count($subjectTokens);
    }
}
