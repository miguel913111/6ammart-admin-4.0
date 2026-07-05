<?php

namespace App\Http\Controllers\Api\V1;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\HomeFooterSuggestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomeFooterSuggestionController extends Controller
{
    public function getSuggestions(Request $request)
    {
        Helpers::setZoneIds($request);
        $zoneId = $request->header('zoneId');

        if (!$zoneId) {
            return response()->json([], 200);
        }

        $zoneIds = json_decode($zoneId, true) ?? [$zoneId];

        $cacheKey = 'home_footer_suggestions_' . md5(implode('_', $zoneIds));

        $suggestions = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($zoneIds) {
            return HomeFooterSuggestion::with(['store' => function ($query) {
                    $query->select('id', 'name', 'logo', 'slug', 'zone_id', 'module_id');
                }])
                ->where('status', 1)
                ->whereIn('zone_id', $zoneIds)
                ->orderBy('sort_order', 'asc')
                ->orderBy('created_at', 'desc')
                ->get();
        });

        $data = $suggestions->map(function ($item) {
            return [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'icon' => $item->icon,
                'image_full_url' => Helpers::get_full_url('home_footer_suggestion', $item->icon, 'public'),
                'sort_order' => $item->sort_order,
                'store' => $item->type == 'store' && $item->store ? [
                    'id' => $item->store->id,
                    'name' => $item->store->name,
                    'logo_full_url' => $item->store->logo_full_url,
                    'slug' => $item->store->slug,
                ] : null,
            ];
        });

        return response()->json($data, 200);
    }
}
