@extends('dashboard.layout')
@section('title', app(\App\Services\SettingsService::class)->appName() . ' — ' . $ecomDisplayName . ' Settings')
@section('page-title', $ecomDisplayName . ' Settings')

@section('content')

@include('dashboard.settings._flash')
@include('dashboard.settings._styles')

<form method="POST" action="{{ route('dashboard.settings.ecom.update') }}" autocomplete="off">
    @csrf
    @method('PUT')
    <input type="hidden" name="_settings_context" value="ecom">

    <div class="settings-card">
        <div class="settings-header open static" style="background:linear-gradient(135deg,#059669,#10b981)">
            <span class="icon">−</span>
            {{ $ecomDisplayName }} Settings
        </div>
        <div class="settings-divider"></div>
        <div class="settings-body">
            @if($settings->isEmpty())
                <div class="px-6 py-6 text-center text-sm text-gray-400">No {{ $ecomDisplayName }} settings configured.</div>
            @else
                @include('dashboard.settings._fields', ['settings' => $settings])
            @endif
        </div>
    </div>

    @include('dashboard.settings._save-bar')
</form>
@endsection
