<?php

namespace Goophim\Ultracrawler\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Ophim\Core\Models\Movie;

class UltracrawlerController extends Controller
{
    public function __construct()
    {
        
    }

    public function index()
    {
        $categories = $this->getCategories();
        $regions = $this->getRegions();
        $fields = $this->movieUpdateOptions();

        return view('ultracrawler::crawl', compact('fields', 'regions', 'categories'));
    }

    public function apiManager()
    {
        return view('ultracrawler::apimanager');
    }
    
    public function slugify($text, string $divider = '-')
    {
        // Xử lý dấu tiếng Việt trước
        $text = $this->removeAccents($text);
        
        // Chuyển sang chữ thường
        $text = mb_strtolower($text, 'UTF-8');
        
        // Thay thế các ký tự đặc biệt bằng divider
        $text = preg_replace('~[^\p{L}\p{N}]+~u', $divider, $text);
        
        // Xóa divider ở đầu và cuối
        $text = trim($text, $divider);
        
        // Thay thế nhiều divider liên tiếp thành một
        $text = preg_replace('~-+~', $divider, $text);
        
        if (empty($text)) {
            return 'n-a';
        }
        
        return $text;
    }

    private function removeAccents($str)
    {
        $str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/", 'a', $str);
        $str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/", 'e', $str);
        $str = preg_replace("/(ì|í|ị|ỉ|ĩ)/", 'i', $str);
        $str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/", 'o', $str);
        $str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/", 'u', $str);
        $str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/", 'y', $str);
        $str = preg_replace("/(đ)/", 'd', $str);
        return $str;
    }
    public function extractOriginName($input)
    {
        $pattern = '/[a-zA-Z\s:]+/';
        preg_match_all($pattern, $input, $matches);
        if (!empty($matches[0])) {
            $englishTitle = trim(end($matches[0]));
            if (strpos($input, ':') !== false && strpos($englishTitle, ':') === false) {
                foreach ($matches[0] as $match) {
                    if (strpos($match, ':') !== false) {
                        $englishTitle = trim($match);
                        break;
                    }
                }
            }
            if (str_word_count($englishTitle) > 1) {
                return $englishTitle;
            }
        }
        return $input;
    }

    public function multiCrawler($slug = null, $page = 1, $sources = [])
    {
        // Định nghĩa base URLs cho từng nguồn
        $baseUrls = [
            'ophim' => [
                'detail' => 'https://ophim1.com/phim/',
                'update' => 'https://ophim1.com/danh-sach/phim-moi-cap-nhat'
            ],
            'kkphim' => [
                'detail' => 'https://phimapi.com/phim/',
                'update' => 'https://phimapi.com/danh-sach/phim-moi-cap-nhat'
            ],
            'nguonc' => [
                'detail' => 'https://phim.nguonc.com/api/film/',
                'update' => 'https://phim.nguonc.com/api/films/phim-moi-cap-nhat'
            ]
        ];

        // Xây dựng danh sách URLs cần crawl
        $urls = [];
        foreach ($sources as $source) {
            if (isset($baseUrls[$source])) {
                if ($slug) {
                    $urls[$source] = $baseUrls[$source]['detail'] . $slug;
                } else {
                    $urls[$source . '_update'] = $baseUrls[$source]['update'] . '?page=' . $page;
                }
            }
        }

        // Nếu không có URL nào cần crawl
        if (empty($urls)) {
            return [];
        }

        $mh = curl_multi_init();
        $curlHandles = [];

        // Khởi tạo curl handles
        foreach ($urls as $key => $url) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Thêm timeout
            curl_multi_add_handle($mh, $ch);
            $curlHandles[$key] = $ch;
        }

