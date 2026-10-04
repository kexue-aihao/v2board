<?php

namespace App\Observers;

use App\Services\RatePolicyService;
use Illuminate\Database\Eloquent\Model;

class RatePolicyNodeObserver
{
    public function created(Model $node): void
    {
        // Explicitly reusing a deleted node ID must not inherit its old policy.
        $this->deleted($node);
    }

    public function deleted(Model $node): void
    {
        (new RatePolicyService())->forgetNode(substr($node->getTable(), strlen('v2_server_')), (int) $node->getKey());
    }
}
