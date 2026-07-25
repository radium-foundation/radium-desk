@if($navigationContext->showBreadcrumb && ! empty($navigationContext->breadcrumbs))
    <nav aria-label="breadcrumb" class="app-navigation-breadcrumb mb-3">
        <ol class="breadcrumb mb-0">
            @foreach($navigationContext->breadcrumbs as $crumb)
                <li @class(['breadcrumb-item', 'active' => $crumb['url'] === null]) @if($crumb['url'] === null) aria-current="page" @endif>
                    @if($crumb['url'] !== null)
                        <a href="{{ $crumb['url'] }}">{{ $crumb['label'] }}</a>
                    @else
                        {{ $crumb['label'] }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
