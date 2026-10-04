<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Queue;

class AuditedTestJob implements ShouldQueue
{
    private $nested;
    private $fail;

    public function __construct(bool $nested = false, bool $fail = false)
    {
        $this->nested = $nested;
        $this->fail = $fail;
    }

    public function handle(): void
    {
        if ($this->nested) Queue::connection('sync')->push(new self());
        User::findOrFail(6)->update(['email' => $this->nested ? 'parent@example.test' : 'child@example.test']);
        if ($this->fail) throw new \RuntimeException('secret-job-failure');
    }
}
