<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function fetch(Request $request)
    {
        $counts = PlanService::countActiveUsers();
        $plans = Plan::where('show', 1)
            ->orderBy('sort', 'ASC')
            ->get();

        $data = $plans->map(function (Plan $plan) use ($counts) {
            $capacity = $plan->capacity_limit;
            if ($capacity !== null && isset($counts[$plan->id])) {
                $capacity -= $counts[$plan->id]->count;
            }

            return [
                'id' => $plan->id,
                'name' => $plan->name,
                'content' => $plan->content,
                'transfer_enable' => $plan->transfer_enable,
                'device_limit' => $plan->device_limit,
                'speed_limit' => $plan->speed_limit,
                'month_price' => $plan->month_price,
                'quarter_price' => $plan->quarter_price,
                'half_year_price' => $plan->half_year_price,
                'year_price' => $plan->year_price,
                'two_year_price' => $plan->two_year_price,
                'three_year_price' => $plan->three_year_price,
                'onetime_price' => $plan->onetime_price,
                'capacity_limit' => $capacity,
                'show' => (int)$plan->show,
                'renew' => (int)$plan->renew,
            ];
        })->values();

        return response(['data' => $data]);
    }
}