        // Thực thi requests
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh); // Tránh CPU spike
            }
        } while ($running > 0 && $status == CURLM_OK);

        // Thu thập kết quả
        $results = [];
        foreach ($urls as $key => $url) {
            $ch = $curlHandles[$key];
            if (($errno = curl_errno($ch)) || ($error = curl_error($ch))) {
                Log::error("Curl error for $url: $errno - $error");
                continue;
            }
            $data = curl_multi_getcontent($ch);
            $results[$key] = $this->processData($key, $data, $slug);
            curl_multi_remove_handle($mh, $ch);
        }

        curl_multi_close($mh);

        return $results;
    }

    private function processData($key, $data, $slug)
    {
        $decodedData = json_decode($data, true);
        
        switch ($key) {
            case 'ophim':
                return $this->processOphimData($decodedData);
            case 'kkphim':
                return $this->processKkphimData($decodedData);
            case 'nguonc':
                return $this->processNguoncData($decodedData, $slug);
            case 'ophim_update':
                return $this->processOphimUpdateData($decodedData);
            case 'kkphim_update':
                return $this->processKkphimUpdateData($decodedData);
            case 'nguonc_update':
                return $this->processNguoncUpdateData($decodedData);
            default:
                return $decodedData;
        }
    }

    private function processOphimUpdateData($data)
    {
        foreach ($data['items'] as &$item)
        {
            $originName = $this->removeAccents(mb_strtolower($this->extractOriginName(trim($item['origin_name']))));
            $item['_id'] = md5($originName);
        }
        return $data;
    }
    private function processKkphimUpdateData($data)
    {
        foreach ($data['items'] as &$item)
        {
            $originName = $this->removeAccents(mb_strtolower($this->extractOriginName(trim($item['origin_name']))));
            $item['_id'] = md5($originName);
        }
        return $data;
    }
    private function processNguoncUpdateData($nguonc_arr)
    {
        if($nguonc_arr['status'] === 'success')
        {
            foreach($nguonc_arr['items'] as $item)
            {
                $items[] = [
                    'tmdb' => [
                        'type' => '',
                        'id' => '',
                        'season' => '',
                        'vote_average' => '',
                        'vote_count' => ''
                    ],
                    'imdb' => [
                        'id' => ''
                    ],
                    'modified' => [
                        'time' => $item['modified']
                    ],
                    '_id' => md5($this->removeAccents(mb_strtolower($this->extractOriginName(trim($item['original_name']))))),
                    'name' => $item['name'],
                    'origin_name' => $item['original_name'],
                    'thumb_url' => $item['thumb_url'],
                    'poster_url' => $item['poster_url'],
                    'slug' => $item['slug'],
                    'year' => ''
                ];
            }
            return [
                'status' => true,
                'items' => $items,
                'pathImage'  => env('APP_URL'),
                'pagination' => [
                    'totalItems' => $nguonc_arr['paginate']['total_items'],
                    'totalItemsPerPage' => $nguonc_arr['paginate']['items_per_page'],
                    'currentPage' => $nguonc_arr['paginate']['current_page'],
                    'totalPages' => $nguonc_arr['paginate']['total_page']
                ]
            ];
        }
        else
        {
            return [
                'status' => false,
                'msg' => 'No valid data found'
            ];
        }
    }
    private function processOphimData($data)
    {
        if ($data['status'] == true) {
            $_id = md5($this->removeAccents(mb_strtolower($this->extractOriginName(trim($data['movie']['origin_name'])))) . '-' . trim($data['movie']['year']) . '-' . trim($data['movie']['type']));
            $data['movie']['_id'] = $_id;
            $data['movie']['category'] = $this->processCategoryOrCountry($data['movie']['category']);
            $data['movie']['country'] = $this->processCategoryOrCountry($data['movie']['country']);
        }
        return $data;
    }

    private function processKkphimData($data)
    {
        if ($data['status'] == true) {
            $_id = md5($this->removeAccents(mb_strtolower($this->extractOriginName(trim($data['movie']['origin_name'])))) . '-' . trim($data['movie']['year']) . '-' . trim($data['movie']['type']));
            $data['movie']['_id'] = $_id;
            $data['movie']['thumb_url'] = $data['movie']['poster_url'];
            $data['movie']['poster_url'] = $data['movie']['thumb_url'];
            $data['movie']['category'] = $this->processCategoryOrCountry($data['movie']['category']);
            $data['movie']['country'] = $this->processCategoryOrCountry($data['movie']['country']);
        }
        return $data;
    }

    private function processNguoncData($nguoncData, $slug)
    {
        try {
            if (!isset($nguoncData['movie']) || !isset($nguoncData['movie']['category'])) {
                return [
                    'status' => false, 
                    'msg' => 'Dữ liệu phim không hợp lệ'
                ];
            }

            $type = "series";
            $status = "completed";
            $categoryData = [];
            $countryData = [];
            $year = '';

            // Xử lý type và status
            if (isset($nguoncData['movie']['category'][1]['list']) && is_array($nguoncData['movie']['category'][1]['list'])) {
                foreach($nguoncData['movie']['category'][1]['list'] as $category) {
                    $type_category = $category['name'];
                    if($type_category == 'Phim bộ'){
                        $type = 'series';
                    }elseif($type_category == 'Phim lẻ'){
                        $type = 'single';
                    }elseif($type_category == 'TV shows'){
                        $type = 'tvshows';
                    }
                    if($type_category == 'Phim đang chiếu'){
                        $status = 'ongoing'; 
                    }
                }
            }
            
            // Xử lý hoạt hình
            if (isset($nguoncData['movie']['category'][2]['list']) && is_array($nguoncData['movie']['category'][2]['list'])) {
                foreach($nguoncData['movie']['category'][2]['list'] as $layhoathinh) {
                    $thongtintype = $layhoathinh['name'];
                    if ($thongtintype == "Hoạt Hình"){
                        $type = 'hoathinh';
                    }
                }
            }

            // Xử lý các category groups
            if (isset($nguoncData['movie']['category']) && is_array($nguoncData['movie']['category'])) {
                foreach ($nguoncData['movie']['category'] as $categoryGroup) {
                    if (!isset($categoryGroup['group']['name'])) {
                        continue;
                    }

                    $groupName = strtolower($categoryGroup['group']['name']);
                    if ($groupName === "năm") {
                        if (isset($categoryGroup['list'][0]['name'])) {
                            $year = $categoryGroup['list'][0]['name'];
                        }
                    } elseif ($groupName === "quốc gia") {
                        if (isset($categoryGroup['list']) && is_array($categoryGroup['list'])) {
                            foreach ($categoryGroup['list'] as $country) {
                                if (isset($country['name'])) {
                                    $slug = $this->slugify($country['name']);
                                    $countryData[] = [
                                        "id" => md5($slug), 
                                        "name" => $country['name'],
                                        "slug" => $slug
                                    ];
                                }
                            }
                        }
                    } else {
                        if (isset($categoryGroup['list']) && is_array($categoryGroup['list'])) {
                            foreach ($categoryGroup['list'] as $category) {
                                if (isset($category['name'])) {
                                    $slug = $this->slugify($category['name']);
                                    $categoryData[] = [
                                        "id" => md5($slug), 
                                        "name" => $category['name'],
                                        "slug" => $slug
                                    ];
                                }
                            }
                        }
                    }
                }
            }
            $_id=md5($this->removeAccents(mb_strtolower($this->extractOriginName(trim($nguoncData['movie']['original_name'])))) . '-' . trim($year) . '-' . trim($type));
            $ophimData = [
                "status" => true,
                "msg" => "",
                "movie" => [
                    "_id" => $_id,
                    "name" => $nguoncData['movie']['name'],
                    "slug" => $nguoncData['movie']['slug'],
                    "origin_name" => $this->extractOriginName($nguoncData['movie']['original_name']),
                    "content" => $nguoncData['movie']['description'],
                    "type" => $type,
                    "status" => $status,
                    "thumb_url" => $nguoncData['movie']['thumb_url'],
                    "poster_url" => $nguoncData['movie']['poster_url'],
                    "time" => $nguoncData['movie']['time'],
                    "episode_current" => $nguoncData['movie']['current_episode'],
                    "episode_total" => $nguoncData['movie']['total_episodes'],
                    "quality" => $nguoncData['movie']['quality'],
                    "lang" => $nguoncData['movie']['language'],
                    "is_copyright" => false,
                    "notify" => '',
                    "showtimes" => '',
                    "year" => $year,
                    "view" => 0, 
                    "actor" => $nguoncData['movie']['casts'] ? explode(", ", $nguoncData['movie']['casts']) : [""],
                    "director" => $nguoncData['movie']['director'] ? [$nguoncData['movie']['director']] : [""],
                    "category" => $categoryData,
                    "country" => $countryData,
                    "chieurap" => false, 
                ],
                "episodes" => []
            ];
            foreach ($nguoncData['movie']['episodes'] as $episode) {
                $serverData = [];
                foreach ($episode['items'] as $item) {
                    $serverData[] = [
                        "name" => $item['name'],
                        "slug" => $item['slug'],
                        "filename" => $item['name'], 
                        "link_embed" => $item['embed'],
                        "link_m3u8" => ""
                    ];
                }
                $ophimData['episodes'][] = [
                    "server_name" => $episode['server_name'],
                    "server_data" => $serverData
                ];
            }

            return $ophimData;
        } catch (\Exception $e) {
            return [
                'status' => false, 
                'msg' => 'Đã xảy ra lỗi khi xử lý dữ liệu phim'
            ];
        }
    }
    private function processCategoryOrCountry($items)
    {
        $processedItems = [];
        foreach ($items as $item) {
            $processedItems[] = [
                "id" => md5($item['slug']),
                "name" => $item['name'],
                "slug" => $item['slug']
            ];
        }
        return $processedItems;
    }
    public function mergeUpdateData($page, $sources='ophim,kkphim,nguonc')
    {
        // Chuẩn hóa sources
        if (is_string($sources)) {
            $sources = explode(',', $sources);
        }
        $sources = array_map('trim', $sources);
        $validSources = ['ophim', 'kkphim', 'nguonc'];
        $sources = array_intersect($sources, $validSources);

        // Truyền sources vào multiCrawler
        $multiCrawlerData = $this->multiCrawler(null, $page, $sources);

        $sourceData = [
            'ophim' => $multiCrawlerData['ophim_update'] ?? ['status' => false, 'items' => [], 'pagination' => []],
            'kkphim' => $multiCrawlerData['kkphim_update'] ?? ['status' => false, 'items' => [], 'pagination' => []],
            'nguonc' => $multiCrawlerData['nguonc_update'] ?? ['status' => false, 'items' => [], 'pagination' => []]
        ];

        $mergedDataUpdate = [
            'status' => true,
            'items' => [],
            'pathImage' => env('APP_URL'),
            'pagination' => [
                'totalItems' => 0,
                'totalItemsPerPage' => 0,
                'currentPage' => $page,
                'totalPages' => 0
            ]
        ];

        $allItems = [];
        
        // Chỉ xử lý các nguồn được chọn
        foreach ($sources as $source) {
            $data = $sourceData[$source];
            if ($data['status']) {
                foreach ($data['items'] as $item) {
                    $originName = $this->removeAccents(mb_strtolower($this->extractOriginName(trim($item['origin_name']))));
                    $item['_id'] = md5($originName);
                    $allItems[$item['_id']] = $item;
                }

                $mergedDataUpdate['pagination']['totalItems'] += $data['pagination']['totalItems'] ?? 0;
                $mergedDataUpdate['pagination']['totalItemsPerPage'] += $data['pagination']['totalItemsPerPage'] ?? 0;
                $mergedDataUpdate['pagination']['totalPages'] = max(
                    $mergedDataUpdate['pagination']['totalPages'],
                    $data['pagination']['totalPages'] ?? 0
                );
            }
        }

        // Sắp xếp các mục theo thời gian sửa đổi gần đây nhất
        uasort($allItems, function($a, $b) {
            return strtotime($b['modified']['time'] ?? 0) - strtotime($a['modified']['time'] ?? 0);
        });

        $mergedDataUpdate['items'] = array_values($allItems);

        if (empty($mergedDataUpdate['items'])) {
            return response()->json([
                'status' => false, 
                'msg' => 'Không tìm thấy dữ liệu cập nhật hợp lệ từ các nguồn: ' . implode(', ', $sources)
            ]);
        }

        return $mergedDataUpdate;
    }
    public function test(Request $request)
    {
        $data=Http::get('https://phim.nguonc.com/api/film/'.$request->slug);
        return $this->processNguoncData($data, $request->slug);
    }
    public function mergeMovieData($slug, $sources='ophim,kkphim,nguonc')
    {
        if (is_string($sources)) {
            $sources = explode(',', $sources);
        }
        $sources = array_map('trim', $sources);
        $validSources = ['ophim', 'kkphim', 'nguonc'];
        $sources = array_intersect($sources, $validSources);

        // Truyền sources vào multiCrawler
        $multiCrawlerData = $this->multiCrawler($slug, 1, $sources);

        // Khởi tạo prefixes cho các nguồn
        $prefixes = [
            'ophim' => 'OP',
            'kkphim' => 'KK',
            'nguonc' => 'NC'
        ];
        
        // Khởi tạo dữ liệu từ các nguồn được chọn
        $sourceData = [
            'ophim' => $multiCrawlerData['ophim'] ?? ['status' => false, 'movie' => [], 'episodes' => []],
            'kkphim' => $multiCrawlerData['kkphim'] ?? ['status' => false, 'movie' => [], 'episodes' => []],
            'nguonc' => $multiCrawlerData['nguonc'] ?? ['status' => false, 'movie' => [], 'episodes' => []]
        ];

        // Nhóm các nguồn theo năm phát hành
        $moviesByYear = [];
        foreach ($sources as $source) {
            $data = $sourceData[$source];
            if ($data['status']) {
                $year = $data['movie']['year'] ?? '';
                $year = empty($year) ? 'unknown' : $year; // Xử lý year null hoặc rỗng
                if (!isset($moviesByYear[$year])) {
                    $moviesByYear[$year] = [];
                }
                $moviesByYear[$year][$source] = $data;
            }
        }

        //Nếu không có dữ liệu hoặc không có năm trùng khớp
        if (empty($moviesByYear)) {
            return [
                'status' => false,
                'msg' => 'Không tìm thấy dữ liệu hợp lệ từ các nguồn: ' . implode(', ', $sources)
            ];
        }

        // Tìm nhóm có nhiều nguồn nhất
        $maxSourcesYear = '';
        $maxSourcesCount = 0;
        foreach ($moviesByYear as $year => $yearSources) {
            if (count($yearSources) > $maxSourcesCount) {
                $maxSourcesCount = count($yearSources);
                $maxSourcesYear = $year;
            }
        }

        // Nếu không tìm thấy năm hoặc chỉ có 1 nguồn, lấy nguồn đầu tiên có sẵn
        // if (empty($maxSourcesYear) || $maxSourcesCount == 1) {
        //     $firstYear = array_key_first($moviesByYear);
        //     $maxSourcesYear = $firstYear;
        // }

        // Chỉ sử dụng các nguồn có cùng năm phát hành
        $validSources = array_keys($moviesByYear[$maxSourcesYear]);

        // Khởi tạo mảng kết quả
        $mergedData = [
            'status' => true,
            'msg' => '',
            'movie' => [
                'category' => [],
                'country' => []
            ],
            'episodes' => []
        ];

        // Hàm hỗ trợ để gộp dữ liệu
        $mergeMovieInfo = function($target, $source) {
            foreach ($source as $key => $value) {
                if ($key !== 'category' && $key !== 'country') {
                    if (!isset($target[$key]) || empty($target[$key])) {
                        $target[$key] = $value;
                    } elseif (is_array($value) && is_array($target[$key])) {
                        $target[$key] = array_merge($target[$key], $value);
                    }
                }
            }
            return $target;
        };

        // Gộp thông tin phim
        $categoryMap = [];
        $countryMap = [];

        $mergeTaxonomy = function($map, $items) {
            foreach ($items as $item) {
                $id = $item['id'];
                if (!isset($map[$id])) {
                    $map[$id] = $item;
                }
            }
            return $map;
        };

        // Chỉ xử lý các nguồn có cùng năm phát hành
        foreach ($validSources as $source) {
            $data = $moviesByYear[$maxSourcesYear][$source];
            $mergedData['movie'] = $mergeMovieInfo($mergedData['movie'], $data['movie']);
            $categoryMap = $mergeTaxonomy($categoryMap, $data['movie']['category']);
            $countryMap = $mergeTaxonomy($countryMap, $data['movie']['country']);
        }

        // Chỉ gộp episodes từ các nguồn có cùng năm
        foreach ($validSources as $source) {
            $episodes = $moviesByYear[$maxSourcesYear][$source]['episodes'];
            $prefix = $prefixes[$source];
            foreach ($episodes as $episode) {
                $serverName = $episode['server_name'] ?? "Server";
                $serverName = "$prefix - $serverName";
                $items = [];
                foreach ($episode['server_data'] as $data) {
                    $items[] = [
                        'name' => $data['name'],
                        'slug' => $data['slug'],
                        'link_embed' => $data['link_embed'] ?? '',
                        'link_m3u8' => $data['link_m3u8'] ?? ''
                    ];
                }
                $mergedData['episodes'][] = [
                    'server_name' => $serverName,
                    'server_data' => $items
                ];
            }
        }

        $mergedData['movie']['category'] = array_values($categoryMap);
        $mergedData['movie']['country'] = array_values($countryMap);
        return $mergedData;
    }

    public function showCrawlPage(Request $request)
    {
        $categories = $this->getCategories();
        $regions = $this->getRegions();
        $fields = $this->movieUpdateOptions();

        return view('ultracrawler::crawl', compact('fields', 'regions', 'categories'));
    }

    protected function getCategories()
    {
        return Cache::remember('ultracrawler_categories', 86400, function () {
            $data = json_decode(file_get_contents(sprintf('%s/the-loai', config('ultracrawler.domain', 'https://ophim1.com'))), true) ?? [];
            return collect($data)->pluck('name', 'name')->toArray();
        });
    }

    protected function getRegions()
    {
        return Cache::remember('ultracrawler_regions', 86400, function () {
            $data = json_decode(file_get_contents(sprintf('%s/quoc-gia', config('ultracrawler.domain', 'https://ophim1.com'))), true) ?? [];
            return collect($data)->pluck('name', 'name')->toArray();
        });
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
    public function tikphoMovie($tmdbid)
    {
        $get_embed = Http::get("https://play.123embed.net/mv/{$tmdbid}");
        preg_match_all('/loadMovieServer\(\'([^\']+)\'/m', $get_embed, $matches, PREG_SET_ORDER, 0);
        $id = $matches[0][1];

        // Danh sách các servers cần gọi
        $servers = ['tikpho', 'gd-hls', 'hls-v', 'hls-s', 'hls-f', 'ytstream'];
        
        // Khởi tạo multi curl
        $multiCurl = [];
        $result = [];
        $mh = curl_multi_init();

        // Tạo các curl requests
        foreach ($servers as $server) {
            $url = "https://play.123embed.net/ajax/movie/get_sources/{$id}/{$server}";
            
            $multiCurl[$server] = curl_init();
            curl_setopt($multiCurl[$server], CURLOPT_URL, $url);
            curl_setopt($multiCurl[$server], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($multiCurl[$server], CURLOPT_HEADER, 0);
            curl_setopt($multiCurl[$server], CURLOPT_SSL_VERIFYPEER, false);
            // Thêm referrer
            curl_setopt($multiCurl[$server], CURLOPT_REFERER, 'https://mv.dailyphimz.com/');
            
            curl_multi_add_handle($mh, $multiCurl[$server]);
        }

        // Thực thi các requests
        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running);

        // Lấy kết quả và đóng các connections
        foreach ($servers as $server) {
            $response = curl_multi_getcontent($multiCurl[$server]);
            $result[$server] = json_decode($response, true);
            curl_multi_remove_handle($mh, $multiCurl[$server]);
        }

        curl_multi_close($mh);

        // Xử lý và gộp kết quả
        $allSources = [];
        foreach ($result as $server => $response) {
            // Bỏ qua nếu response rỗng hoặc không phải array
            if (empty($response) || !is_array($response)) {
                continue;
            }

            // Kiểm tra và xử lý sources
            if (isset($response['sources'])) {
                if (is_string($response['sources'])) {
                    $sources = json_decode($response['sources'], true);
                    if (!empty($sources)) {
                        $allSources[$server] = $sources;
                    }
                } elseif (is_array($response['sources']) && !empty($response['sources'])) {
                    $allSources[$server] = $response['sources'];
                }
            }
        }

        return $allSources;
    }
    public function tikphoSerie($tmdbid, $season, $episode)
    {
        // Lấy ID từ embed URL
        $get_embed = Http::get("https://play.123embed.net/tv/{$tmdbid}-S{$season}/{$episode}");
        preg_match_all('/loadSerieEpisode\(\'([^\']+)\', \'([^\']+)\', \'([^\']+)\'/m', $get_embed, $matches, PREG_SET_ORDER, 0);
        $id = $matches[0][1];

        // Danh sách các servers cần gọi
        $servers = ['tikpho', 'gd-hls', 'hls-v', 'hls-s', 'hls-f', 'ytstream'];
        
        // Khởi tạo multi curl
        $multiCurl = [];
        $result = [];
        $mh = curl_multi_init();

        // Tạo các curl requests
        foreach ($servers as $server) {
            $url = "https://play.123embed.net/ajax/serie/get_sources/{$id}/{$episode}/{$server}";
            
            $multiCurl[$server] = curl_init();
            curl_setopt($multiCurl[$server], CURLOPT_URL, $url);
            curl_setopt($multiCurl[$server], CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($multiCurl[$server], CURLOPT_HEADER, 0);
            curl_setopt($multiCurl[$server], CURLOPT_SSL_VERIFYPEER, false);
            // Thêm referrer
            curl_setopt($multiCurl[$server], CURLOPT_REFERER, 'https://mv.dailyphimz.com/');
            
            curl_multi_add_handle($mh, $multiCurl[$server]);
        }

        // Thực thi các requests
        $running = null;
        do {
            curl_multi_exec($mh, $running);
        } while ($running);

        // Lấy kết quả và đóng các connections
        foreach ($servers as $server) {
            $response = curl_multi_getcontent($multiCurl[$server]);
            $result[$server] = json_decode($response, true);
            curl_multi_remove_handle($mh, $multiCurl[$server]);
        }

        curl_multi_close($mh);

        // Xử lý và gộp kết quả
        $allSources = [];
        foreach ($result as $server => $response) {
            // Bỏ qua nếu response rỗng hoặc không phải array
            if (empty($response) || !is_array($response)) {
                continue;
            }

            // Kiểm tra và xử lý sources
            if (isset($response['sources'])) {
                if (is_string($response['sources'])) {
                    $sources = json_decode($response['sources'], true);
                    if (!empty($sources)) {
                        $allSources[$server] = $sources;
                    }
                } elseif (is_array($response['sources']) && !empty($response['sources'])) {
                    $allSources[$server] = $response['sources'];
                }
            }
        }
        return $allSources;
    }
}
