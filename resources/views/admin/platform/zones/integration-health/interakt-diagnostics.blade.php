@props([
    'card' => [],
    'templates' => [],
    'template_statuses' => [],
])

<section aria-labelledby="interakt-diagnostics-heading" data-interakt-diagnostics>
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h2 id="interakt-diagnostics-heading" class="h5 mb-0">Interakt</h2>
        <span class="badge text-bg-{{ $card['badge_class'] ?? 'secondary' }}">
            {{ $card['status_label'] ?? 'Unknown' }}
        </span>
    </div>

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body">
            <p class="text-muted small mb-0">{{ $card['summary'] ?? ($card['detail'] ?? 'WhatsApp messaging via Interakt.') }}</p>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h3 class="h6 mb-2">Templates</h3>
            <p class="text-muted small mb-3">
                {{ $templates['detail'] ?? sprintf(
                    '%d / %d templates configured',
                    (int) ($templates['configured_count'] ?? 0),
                    (int) ($templates['total_count'] ?? 0),
                ) }}
            </p>

            @if(! empty($templates['errors']) && is_array($templates['errors']))
                <ul class="small text-danger mb-2">
                    @foreach($templates['errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            @if(! empty($templates['warnings']) && is_array($templates['warnings']))
                <ul class="small text-warning mb-2">
                    @foreach($templates['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            @endif

            @if($template_statuses !== [])
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Template</th>
                                <th scope="col">Name</th>
                                <th scope="col">Language</th>
                                <th scope="col">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($template_statuses as $templateKey => $status)
                                <tr>
                                    <td><code>{{ $templateKey }}</code></td>
                                    <td>{{ $status->templateName ?? '—' }}</td>
                                    <td>{{ $status->languageCode ?? '—' }}</td>
                                    <td>
                                        @if(! ($status->valid ?? false))
                                            <span class="badge text-bg-danger">Invalid</span>
                                        @elseif(! empty($status->warning))
                                            <span class="badge text-bg-warning">Warning</span>
                                        @else
                                            <span class="badge text-bg-success">OK</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</section>
