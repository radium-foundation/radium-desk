@extends('layouts.app')

@section('title', 'Gmail Sync Logs')

@section('content')
  <div class="mb-4">
      <h1 class="h3 mb-1">Gmail Sync Logs</h1>
      <p class="text-muted mb-0"><code>{{ $path }}</code></p>
  </div>

  <div class="mb-3">
      <a href="{{ route('admin.platform.index') }}" class="btn btn-sm btn-outline-secondary">Back to Platform</a>
  </div>

  <div class="card border-0 shadow-sm">
      <div class="card-body">
          @if($lines === [])
              <p class="text-muted mb-0">No sync log output found.</p>
          @else
              <pre class="small mb-0" style="max-height: 70vh; overflow: auto;">{{ implode("\n", $lines) }}</pre>
          @endif
      </div>
  </div>
@endsection
