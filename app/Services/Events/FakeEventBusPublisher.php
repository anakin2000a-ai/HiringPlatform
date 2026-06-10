<?php

namespace App\Services\Events;

use RuntimeException;

class FakeEventBusPublisher implements EventBusPublisher
{
    /** @var array<int, array{subject: string, payload: array<string, mixed>}> */
    private array $published = [];

    private bool $shouldFail = false;

    private string $failureMessage = 'Fake publisher failure';

    public function publish(string $subject, array $payload): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException($this->failureMessage);
        }

        $this->published[] = ['subject' => $subject, 'payload' => $payload];
    }

    public function makeFail(string $message = 'Fake publisher failure'): void
    {
        $this->shouldFail     = true;
        $this->failureMessage = $message;
    }

    /** @return array<int, array{subject: string, payload: array<string, mixed>}> */
    public function published(): array
    {
        return $this->published;
    }

    public function reset(): void
    {
        $this->published      = [];
        $this->shouldFail     = false;
        $this->failureMessage = 'Fake publisher failure';
    }
}
