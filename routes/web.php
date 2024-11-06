<?php

use Goophim\Ultracrawler\Http\Controllers\UltracrawlerController;
use Goophim\Ultracrawler\Http\Controllers\CrawlController;
use Goophim\Ultracrawler\Http\Controllers\CrawlerSettingController;
use Illuminate\Support\Facades\Route;

Route::group([
    'prefix'     => config('backpack.base.route_prefix', 'admin'),
    'middleware' => array_merge(
        (array) config('backpack.base.web_middleware', 'web'),
        (array) config('backpack.base.middleware_key', 'admin')
    ),
], function () {
    Route::post('plugin/ultracrawler/crawl', [CrawlController::class, 'crawl'])->name('ultracrawler.crawl');
    Route::get('plugin/ultracrawler/fetch', [CrawlController::class, 'fetch'])->name('ultracrawler.fetch');
    Route::post('plugin/ultracrawler/get-movies', [CrawlController::class, 'getMoviesFromParams'])->name('ultracrawler.merge');
    Route::get('plugin/ultracrawler', [UltracrawlerController::class, 'index'])->name('ultracrawler.index');
    Route::get('plugin/ultracrawler/options', [CrawlerSettingController::class, 'editOptions'])->name('ultracrawler.options');
    Route::put('plugin/ultracrawler/options', [CrawlerSettingController::class, 'updateOptions'])->name('ultracrawler.update_options');
    Route::get('plugin/ultracrawler/apimanager', [UltracrawlerController::class, 'apiManager'])->name('ultracrawler.apimanager');
    Route::get('plugin/ultracrawler/merge', [UltracrawlerController::class, 'mergeMovieData'])->name('ultracrawler.merge');
    Route::get('plugin/ultracrawler/update', [UltracrawlerController::class, 'mergeUpdateData'])->name('ultracrawler.update');
    Route::get('plugin/ultracrawler/test', [UltracrawlerController::class, 'test'])->name('ultracrawler.test');
});
