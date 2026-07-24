<div class="operations-automation-tab" data-operations-automation-tab>
    <nav class="nav nav-pills operations-automation-subnav mb-3 flex-nowrap overflow-auto" aria-label="Automation views">
        <button
            type="button"
            class="nav-link active"
            data-automation-subview-target="health"
            aria-selected="true"
        >
            Health
        </button>
        <button
            type="button"
            class="nav-link"
            data-automation-subview-target="pipeline"
            aria-selected="false"
        >
            Pipeline
        </button>
    </nav>

    <div
        data-automation-subview-pane="health"
        id="operations-automation-health-content"
        data-operations-automation-subview-loaded="false"
    >
        @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading automation health…'])
    </div>

    <div
        data-automation-subview-pane="pipeline"
        id="operations-automation-pipeline-content"
        class="d-none"
        data-operations-automation-subview-loaded="false"
    >
        @include('admin.operations.partials.lazy-tab-placeholder', ['label' => 'Loading automation pipeline…'])
    </div>
</div>
