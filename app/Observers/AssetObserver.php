<?php

namespace App\Observers;

use App\Models\Screen;

class AssetObserver
{
    public function creating(Screen $asset): void
    {
        if (empty($asset->screen_code)) {
            $asset->screen_code = 'AST-' . strtoupper(substr(md5(uniqid()), 0, 6));
        }
    }

    public function created(Screen $asset): void
    {
        \App\Domain\Asset\Events\AssetCreated::dispatch($asset);
    }
}
