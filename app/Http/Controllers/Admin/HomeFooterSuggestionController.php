<?php

namespace App\Http\Controllers\Admin;

use App\CentralLogics\Helpers;
use App\Http\Controllers\Controller;
use App\Models\HomeFooterSuggestion;
use App\Models\Store;
use App\Models\Zone;
use Brian2694\Toastr\Facades\Toastr;
use Illuminate\Http\Request;

class HomeFooterSuggestionController extends Controller
{
    public function index(Request $request)
    {
        if (!Helpers::module_permission_check('settings')) {
            Toastr::error(translate('messages.access_denied'));
            return back();
        }

        $zoneId = $request->input('zone_id');
        $language = getWebConfig('language');

        $suggestions = HomeFooterSuggestion::with(['store', 'zone', 'module'])
            ->when($zoneId, fn ($query) => $query->where('zone_id', $zoneId))
            ->latest()
            ->paginate(config('default_pagination'));

        $zones = Zone::all();
        $stores = $zoneId
            ? Store::where('zone_id', $zoneId)->where('status', 1)->get()
            : collect();

        return view('admin-views.business-settings.settings.home-footer-index', compact(
            'suggestions', 'zones', 'stores', 'zoneId', 'language'
        ));
    }

    public function store(Request $request)
    {
        if (!Helpers::module_permission_check('settings')) {
            Toastr::error(translate('messages.access_denied'));
            return back();
        }

        $request->validate([
            'zone_id' => 'required|exists:zones,id',
            'type' => 'required|in:store,promotion_hub',
            'store_id' => 'required_if:type,store|nullable|exists:stores,id',
            'title' => 'required|string|max:255',
            'icon' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $activeCount = HomeFooterSuggestion::where('zone_id', $request->zone_id)
            ->where('status', 1)
            ->count();

        if ($activeCount >= 4 && $request->status == 1) {
            Toastr::warning(translate('messages.maximum_4_active_suggestions_allowed_per_zone'));
            return back();
        }

        $icon = Helpers::upload('home_footer_suggestion/', 'png', $request->file('icon'));

        HomeFooterSuggestion::create([
            'type' => $request->type,
            'store_id' => $request->type == 'store' ? $request->store_id : null,
            'zone_id' => $request->zone_id,
            'module_id' => $request->module_id,
            'title' => $request->title,
            'icon' => $icon,
            'sort_order' => $request->sort_order ?? 0,
            'status' => $request->status ?? 1,
        ]);

        Toastr::success(translate('messages.home_footer_suggestion_added_successfully'));
        return back();
    }

    public function update(Request $request, $id)
    {
        if (!Helpers::module_permission_check('settings')) {
            Toastr::error(translate('messages.access_denied'));
            return back();
        }

        $request->validate([
            'zone_id' => 'required|exists:zones,id',
            'type' => 'required|in:store,promotion_hub',
            'store_id' => 'required_if:type,store|nullable|exists:stores,id',
            'title' => 'required|string|max:255',
            'icon' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:2048',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $suggestion = HomeFooterSuggestion::findOrFail($id);

        $activeCount = HomeFooterSuggestion::where('zone_id', $request->zone_id)
            ->where('status', 1)
            ->where('id', '!=', $id)
            ->count();

        if ($activeCount >= 4 && ($request->status ?? $suggestion->status) == 1) {
            Toastr::warning(translate('messages.maximum_4_active_suggestions_allowed_per_zone'));
            return back();
        }

        if ($request->hasFile('icon')) {
            $suggestion->icon = Helpers::update('home_footer_suggestion/', $suggestion->icon, 'png', $request->file('icon'));
        }

        $suggestion->type = $request->type;
        $suggestion->store_id = $request->type == 'store' ? $request->store_id : null;
        $suggestion->zone_id = $request->zone_id;
        $suggestion->module_id = $request->module_id;
        $suggestion->title = $request->title;
        $suggestion->sort_order = $request->sort_order ?? 0;
        $suggestion->status = $request->status ?? $suggestion->status;
        $suggestion->save();

        Toastr::success(translate('messages.home_footer_suggestion_updated_successfully'));
        return back();
    }

    public function destroy($id)
    {
        if (!Helpers::module_permission_check('settings')) {
            Toastr::error(translate('messages.access_denied'));
            return back();
        }

        $suggestion = HomeFooterSuggestion::findOrFail($id);
        Helpers::check_and_delete('home_footer_suggestion/', $suggestion->icon);
        $suggestion->delete();

        Toastr::success(translate('messages.home_footer_suggestion_deleted_successfully'));
        return back();
    }

    public function status(Request $request)
    {
        if (!Helpers::module_permission_check('settings')) {
            Toastr::error(translate('messages.access_denied'));
            return back();
        }

        $suggestion = HomeFooterSuggestion::findOrFail($request->id);

        if (!$suggestion->status) {
            $activeCount = HomeFooterSuggestion::where('zone_id', $suggestion->zone_id)
                ->where('status', 1)
                ->count();

            if ($activeCount >= 4) {
                Toastr::warning(translate('messages.maximum_4_active_suggestions_allowed_per_zone'));
                return back();
            }
        }

        $suggestion->status = $request->status;
        $suggestion->save();

        Toastr::success(translate('messages.status_updated'));
        return back();
    }
}
