<?php

namespace Goophim\Ultracrawler;

use Ophim\Core\Models\Movie;
use Illuminate\Support\Str;
use Ophim\Core\Models\Actor;
use Ophim\Core\Models\Category;
use Ophim\Core\Models\Director;
use Ophim\Core\Models\Episode;
use Ophim\Core\Models\Region;
use Ophim\Core\Models\Tag;
use Goophim\Ultracrawler\Contracts\BaseCrawler;
use Goophim\Ultracrawler\Http\Controllers\UltracrawlerController;

class Crawler extends BaseCrawler
{
    protected $sources;

    public function setSources($sources)
    {
        $this->sources = $sources;
        return $this;
    }

    public function handle()
    {
        $sources = $this->sources ?? 'ophim,kkphim,nguonc';
        
        $payload = (new UltracrawlerController)->mergeMovieData($this->link, $sources);
        $body = json_encode($payload);
        $this->checkIsInExcludedList($payload);

        $movie = Movie::where('update_handler', static::class)
            ->where('update_identity', $payload['movie']['_id'])
            ->first();

        if (!$this->hasChange($movie, md5($body)) && $this->forceUpdate == false) {
            return false;
        }

        $info = (new Collector($payload, $this->fields, $this->forceUpdate))->get();

        if ($movie) {
            $movie->updated_at = now();
            $movie->update(collect($info)->only($this->fields)->merge(['update_checksum' => md5($body)])->toArray());
        } else {
            $movie = Movie::create(array_merge($info, [
                'update_handler' => static::class,
                'update_identity' => $payload['movie']['_id'],
                'update_checksum' => md5($body)
            ]));
        }

        $this->syncActors($movie, $payload);
        $this->syncDirectors($movie, $payload);
        $this->syncCategories($movie, $payload);
        $this->syncRegions($movie, $payload);
        $this->syncTags($movie, $payload);
        $this->syncStudios($movie, $payload);
        $this->updateEpisodes($movie, $payload);
    }

    protected function hasChange(?Movie $movie, $checksum)
    {
        return is_null($movie) || ($movie->update_checksum != $checksum);
    }

    protected function checkIsInExcludedList($payload)
    {
        $newType = $payload['movie']['type'];
        if (in_array($newType, $this->excludedType)) {
            throw new \Exception("Thuộc định dạng đã loại trừ");
        }

        $newCategories = collect($payload['movie']['category'])->pluck('name')->toArray();
        if (array_intersect($newCategories, $this->excludedCategories)) {
            throw new \Exception("Thuộc thể loại đã loại trừ");
        }

        $newRegions = collect($payload['movie']['country'])->pluck('name')->toArray();
        if (array_intersect($newRegions, $this->excludedRegions)) {
            throw new \Exception("Thuộc quốc gia đã loại trừ");
        }
    }

    protected function syncActors($movie, array $payload)
    {
        if (!in_array('actors', $this->fields)) return;

        $actors = [];
        foreach ($payload['movie']['actor'] as $actor) {
            if (!trim($actor)) continue;
            $actors[] = Actor::firstOrCreate(['name' => trim($actor)])->id;
        }
        $movie->actors()->sync($actors);
    }

    protected function syncDirectors($movie, array $payload)
    {
        if (!in_array('directors', $this->fields)) return;

        $directors = [];
        foreach ($payload['movie']['director'] as $director) {
            if (!trim($director)) continue;
            $directors[] = Director::firstOrCreate(['name' => trim($director)])->id;
        }
        $movie->directors()->sync($directors);
    }

    protected function syncCategories($movie, array $payload)
    {
        if (!in_array('categories', $this->fields)) return;
        $categories = [];
        foreach ($payload['movie']['category'] as $category) {
            if (!trim($category['name'])) continue;
            $categories[] = Category::firstOrCreate(['name' => trim($category['name'])])->id;
        }
        if($payload['movie']['type'] === 'hoathinh') $categories[] = Category::firstOrCreate(['name' => 'Hoạt Hình'])->id;
        if($payload['movie']['type'] === 'tvshows') $categories[] = Category::firstOrCreate(['name' => 'TV Shows'])->id;
        $movie->categories()->sync($categories);
    }

    protected function syncRegions($movie, array $payload)
    {
        if (!in_array('regions', $this->fields)) return;

        $regions = [];
        foreach ($payload['movie']['country'] as $region) {
            if (!trim($region['name'])) continue;
            $regions[] = Region::firstOrCreate(['name' => trim($region['name'])])->id;
        }
        $movie->regions()->sync($regions);
    }

    protected function syncTags($movie, array $payload)
    {
        if (!in_array('tags', $this->fields)) return;

        $tags = [];
        $tags[] = Tag::firstOrCreate(['name' => trim($movie->name)])->id;
        $tags[] = Tag::firstOrCreate(['name' => trim($movie->origin_name)])->id;

        $movie->tags()->sync($tags);
    }

    protected function syncStudios($movie, array $payload)
    {
        if (!in_array('studios', $this->fields)) return;
    }

    protected function updateEpisodes($movie, $payload)
    {
        if (!in_array('episodes', $this->fields)) return;
        
        // Lấy danh sách server hiện có
        $existingServers = $movie->episodes()->distinct()->pluck('server')->toArray();
        
        foreach ($payload['episodes'] as $server) {
            // Nếu server_name mới không tồn tại trong danh sách cũ
            if (!in_array($server['server_name'], $existingServers)) {
                foreach ($server['server_data'] as $episode) {
                    if ($episode['link_m3u8']) {
                        Episode::create([
                            'name' => $episode['name'],
                            'movie_id' => $movie->id,
                            'server' => $server['server_name'],
                            'type' => 'm3u8',
                            'link' => $episode['link_m3u8'],
                            'slug' => 'tap-' . Str::slug($episode['name'])
                        ]);
                    }
                    if ($episode['link_embed']) {
                        Episode::create([
                            'name' => $episode['name'],
                            'movie_id' => $movie->id,
                            'server' => $server['server_name'],
                            'type' => 'embed',
                            'link' => $episode['link_embed'],
                            'slug' => 'tap-' . Str::slug($episode['name'])
                        ]);
                    }
                }
            } else {
                // Cập nhật episodes cho server đã tồn tại
                $flag = 0;
                $existingEpisodes = $movie->episodes()->where('server', $server['server_name'])->get();
                
                foreach ($server['server_data'] as $episode) {
                    if ($episode['link_m3u8']) {
                        Episode::updateOrCreate([
                            'id' => $existingEpisodes[$flag]->id ?? null,
                            'server' => $server['server_name'],
                            'movie_id' => $movie->id,
                        ], [
                            'name' => $episode['name'],
                            'type' => 'm3u8',
                            'link' => $episode['link_m3u8'],
                            'slug' => 'tap-' . Str::slug($episode['name'])
                        ]);
                        $flag++;
                    }
                    if ($episode['link_embed']) {
                        Episode::updateOrCreate([
                            'id' => $existingEpisodes[$flag]->id ?? null,
                            'server' => $server['server_name'],
                            'movie_id' => $movie->id,
                        ], [
                            'name' => $episode['name'],
                            'type' => 'embed',
                            'link' => $episode['link_embed'],
                            'slug' => 'tap-' . Str::slug($episode['name'])
                        ]);
                        $flag++;
                    }
                }
                
                // Xóa các episode cũ thừa
                if (isset($existingEpisodes) && count($existingEpisodes) > $flag) {
                    for ($i = $flag; $i < count($existingEpisodes); $i++) {
                        $existingEpisodes[$i]->delete();
                    }
                }
            }
        }
    }
}
