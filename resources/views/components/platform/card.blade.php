@props([
    'card',
])

@php
    /** @var \App\Data\Platform\PlatformCardPayload $card */
    use App\Enums\PlatformHealthStatus;

    $refreshUrl = route('admin.platform.cards.show', ['card' => $card->key]);
    $isExecutive = $card->section === 'executive';
    $isLaunchpad = str_starts_with($card->key, 'placeholder_');
    $isHealth = $card->key === 'platform_health';

    $statusTone = match ($card->status) {
        PlatformHealthStatus::Healthy => 'success',
        PlatformHealthStatus::Warning => 'warning',
        PlatformHealthStatus::Critical => 'danger',
        PlatformHealthStatus::Disabled => 'neutral',
    };

    $showStatusPill = ! $isExecutive && ($isLaunchpad || ($isHealth && $card->status !== PlatformHealthStatus::Healthy));
    $showDetailFooter = filled($card->detailUrl) && ! $isExecutive && ! $isLaunchpad && ! $isHealth;
@endphp

<article
    @class([
        'settings-center-card settings-center-platform-card h-100',
        'settings-center-platform-card--executive' => $isExecutive,
        'settings-center-platform-card--launchpad' => $isLaunchpad,
        'settings-center-platform-card--health' => $isHealth,
    ])
    data-platform-card
    data-card-key="{{ $card->key }}"
    @if($card->refreshable)
        data-refresh-url="{{ $refreshUrl }}"
    @endif
>
  @if($isExecutive)
      <div class="settings-center-platform-card__body settings-center-platform-card__body--executive">
          @include($card->bodyPartial, ['card' => $card])
          @if($card->refreshable)
              <button type="button"
                      class="btn btn-sm btn-link settings-center-platform-card__refresh settings-center-platform-card__refresh--corner"
                      data-platform-card-refresh
                      title="Refresh {{ $card->title }}"
                      aria-label="Refresh {{ $card->title }}">
                  <x-settings-center.icon name="refresh-cw" class="settings-center-icon settings-center-icon--sm" />
              </button>
          @endif
      </div>
  @else
      <header class="settings-center-card__header settings-center-platform-card__header">
          <div class="settings-center-card__heading">
              @if(filled($card->icon))
                  <span class="settings-center-card__icon" aria-hidden="true">
                      <i class="bi {{ $card->icon }}"></i>
                  </span>
              @endif
              <div class="min-w-0">
                  <h3 class="settings-center-card__title">{{ $card->title }}</h3>
              </div>
          </div>

          <div class="settings-center-platform-card__actions">
              @if($showStatusPill)
                  <x-settings-center.status-pill :label="$card->statusLabel()" :tone="$statusTone" size="sm" />
              @endif

              @if($card->refreshable)
                  <button type="button"
                          class="btn btn-sm btn-outline-secondary settings-center-platform-card__refresh"
                          data-platform-card-refresh
                          title="Refresh card"
                          aria-label="Refresh {{ $card->title }}">
                      <x-settings-center.icon name="refresh-cw" class="settings-center-icon settings-center-icon--sm" />
                  </button>
              @endif

              @if($card->actions !== [] && ! $isLaunchpad)
                  <div class="dropdown settings-center-table-actions">
                      <button type="button"
                              class="btn btn-sm btn-outline-secondary settings-center-table-actions__trigger"
                              data-bs-toggle="dropdown"
                              aria-expanded="false"
                              aria-label="More actions for {{ $card->title }}">
                          <x-settings-center.icon name="more-vertical" class="settings-center-icon settings-center-icon--sm" />
                      </button>
                      <ul class="dropdown-menu dropdown-menu-end">
                          @foreach($card->actions as $action)
                              <li>
                                  <a class="dropdown-item" href="{{ $action['url'] ?? '#' }}">
                                      {{ $action['label'] ?? 'Action' }}
                                  </a>
                              </li>
                          @endforeach
                      </ul>
                  </div>
              @endif
          </div>
      </header>

      <div class="settings-center-card__body settings-center-platform-card__body">
          @if(filled($card->bodyPartial))
              @include($card->bodyPartial, ['card' => $card])
          @else
              <div class="settings-center-platform-metrics">
                  @foreach($card->metrics as $metric)
                      <x-platform.metric-row :metric="$metric" />
                  @endforeach
              </div>
          @endif
      </div>

      @if($showDetailFooter)
          <footer class="settings-center-card__footer settings-center-card__footer--inline">
              <a href="{{ $card->detailUrl }}" class="settings-center-platform-card__detail-link">
                  View details
                  <x-settings-center.icon name="external-link" class="settings-center-icon settings-center-icon--sm" />
              </a>
          </footer>
      @endif
  @endif
</article>
