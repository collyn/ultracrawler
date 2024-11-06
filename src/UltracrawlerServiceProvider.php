<?php

namespace Goophim\Ultracrawler;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as SP;
use Goophim\Ultracrawler\Console\CrawlerScheduleCommand;
use Goophim\Ultracrawler\Option;

class UltracrawlerServiceProvider extends SP
{
    /**
     * Get the policies defined on the provider.
     *
     * @return array
     */
    public function policies()
    {
        return [];
    }

    public function register()
    {
        $admin_prefix = config('backpack.base.route_prefix', 'admin');
        config(['plugins' => array_merge(config('plugins', []), [
            'goophim/ultracrawler' =>
            [
                'name' => 'Ultracrawler',
                'package_name' => 'goophim/ultracrawler',
                'icon' => 'la la-hand-grab-o',
                'entries' => [
                    ['name' => 'Crawler', 'icon' => 'la la-hand-grab-o', 'url' => url($admin_prefix .'/plugin/ultracrawler')],
                    ['name' => 'Option', 'icon' => 'la la-cog', 'url' => url($admin_prefix .'/plugin/ultracrawler/options')],
                ],
            ]
        ])]);

        config(['logging.channels' => array_merge(config('logging.channels', []), [
            'ultracrawler' => [
                'driver' => 'daily',
                'path' => storage_path('logs/goophim/ultracrawler.log'),
                'level' => env('LOG_LEVEL', 'debug'),
                'days' => 7,
            ],
        ])]);

        config(['ophim.updaters' => array_merge(config('ophim.updaters', []), [
            [
                'name' => 'Ultracrawler',
                'handler' => 'Goophim\Ultracrawler\Crawler'
            ]
        ])]);
    }

    public function boot()
    {
        $this->commands([
            CrawlerScheduleCommand::class,
        ]);

        $this->app->booted(function () {
            $this->loadScheduler();
        });

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'ultracrawler');
    }

    protected function loadScheduler()
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('ultracrawler:schedule')->cron(Option::get('crawler_schedule_cron_config', '*/10 * * * *'))->withoutOverlapping();
    }
}
