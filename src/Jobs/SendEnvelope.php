<?php

namespace Xstrm\Xstrm\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Xstrm\Xstrm\Transport;

class SendEnvelope implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public function __construct(public array $envelope) {}

    public function handle(Transport $transport): void
    {
        // post() swallows its own failures, so this job never fails and never
        // retries — telemetry must not pile up in the customer's failed_jobs.
        $transport->post($this->envelope);
    }
}
