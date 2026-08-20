@extends('dashboard.layout')
@section('title', app(\App\Services\SettingsService::class)->appName() . ' — Settings')
@section('page-title', 'Global Settings')

@section('content')

@include('dashboard.settings._flash')
@include('dashboard.settings._styles')

<form method="POST" action="{{ route('dashboard.settings.update') }}" id="settings-form" autocomplete="off">
    @csrf
    @method('PUT')
    <input type="hidden" name="_settings_context" value="global">

    @php
        $sectionOrder = ['general', 'amazon'];
        $sectionConfig = [
            'general' => ['label' => 'Common Settings',  'gradient' => 'linear-gradient(135deg,#f97316,#ef4444)', 'open' => true],
            'amazon'  => ['label' => 'Amazon Settings',   'gradient' => 'linear-gradient(135deg,#d97706,#f59e0b)', 'open' => false],
        ];
    @endphp

    @foreach($sectionOrder as $groupKey)
        @php
            $settings = $groups[$groupKey] ?? collect();
            $cfg      = $sectionConfig[$groupKey];
            $domId    = 'section-' . $groupKey;
        @endphp

        <div class="settings-card">
            <div class="settings-header {{ $cfg['open'] ? 'open' : '' }}"
                 style="background:{{ $cfg['gradient'] }}"
                 onclick="toggleSection('{{ $domId }}', this)">
                <span class="icon">{{ $cfg['open'] ? '−' : '+' }}</span>
                {{ $cfg['label'] }}
                <svg class="chevron w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
            <div class="settings-divider"></div>
            <div id="{{ $domId }}" class="settings-body" style="{{ $cfg['open'] ? '' : 'display:none' }}">
                @if($settings->isEmpty())
                    <div class="px-6 py-6 text-center text-sm text-gray-400">No settings configured.</div>
                @else
                    @include('dashboard.settings._fields', ['settings' => $settings])
                @endif
            </div>
        </div>
    @endforeach

    @include('dashboard.settings._sync-directions')
    @include('dashboard.settings._sync-triggers')

    @include('dashboard.settings._save-bar')
</form>
@endsection
