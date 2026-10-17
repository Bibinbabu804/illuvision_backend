<?php

namespace App\Domain\Asset\Events;

use App\Models\Screen;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Screen $asset
    ) {}
}
