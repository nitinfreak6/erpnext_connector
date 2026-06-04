@extends('dashboard.layout')
@section('page-title', isset($alert) ? 'Edit Alert' : 'Add New Alert')

@section('content')

<div class="max-w-3xl mx-auto">

    {{-- ── Header ── --}}
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
            🔔 Alerts &amp; Notifications
        </h1>
        <a href="{{ route('dashboard.alerts.index') }}"
           class="text-gray-500 hover:text-gray-700 text-2xl leading-none" title="Close">✕</a>
    </div>

    {{-- ── Form Card ── --}}
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
        <form method="POST"
              action="{{ isset($alert) ? route('dashboard.alerts.update', $alert) : route('dashboard.alerts.store') }}">
            @csrf
            @if(isset($alert)) @method('PUT') @endif

            {{-- Alert Type --}}
            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Alert Type <span class="text-red-500">*</span>
                </label>
                <select name="alert_type"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                    @foreach($typeLabels as $value => $label)
                        <option value="{{ $value }}"
                            {{ old('alert_type', $alert->alert_type ?? '') === $value ? 'selected' : '' }}>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
                @error('alert_type')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Status --}}
            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Alert Type <span class="text-red-500">*</span>
                </label>
                <select name="status"
                        class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                    <option value="active"   {{ old('status', $alert->status ?? 'active') === 'active'   ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ old('status', $alert->status ?? 'active') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                </select>
                @error('status')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Sent Mail To --}}
            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Sent Mail To <span class="text-red-500">*</span>
                </label>
                <input type="text" name="send_to"
                       value="{{ old('send_to', $alert->send_to ?? '') }}"
                       placeholder="email@example.com"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <p class="text-gray-400 text-xs mt-1">Separate multiple emails with commas.</p>
                @error('send_to')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- CC --}}
            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 mb-1">CC</label>
                <input type="text" name="cc"
                       value="{{ old('cc', $alert->cc ?? '') }}"
                       placeholder="cc@example.com"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            {{-- BCC --}}
            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 mb-1">BCC</label>
                <input type="text" name="bcc"
                       value="{{ old('bcc', $alert->bcc ?? '') }}"
                       placeholder="bcc@example.com"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
            </div>

            {{-- Subject --}}
            <div class="mb-5">
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Subjects <span class="text-red-500">*</span>
                </label>
                <input type="text" name="subject"
                       value="{{ old('subject', $alert->subject ?? '') }}"
                       class="w-full border border-gray-200 rounded-lg px-3 py-2.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                @error('subject')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Details / Body --}}
            <div class="mb-6">
                <label class="block text-sm font-semibold text-gray-700 mb-1">
                    Details <span class="text-red-500">*</span>
                </label>
                <p class="text-gray-400 text-xs mb-2">
                    Use <code class="bg-gray-100 px-1 rounded">{body}</code> as a placeholder — it will be replaced with the table of pending items when the email is sent.
                </p>

                {{-- Toolbar --}}
                <div class="border border-gray-200 rounded-t-lg bg-gray-50 px-3 py-2 flex flex-wrap gap-1 text-sm">
                    <button type="button" onclick="fmt('bold')"          class="toolbar-btn font-bold">B</button>
                    <button type="button" onclick="fmt('italic')"        class="toolbar-btn italic">I</button>
                    <button type="button" onclick="fmt('strikeThrough')" class="toolbar-btn line-through">S</button>
                    <span class="text-gray-300 mx-1">|</span>
                    <button type="button" onclick="fmt('insertUnorderedList')" class="toolbar-btn">• List</button>
                    <button type="button" onclick="fmt('insertOrderedList')"   class="toolbar-btn">1. List</button>
                    <span class="text-gray-300 mx-1">|</span>
                    <button type="button" onclick="insertPlaceholder()" class="toolbar-btn text-indigo-600 font-medium">{body}</button>
                    <span class="text-gray-300 mx-1">|</span>
                    <button type="button" onclick="toggleSource()" class="toolbar-btn text-xs">Source</button>
                </div>

                {{-- WYSIWYG area --}}
                <div id="editor"
                     contenteditable="true"
                     class="border border-t-0 border-gray-200 rounded-b-lg p-4 min-h-48 text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-300"
                     style="line-height:1.7;">
                </div>

                {{-- Hidden textarea for form submit --}}
                <textarea name="body" id="body-hidden" class="hidden">{{ old('body', $alert->body ?? '') }}</textarea>

                @error('body')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
            </div>

            {{-- Actions --}}
            <div class="flex gap-3 justify-end">
                <a href="{{ route('dashboard.alerts.index') }}"
                   class="px-5 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition">
                    Cancel
                </a>
                <button type="submit"
                        class="px-5 py-2.5 bg-indigo-700 hover:bg-indigo-800 text-white rounded-lg text-sm font-medium transition shadow-sm">
                    {{ isset($alert) ? 'Update Alert' : 'Create Alert' }}
                </button>
            </div>

        </form>
    </div>
</div>

<style>
.toolbar-btn {
    padding: 2px 8px;
    border: 1px solid #e5e7eb;
    border-radius: 4px;
    background: white;
    cursor: pointer;
    font-size: 13px;
    color: #374151;
    transition: background 0.1s;
}
.toolbar-btn:hover { background: #f3f4f6; }
</style>

<script>
// Initialise editor with saved body
const editor       = document.getElementById('editor');
const bodyHidden   = document.getElementById('body-hidden');
let   sourceMode   = false;

editor.innerHTML = bodyHidden.value;

function fmt(cmd) {
    if (sourceMode) return;
    document.execCommand(cmd, false, null);
    editor.focus();
    syncBody();
}

function insertPlaceholder() {
    if (sourceMode) return;
    document.execCommand('insertText', false, '{body}');
    editor.focus();
    syncBody();
}

function toggleSource() {
    if (!sourceMode) {
        // Switch to source
        const pre = document.createElement('pre');
        pre.id        = 'source-view';
        pre.contentEditable = true;
        pre.style.cssText   = 'white-space:pre-wrap;font-family:monospace;font-size:12px;padding:1rem;min-height:12rem;outline:none;';
        pre.textContent     = editor.innerHTML;
        editor.replaceWith(pre);
        sourceMode = true;
    } else {
        // Switch back to WYSIWYG
        const pre = document.getElementById('source-view');
        editor.innerHTML = pre.textContent;
        pre.replaceWith(editor);
        sourceMode = false;
    }
    syncBody();
}

function syncBody() {
    if (sourceMode) {
        const src = document.getElementById('source-view');
        bodyHidden.value = src ? src.textContent : '';
    } else {
        bodyHidden.value = editor.innerHTML;
    }
}

// Sync before submit
document.querySelector('form').addEventListener('submit', syncBody);
editor.addEventListener('input', syncBody);
</script>

@endsection