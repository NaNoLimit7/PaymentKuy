<?php

namespace App\Providers;

use App\Contracts\BlockchainGateway;
use App\Gateways\CliBlockchainGateway;
use App\Gateways\HttpBlockchainGateway;
use App\Gateways\MockBlockchainGateway;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(BlockchainGateway::class, function () {
            $driver = config('payment.blockchain.driver', 'mock');

            return match ($driver) {
                'http' => $this->app->make(HttpBlockchainGateway::class),
                'cli' => $this->app->make(CliBlockchainGateway::class),
                default => $this->app->make(MockBlockchainGateway::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
