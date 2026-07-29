@extends('layouts.app')

@section('title', 'Gmail Failed Messages')

@section('content')
  <div class="mb-4">
      <h1 class="h3 mb-1">Gmail Failed Messages</h1>
      <p class="text-muted mb-0">Message fetch failures that were skipped so mailbox sync could continue.</p>
  </div>

  <div class="mb-3">
      <a href="{{ route('admin.administration.index') }}#administration-api-health" class="btn btn-sm btn-outline-secondary">Back to API Health</a>
  </div>

  <div class="card border-0 shadow-sm">
      <div class="card-body p-0">
          <div class="table-responsive">
              <table class="table table-sm mb-0">
                  <thead>
                      <tr>
                          <th>When</th>
                          <th>Mailbox</th>
                          <th>Message ID</th>
                          <th>HTTP</th>
                          <th>Attempts</th>
                          <th>Error</th>
                      </tr>
                  </thead>
                  <tbody>
                      @forelse($failures as $failure)
                          <tr>
                              <td class="text-nowrap">{{ display_app_datetime($failure->created_at) }}</td>
                              <td>{{ $failure->mailbox }}</td>
                              <td><code>{{ $failure->message_id }}</code></td>
                              <td>{{ $failure->http_status ?? '—' }}</td>
                              <td>{{ $failure->attempt_count }}</td>
                              <td class="small">
                                  @if(is_array($failure->error_payload))
                                      {{ $failure->error_payload['message'] ?? json_encode($failure->error_payload) }}
                                  @else
                                      —
                                  @endif
                              </td>
                          </tr>
                      @empty
                          <tr>
                              <td colspan="6" class="text-muted p-3">No failed message fetches recorded.</td>
                          </tr>
                      @endforelse
                  </tbody>
              </table>
          </div>
      </div>
  </div>
@endsection
