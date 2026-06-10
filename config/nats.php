<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NATS Connection
    |--------------------------------------------------------------------------
    |
    | Connection settings for the NATS server.  The hiring platform does not
    | require a live NATS server to run tests; the real client is only resolved
    | when the events:consume-nats or events:publish-outbox commands run in a
    | configured environment.
    |
    */

    'host'  => env('NATS_HOST', '127.0.0.1'),
    'port'  => (int) env('NATS_PORT', 4222),
    'user'  => env('NATS_USER', ''),
    'pass'  => env('NATS_PASS', ''),
    'token' => env('NATS_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | JetStream
    |--------------------------------------------------------------------------
    |
    | enabled  — set false to disable JetStream publishing entirely.
    | stream   — the outbound stream this service publishes to.
    | subjects — allowlist of NATS subject patterns for outbound publish.
    |            Empty array = no validation (all subjects allowed).
    |            Supports ">" and "*" wildcards.
    |
    */

    'jetstream' => [
        'enabled'  => (bool) env('NATS_JETSTREAM', true),
        'stream'   => env('NATS_HIRING_PLATFORM_STREAM', 'HIRING_PLATFORM_EVENTS'),
        'subjects' => ['hiring.v1.>'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Streams (Pull Consumer Config)
    |--------------------------------------------------------------------------
    |
    | Each entry configures one JetStream pull consumer.  The consumer is
    | durable so that un-ACKed messages are re-delivered after a crash.
    |
    | To add a new subscription, append an entry here and handle the subject
    | in EventRouter.
    |
    */

    'streams' => [
        [
            'name'           => env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable'        => env('NATS_AUTH_DURABLE', 'HIRING_AUTH_CONSUMER'),
            'filter_subject' => 'auth.v1.>',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Pull Consumer Tuning
    |--------------------------------------------------------------------------
    */

    'pull' => [
        'batch'           => (int) env('NATS_PULL_BATCH', 10),
        'expires_seconds' => (int) env('NATS_PULL_EXPIRES', 5),
        'timeout_ms'      => (int) env('NATS_PULL_TIMEOUT_MS', 5000),
        'sleep_ms'        => (int) env('NATS_PULL_SLEEP_MS', 100),
    ],

];
