@php
    /** @var \App\Models\CommunicationTemplate|null $template */
    /** @var \App\Models\CommunicationTemplateVersion|null $version */
    $isEdit = isset($template);
    $selectedChannels = old('channels', $isEdit ? ($template->channels ?? []) : ['email']);
@endphp

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="name">Template Name</label>
                        <input type="text" name="name" id="name" class="form-control @error('name') is-invalid @enderror"
                               value="{{ old('name', $template->name ?? '') }}" required>
                        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @unless($isEdit)
                        <div class="col-md-4">
                            <label class="form-label" for="key">Key (optional)</label>
                            <input type="text" name="key" id="key" class="form-control @error('key') is-invalid @enderror"
                                   value="{{ old('key') }}" placeholder="auto-from-name">
                            @error('key')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endunless
                    <div class="col-md-6">
                        <label class="form-label" for="category">Category</label>
                        <select name="category" id="category" class="form-select @error('category') is-invalid @enderror" required>
                            @foreach($categories as $category)
                                <option value="{{ $category->value }}" @selected(old('category', $template->category->value ?? '') === $category->value)>
                                    {{ $category->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('category')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Channel(s)</label>
                        <div class="d-flex flex-wrap gap-3">
                            @foreach($channels as $channel)
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="channels[]" value="{{ $channel->value }}"
                                           id="channel_{{ $channel->value }}"
                                           @checked(in_array($channel->value, $selectedChannels, true))>
                                    <label class="form-check-label" for="channel_{{ $channel->value }}">
                                        {{ $channel->label() }}@if($channel->isFuture()) <span class="text-muted">(future)</span>@endif
                                    </label>
                                </div>
                            @endforeach
                        </div>
                        @error('channels')<div class="text-danger small">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="subject">Subject (Email)</label>
                        <input type="text" name="subject" id="subject" class="form-control"
                               value="{{ old('subject', $version->subject ?? '') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="greeting_style">Greeting Style</label>
                        <select name="greeting_style" id="greeting_style" class="form-select" required>
                            @foreach($greetings as $greeting)
                                <option value="{{ $greeting->value }}" @selected(old('greeting_style', $version->greeting_style->value ?? 'hello_customer') === $greeting->value)>
                                    {{ $greeting->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="signature_mode">Signature</label>
                        <select name="signature_mode" id="signature_mode" class="form-select" required>
                            @foreach($signatures as $signature)
                                <option value="{{ $signature->value }}" @selected(old('signature_mode', $version->signature_mode->value ?? 'company_default') === $signature->value)>
                                    {{ $signature->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="body_html">Body (HTML)</label>
                        <textarea name="body_html" id="body_html" rows="14" class="form-control font-monospace @error('body_html') is-invalid @enderror" required>{{ old('body_html', $version->body_html ?? '') }}</textarea>
                        <div class="form-text">Phase 1 HTML editor with live preview. Use variables like <code>{{'{{customer_name}}'}}</code>.</div>
                        @error('body_html')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    @if($isEdit)
                        <div class="col-12">
                            <label class="form-label" for="change_reason">Change reason</label>
                            <input type="text" name="change_reason" id="change_reason" class="form-control @error('change_reason') is-invalid @enderror"
                                   value="{{ old('change_reason') }}" required placeholder="Why is this version being saved?">
                            @error('change_reason')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Live preview</h2>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-outline-secondary active" data-preview-mode="desktop">Desktop</button>
                    <button type="button" class="btn btn-outline-secondary" data-preview-mode="mobile">Mobile</button>
                </div>
            </div>
            <div class="card-body">
                <div class="fw-semibold mb-2" data-preview-subject></div>
                <div class="border rounded p-3 bg-white" data-preview-frame style="max-width: 100%;">
                    <div data-preview-html class="small"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white"><h2 class="h6 mb-0">Variables</h2></div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    @foreach($variables as $variable)
                        <li class="mb-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary w-100 text-start"
                                    data-insert-variable="{{ $variable['key'] }}">
                                <code>{{'{{'.$variable['key'].'}}'}}</code>
                                <span class="d-block text-muted small">{{ $variable['label'] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
