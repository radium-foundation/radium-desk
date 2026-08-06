@props([
    'commercialState' => null,
    'incident' => null,
])

@php
    $state = is_array($commercialState) ? $commercialState : null;
    $show = (bool) ($state['show_banner'] ?? false);
    $canRestore = (bool) ($state['can_restore_commercial_service'] ?? false);
    $canRevoke = (bool) ($state['can_revoke_commercial_service_restoration'] ?? false);
    $restoreUrl = $state['restore_url'] ?? null;
    $revokeUrl = $state['revoke_url'] ?? null;
    $incidentId = $incident?->id;
    $modalId = 'c360-commercial-restore-'.$incidentId;
@endphp

@if($show && $state !== null)
    <section class="c360-commercial-state c360-commercial-state--{{ $state['banner_variant'] ?? 'muted' }}"
             data-customer-360-section="commercial-state"
             data-commercial-state="{{ $state['state'] ?? '' }}"
             data-commercial-restore-incident="{{ $incidentId }}"
             aria-label="Commercial state">
        <div class="c360-commercial-state-header">
            <span class="c360-commercial-state-kicker">Commercial State</span>
            <span class="c360-commercial-state-headline">{{ $state['headline'] ?? $state['label'] ?? '' }}</span>
        </div>
        @if(filled($state['summary'] ?? null))
            <p class="c360-commercial-state-summary">{{ $state['summary'] }}</p>
        @endif
        @if(! empty($state['details']))
            <dl class="c360-commercial-state-details">
                @foreach($state['details'] as $detail)
                    <div class="c360-commercial-state-detail">
                        <dt>{{ $detail['label'] ?? '' }}</dt>
                        <dd>{{ $detail['value'] ?? '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if($canRestore && filled($restoreUrl))
            <div class="c360-commercial-state-actions">
                <button type="button"
                        class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal"
                        data-bs-target="#{{ $modalId }}">
                    Restore Commercial Service
                </button>
            </div>

            <div class="modal fade"
                 id="{{ $modalId }}"
                 tabindex="-1"
                 aria-labelledby="{{ $modalId }}-title"
                 aria-hidden="true"
                 data-commercial-restore-modal>
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form method="post"
                              action="{{ $restoreUrl }}"
                              data-commercial-restore-form
                              data-incident-id="{{ $incidentId }}">
                            @csrf
                            <div class="modal-header">
                                <h2 class="modal-title fs-6" id="{{ $modalId }}-title">Restore Commercial Service</h2>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body">
                                <p class="small text-muted mb-3">
                                    Record Finance verification that the RD Wallet credit was reversed externally.
                                    This does not change the original refund record.
                                </p>
                                <div class="form-check mb-2">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           value="1"
                                           name="finance_verified"
                                           id="{{ $modalId }}-finance"
                                           required>
                                    <label class="form-check-label" for="{{ $modalId }}-finance">
                                        Finance Verified
                                    </label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           value="1"
                                           name="wallet_reversed_externally"
                                           id="{{ $modalId }}-wallet"
                                           required>
                                    <label class="form-check-label" for="{{ $modalId }}-wallet">
                                        Wallet Reversed Externally
                                    </label>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="{{ $modalId }}-ref">Wallet Reference</label>
                                    <input type="text"
                                           class="form-control form-control-sm"
                                           name="wallet_reversal_reference"
                                           id="{{ $modalId }}-ref"
                                           maxlength="255"
                                           required
                                           placeholder="e.g. RD273105 reverse txn">
                                </div>
                                <div class="mb-0">
                                    <label class="form-label" for="{{ $modalId }}-note">Finance Note</label>
                                    <textarea class="form-control form-control-sm"
                                              name="finance_note"
                                              id="{{ $modalId }}-note"
                                              rows="2"
                                              maxlength="2000"
                                              placeholder="Optional evidence / note"></textarea>
                                </div>
                                <div class="invalid-feedback d-block small mt-2" data-commercial-restore-error hidden></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                <button type="submit" class="btn btn-sm btn-danger" data-commercial-restore-submit>
                                    Confirm Restore
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        @endif

        @if($canRevoke && filled($revokeUrl))
            <div class="c360-commercial-state-actions">
                <button type="button"
                        class="btn btn-sm btn-outline-secondary"
                        data-commercial-revoke-url="{{ $revokeUrl }}"
                        data-incident-id="{{ $incidentId }}"
                        data-commercial-revoke-button>
                    Revoke Restoration
                </button>
            </div>
        @endif
    </section>
@endif
