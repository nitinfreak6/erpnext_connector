@extends('dashboard.layout')
@section('title', app(\App\Services\SettingsService::class)->appName() . ' — ' . $erpDisplayName . ' Settings')
@section('page-title', $erpDisplayName . ' Settings')

@section('content')

@include('dashboard.settings._flash')
@include('dashboard.settings._styles')

<form method="POST" action="{{ route('dashboard.settings.erp.update') }}" autocomplete="off">
    @csrf
    @method('PUT')
    <input type="hidden" name="_settings_context" value="erp">

    <div class="settings-card">
        <div class="settings-header open static" style="background:linear-gradient(135deg,#7c3aed,#6366f1)">
            <span class="icon">−</span>
            {{ $erpDisplayName }} Settings
        </div>
        <div class="settings-divider"></div>
        <div class="settings-body">
            @if(!empty($driverError))
                <div class="px-6 py-4 text-sm text-red-600 bg-red-50 border-b border-red-100">
                    {{ $driverError }}
                </div>
            @elseif($settings->isEmpty())
                <div class="px-6 py-6 text-center text-sm text-gray-400">
                    No connection settings for driver <strong>{{ $driverGroup ?? 'erp' }}</strong>.
                    Check <strong>ERP Driver</strong> on Global Settings matches a registered adapter.
                </div>
            @else
                @include('dashboard.settings._fields', ['settings' => $settings])
            @endif
        </div>
    </div>

    @include('dashboard.settings._save-bar')
</form>
@endsection
