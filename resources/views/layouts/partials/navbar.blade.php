<header class="app-topbar d-flex align-items-center px-3">
    <button type="button" class="btn btn-link text-dark me-2 p-0" data-sidebar-toggle aria-label="Toggle navigation">
        <i class="bi bi-list fs-4"></i>
    </button>

    <form action="{{ route('dashboard') }}"
          method="GET"
          class="flex-grow-1 mx-lg-3"
          role="search"
          data-universal-search-form>
        <div class="input-group">
            <span class="input-group-text bg-white border-end-0">
                <i class="bi bi-search text-muted"></i>
            </span>
            <input
                type="search"
                name="q"
                id="global-search-input"
                class="form-control border-start-0"
                placeholder="Search phone, order ID, serial, case ID, customer..."
                value="{{ request()->routeIs('dashboard') ? request('q') : '' }}"
                aria-label="Universal search"
                autocomplete="off"
            >
        </div>
    </form>

    <div id="notification-bell-root"
         data-poll-url="{{ route('notifications.poll') }}"
         data-poll-interval="20000">
        @include('layouts.partials.notification-bell')
    </div>

    <div class="dropdown">
        <button
            class="btn btn-light border dropdown-toggle d-flex align-items-center"
            type="button"
            data-bs-toggle="dropdown"
            aria-expanded="false"
        >
            <span class="rounded-circle bg-primary text-white d-inline-flex align-items-center justify-content-center me-2"
                  style="width: 2rem; height: 2rem; font-size: 0.875rem;">
                {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
            </span>
            <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
        </button>
        <ul class="dropdown-menu dropdown-menu-end shadow">
            <li class="px-3 py-2">
                <div class="fw-semibold">{{ auth()->user()->name }}</div>
                <div class="small text-muted">{{ auth()->user()->email }}</div>
                <div class="mt-1">
                    @foreach(auth()->user()->roles as $role)
                        <span class="badge text-bg-secondary">{{ ucfirst($role->name) }}</span>
                    @endforeach
                </div>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <a class="dropdown-item" href="{{ route('profile.edit') }}">
                    <i class="bi bi-person me-2"></i> Profile
                </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="dropdown-item text-danger">
                        <i class="bi bi-box-arrow-right me-2"></i> Logout
                    </button>
                </form>
            </li>
        </ul>
    </div>
</header>
