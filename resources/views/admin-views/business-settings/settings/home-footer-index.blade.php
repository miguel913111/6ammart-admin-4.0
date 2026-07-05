@extends('layouts.admin.app')

@section('title', translate('Home_Footer'))

@php($selectedZoneId = old('zone_id', $zoneId))
@php($selectedStores = $selectedZoneId ? \App\Models\Store::where('zone_id', $selectedZoneId)->where('status', 1)->get() : collect())

@section('content')
    <div class="content container-fluid">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-header-title mr-3">
                <span class="page-header-icon">
                    <img src="{{ asset('public/assets/admin/img/business.png') }}" class="w--26" alt="">
                </span>
                <span>
                    {{ translate('messages.business_setup') }}
                </span>
            </h1>
            @include('admin-views.business-settings.partials.nav-menu')
        </div>
        <!-- End Page Header -->

        <div class="card mb-20">
            <div class="card-body">
                <div class="mb-0">
                    <h3 class="mb-1">
                        {{ translate('Home Footer Suggestions') }}
                    </h3>
                    <p class="mb-0 fs-12">
                        {{ translate('Manage the shortcut icons displayed below the modules on the user app home screen. Maximum 4 active items per zone.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="card mb-20">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ translate('Add New Suggestion') }}</h5>
            </div>
            <div class="card-body">
                <form action="{{ route('admin.business-settings.home_footer.store') }}" method="post" enctype="multipart/form-data">
                    @csrf
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ translate('Zone') }} <span class="text-danger">*</span></label>
                            <select name="zone_id" class="form-control zone-select" required>
                                <option value="">{{ translate('Select Zone') }}</option>
                                @foreach ($zones as $zone)
                                    <option value="{{ $zone->id }}" {{ $selectedZoneId == $zone->id ? 'selected' : '' }}>{{ $zone->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ translate('Type') }} <span class="text-danger">*</span></label>
                            <select name="type" class="form-control type-select" required>
                                <option value="store" {{ old('type') == 'store' ? 'selected' : '' }}>{{ translate('Restaurant/Store') }}</option>
                                <option value="promotion_hub" {{ old('type') == 'promotion_hub' ? 'selected' : '' }}>{{ translate('Promotions Hub') }}</option>
                            </select>
                        </div>
                        <div class="col-md-4 store-field" style="{{ old('type') == 'promotion_hub' ? 'display:none;' : '' }}">
                            <label class="form-label">{{ translate('Store') }} <span class="text-danger">*</span></label>
                            <select name="store_id" class="form-control store-select" {{ old('type') != 'promotion_hub' ? 'required' : '' }}>
                                <option value="">{{ translate('Select Store') }}</option>
                                @foreach ($selectedStores as $store)
                                    <option value="{{ $store->id }}" {{ old('store_id') == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ translate('Title') }} <span class="text-danger">*</span></label>
                            <input type="text" name="title" class="form-control" placeholder="{{ translate('Ex: Promotions') }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">{{ translate('Icon') }} <span class="text-danger">*</span></label>
                            <input type="file" name="icon" class="form-control" accept="image/*" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ translate('Sort Order') }}</label>
                            <input type="number" name="sort_order" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">{{ translate('Status') }}</label>
                            <select name="status" class="form-control">
                                <option value="1">{{ translate('Active') }}</option>
                                <option value="0">{{ translate('Inactive') }}</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 btn--container justify-content-end">
                        <button type="reset" class="btn btn--reset">{{ translate('messages.reset') }}</button>
                        <button type="submit" class="btn btn--primary">{{ translate('messages.Save') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">{{ translate('Suggestion List') }}</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive datatable-custom">
                    <table class="table table-borderless table-thead-bordered table-align-middle">
                        <thead class="thead-light">
                            <tr>
                                <th class="border-0">{{ translate('messages.SL') }}</th>
                                <th class="border-0">{{ translate('Icon') }}</th>
                                <th class="border-0">{{ translate('Title') }}</th>
                                <th class="border-0">{{ translate('Type') }}</th>
                                <th class="border-0">{{ translate('Store') }}</th>
                                <th class="border-0">{{ translate('Zone') }}</th>
                                <th class="border-0">{{ translate('Order') }}</th>
                                <th class="border-0">{{ translate('messages.status') }}</th>
                                <th class="border-0 text-center">{{ translate('messages.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($suggestions as $key => $suggestion)
                                <tr>
                                    <td class="fs-14 text-dark">{{ $key + $suggestions->firstItem() }}</td>
                                    <td>
                                        <img src="{{ asset('storage/app/public/home_footer_suggestion/' . $suggestion->icon) }}" height="40" width="40" class="rounded" alt="">
                                    </td>
                                    <td class="fs-14 text-dark">{{ $suggestion->title }}</td>
                                    <td class="fs-14 text-dark">{{ ucfirst(str_replace('_', ' ', $suggestion->type)) }}</td>
                                    <td class="fs-14 text-dark">{{ $suggestion->store?->name ?? '-' }}</td>
                                    <td class="fs-14 text-dark">{{ $suggestion->zone?->name ?? '-' }}</td>
                                    <td class="fs-14 text-dark">{{ $suggestion->sort_order }}</td>
                                    <td>
                                        <label class="toggle-switch toggle-switch-sm">
                                            <input type="checkbox" class="toggle-switch-input redirect-url"
                                                data-url="{{ route('admin.business-settings.home_footer.status', ['id' => $suggestion->id, 'status' => $suggestion->status ? 0 : 1]) }}"
                                                {{ $suggestion->status ? 'checked' : '' }}>
                                            <span class="toggle-switch-label">
                                                <span class="toggle-switch-indicator"></span>
                                            </span>
                                        </label>
                                    </td>
                                    <td>
                                        <div class="btn--container justify-content-center">
                                            <a href="javascript:" class="btn btn-sm action-btn info--outline text--info info-hover" data-toggle="modal" data-target="#editModal{{ $suggestion->id }}" title="{{ translate('messages.edit') }}">
                                                <i class="tio-edit"></i>
                                            </a>
                                            <a href="{{ route('admin.business-settings.home_footer.destroy', $suggestion->id) }}" class="btn btn-sm action-btn btn--danger btn-outline-danger" onclick="return confirm('{{ translate('messages.are_you_sure') }}')" title="{{ translate('messages.delete') }}">
                                                <i class="tio-delete-outlined"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="text-center py-4">{{ translate('No suggestions found') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="card-footer">
                    {!! $suggestions->links() !!}
                </div>
            </div>
        </div>
    </div>

    @foreach ($suggestions as $suggestion)
        <div class="modal fade" id="editModal{{ $suggestion->id }}" tabindex="-1" role="dialog" aria-hidden="true">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ translate('Edit Suggestion') }}</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <form action="{{ route('admin.business-settings.home_footer.update', $suggestion->id) }}" method="post" enctype="multipart/form-data">
                        @csrf
                        @method('PUT')
                        <div class="modal-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">{{ translate('Zone') }} <span class="text-danger">*</span></label>
                                    <select name="zone_id" class="form-control" required>
                                        @foreach ($zones as $zone)
                                            <option value="{{ $zone->id }}" {{ $suggestion->zone_id == $zone->id ? 'selected' : '' }}>{{ $zone->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ translate('Type') }} <span class="text-danger">*</span></label>
                                    <select name="type" class="form-control edit-type-select" data-id="{{ $suggestion->id }}" required>
                                        <option value="store" {{ $suggestion->type == 'store' ? 'selected' : '' }}>{{ translate('Restaurant/Store') }}</option>
                                        <option value="promotion_hub" {{ $suggestion->type == 'promotion_hub' ? 'selected' : '' }}>{{ translate('Promotions Hub') }}</option>
                                    </select>
                                </div>
                                <div class="col-md-6 edit-store-field{{ $suggestion->id }}" style="{{ $suggestion->type == 'promotion_hub' ? 'display:none;' : '' }}">
                                    <label class="form-label">{{ translate('Store') }} <span class="text-danger">*</span></label>
                                    <select name="store_id" class="form-control">
                                        <option value="">{{ translate('Select Store') }}</option>
                                        @foreach (App\Models\Store::where('zone_id', $suggestion->zone_id)->where('status', 1)->get() as $store)
                                            <option value="{{ $store->id }}" {{ $suggestion->store_id == $store->id ? 'selected' : '' }}>{{ $store->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ translate('Title') }} <span class="text-danger">*</span></label>
                                    <input type="text" name="title" class="form-control" value="{{ $suggestion->title }}" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">{{ translate('Icon') }}</label>
                                    <input type="file" name="icon" class="form-control" accept="image/*">
                                    @if ($suggestion->icon)
                                        <img src="{{ asset('storage/app/public/home_footer_suggestion/' . $suggestion->icon) }}" height="40" width="40" class="rounded mt-2" alt="">
                                    @endif
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ translate('Sort Order') }}</label>
                                    <input type="number" name="sort_order" class="form-control" value="{{ $suggestion->sort_order }}" min="0">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">{{ translate('Status') }}</label>
                                    <select name="status" class="form-control">
                                        <option value="1" {{ $suggestion->status ? 'selected' : '' }}>{{ translate('Active') }}</option>
                                        <option value="0" {{ !$suggestion->status ? 'selected' : '' }}>{{ translate('Inactive') }}</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn--reset" data-dismiss="modal">{{ translate('messages.close') }}</button>
                            <button type="submit" class="btn btn--primary">{{ translate('messages.update') }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endsection

@push('script_2')
    <script>
        $(document).on('change', '.type-select', function() {
            if ($(this).val() === 'promotion_hub') {
                $('.store-field').hide();
                $('.store-field select').prop('required', false);
            } else {
                $('.store-field').show();
                $('.store-field select').prop('required', true);
            }
        });

        $(document).on('change', '.edit-type-select', function() {
            const id = $(this).data('id');
            if ($(this).val() === 'promotion_hub') {
                $('.edit-store-field' + id).hide();
                $('.edit-store-field' + id + ' select').prop('required', false);
            } else {
                $('.edit-store-field' + id).show();
                $('.edit-store-field' + id + ' select').prop('required', true);
            }
        });

        $(document).on('change', '.zone-select', function() {
            const zoneId = $(this).val();
            if (zoneId) {
                const url = new URL(window.location.href);
                url.searchParams.set('zone_id', zoneId);
                window.location.href = url.toString();
            }
        });
    </script>
@endpush
