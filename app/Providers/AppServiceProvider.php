<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * App-wide setup run when the application boots (web, artisan, queue, Reverb).
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Index-length limit for older MySQL/MariaDB with utf8mb4.
        Schema::defaultStringLength(191);
    }
}
