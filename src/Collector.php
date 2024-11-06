<?php

namespace Goophim\Ultracrawler;

use Illuminate\Support\Facades\Log;
use Intervention\Image\ImageManagerStatic as Image;
use Illuminate\Support\Facades\Storage;

class Collector
{
    protected $fields;
    protected $payload;
    protected $forceUpdate;

    public function __construct(array $payload, array $fields, $forceUpdate)
    {
        $this->fields = $fields;
        $this->payload = $payload;
        $this->forceUpdate = $forceUpdate;
    }

    public function get(): array
    {
        $info = $this->payload['movie'] ?? [];
        $episodes = $this->payload['episodes'] ?? [];

        $data = [
            'name' => $info['name'],
            'origin_name' => $info['origin_name'],
            'publish_year' => $info['year'],
            'content' => $info['content'],
            'type' =>  $this->getMovieType($info, $episodes),
            'status' => $info['status'],
            'thumb_url' => $this->getThumbImage($info['slug'], $info['thumb_url']),
            'poster_url' => $this->getPosterImage($info['slug'], $info['poster_url']),
            'is_copyright' => $info['is_copyright'],
            'trailer_url' => $info['trailer_url'] ?? "",
            'quality' => $info['quality'],
            'language' => $info['lang'],
            'episode_time' => $info['time'],
            'episode_current' => $info['episode_current'],
            'episode_total' => $info['episode_total'],
            'notify' => $info['notify'],
            'showtimes' => $info['showtimes'],
            'is_shown_in_theater' => $info['chieurap'],
        ];

        return $data;
    }

    public function getThumbImage($slug, $url)
    {
        return $this->getImage(
            $slug,
            $url,
            Option::get('should_resize_thumb', false),
            Option::get('resize_thumb_width'),
            Option::get('resize_thumb_height')
        );
    }

    public function getPosterImage($slug, $url)
    {
        return $this->getImage(
            $slug,
            $url,
            Option::get('should_resize_poster', false),
            Option::get('resize_poster_width'),
            Option::get('resize_poster_height')
        );
    }


    protected function getMovieType($info, $episodes)
    {
        return $info['type'] == 'series' ? 'series'
            : ($info['type'] == 'single' ? 'single'
                : (count(reset($episodes)['server_data'] ?? []) > 1 ? 'series' : 'single'));
    }

    protected function getImage($slug, string $url, $shouldResize = false, $width = null, $height = null): string
    {
        if (!Option::get('download_image', false) || empty($url)) {
            return $url;
        }
        try {
            $url = strtok($url, '?');
            $filename = substr($url, strrpos($url, '/') + 1);
            if (Option::get('convert_to_webp', false)) {
                $filename = pathinfo($filename, PATHINFO_FILENAME) . '.webp';
            }
            $path = "images/{$slug}/{$filename}";
            
            $disk = strval(Option::get('storage_disk', 'public'));

            if (Storage::disk($disk)->exists($path) && $this->forceUpdate == false) {
                return Storage::url($path);
            }
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_BINARYTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/111.0.0.0 Safari/537.36");
            $image_data = curl_exec($ch);
            curl_close($ch);

            $img = Image::make($image_data);

            if ($shouldResize) {
                $img->resize($width, $height, function ($constraint) {
                    $constraint->aspectRatio();
                });
            }
            
            $tempPath = tempnam(sys_get_temp_dir(), 'img_');
            if (Option::get('convert_to_webp', false)) {
                $img->encode('webp', Option::get('webp_quality', 80));
            }
            $img->save($tempPath);
            Storage::disk($disk)->put($path, file_get_contents($tempPath));
            unlink($tempPath);

            return Storage::url($path);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            return $url;
        }
    }
}
