<?php

namespace Goophim\Ultracrawler\Http\Controllers;


use Backpack\CRUD\app\Http\Controllers\CrudController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Goophim\Ultracrawler\Crawler;
use Ophim\Core\Models\Movie;
use Goophim\Ultracrawler\Http\Controllers\UltracrawlerController;

class CrawlController extends CrudController
{
    public function fetch(Request $request)
    {
        try {
            $data = collect();
            $sources = $request->get('sources') ?: 'ophim,kkphim,nguonc';

            $request['link'] = preg_split('/[\n\r]+/', $request['link']);

            foreach ($request['link'] as $link) {
                if (preg_match('/(.*?)(\/phim\/)(.*?)/', $link)) {
                    $slug = explode('phim/', $link)[1];
                    $slug = preg_replace('/[\/\?].*$/', '', $slug);
                    $response = (new UltracrawlerController)->mergeMovieData($slug, $sources);
                    $data->push(collect($response['movie'])->only('name', 'slug')->toArray());
                }
                elseif (preg_match('/(.*?)(\/film\/)(.*?)/', $link)) {
                    $slug = explode('film/', $link)[1];
                    $slug = preg_replace('/[\/\?].*$/', '', $slug);
                    $response = (new UltracrawlerController)->mergeMovieData($slug, $sources);
                    $data->push(collect($response['movie'])->only('name', 'slug')->toArray());
                }
                else {
                    for ($i = $request['from']; $i <= $request['to']; $i++) {
                        $response = (new UltracrawlerController)->mergeUpdateData($i, $sources);
                        if ($response['status']) {
                            $data->push(...$response['items']);
                        }
                    }
                }
            }

            return $data->shuffle();
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function showCrawlPage(Request $request)
    {
        $categories = [];
        $regions = [];
        try {
            $categories = Cache::remember('ophim_categories', 86400, function () {
                $data = json_decode(file_get_contents(sprintf('%s/the-loai', config('ophim_crawler.domain', 'https://ophim1.com'))), true) ?? [];
                return collect($data)->pluck('name', 'name')->toArray();
            });

            $regions = Cache::remember('ophim_regions', 86400, function () {
                $data = json_decode(file_get_contents(sprintf('%s/quoc-gia', config('ophim_crawler.domain', 'https://ophim1.com'))), true) ?? [];
                return collect($data)->pluck('name', 'name')->toArray();
            });
        } catch (\Throwable $th) {

        }

        $fields = $this->movieUpdateOptions();

        return view('ophim-crawler::crawl', compact('fields', 'regions', 'categories'));
    }

    public function crawl(Request $request)
    {
        try {
            $crawler = new Crawler(
                $request['slug'], 
                request('fields', []), 
                request('excludedCategories', []), 
                request('excludedRegions', []), 
                request('excludedType', []), 
                request('forceUpdate', false)
            );
            
            // Lấy sources từ request hoặc sử dụng giá trị mặc định
            $sources = request('sources', 'ophim,kkphim,nguonc');
            $crawler->setSources($sources);
            
            $result = $crawler->handle();
            
            return response()->json(['message' => 'OK', 'wait' => $result ?? true]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage(), 'wait' => false], 500);
        }
    }

    protected function movieUpdateOptions(): array
    {
        return [
            'Tiến độ phim' => [
                'episodes' => 'Tập mới',
                'status' => 'Trạng thái phim',
                'episode_time' => 'Thời lượng tập phim',
                'episode_current' => 'Số tập phim hiện tại',
                'episode_total' => 'Tổng số tập phim',
            ],
            'Thông tin phim' => [
                'name' => 'Tên phim',
                'origin_name' => 'Tên gốc phim',
                'content' => 'Mô tả nội dung phim',
                'thumb_url' => 'Ảnh Thumb',
                'poster_url' => 'Ảnh Poster',
                'trailer_url' => 'Trailer URL',
                'quality' => 'Chất lượng phim',
                'language' => 'Ngôn ngữ',
                'notify' => 'Nội dung thông báo',
                'showtimes' => 'Giờ chiếu phim',
                'publish_year' => 'Năm xuất bản',
                'is_copyright' => 'Đánh dấu có bản quyền',
                'tmdb_type' => 'Loại phim TMDB',
                'tmdb_id' => 'ID TMDB',
                'tmdb_season' => 'TMDB Season',
                'tmdb_episode' => 'TMDB Episode',
                'tmdb_vote_average' => 'TMDB Vote Average',
                'tmdb_vote_count' => 'TMDB Vote Count',
                'imdb_id' => 'IMDB ID'
            ],
            'Phân loại' => [
                'type' => 'Định dạng phim',
                'is_shown_in_theater' => 'Đánh dấu phim chiếu rạp',
                'actors' => 'Diễn viên',
                'directors' => 'Đạo diễn',
                'categories' => 'Thể loại',
                'regions' => 'Khu vực',
                'tags' => 'Từ khóa',
                'studios' => 'Studio',
            ]
        ];
    }

    public function getMoviesFromParams(Request $request) {
        $field = explode('-', request('params'))[0];
        $val = explode('-', request('params'))[1];
        if (!$val) {
            return Movie::where($field, $val)->orWhere($field, 'like', '%.com%')->orWhere($field, NULL)->get();
        } else {
            return Movie::where($field, $val)->get();
        }
    }
}
