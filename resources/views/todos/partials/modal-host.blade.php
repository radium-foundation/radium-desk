@can('viewAny', \App\Models\Todo::class)
<div
    id="todoModal"
    class="modal fade todo-modal"
    data-todo-modal
    data-todo-endpoint="{{ route('todos.index') }}"
    tabindex="-1"
    aria-hidden="true"
    aria-labelledby="todoModalTitle"
>
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable todo-modal__dialog">
        <div class="modal-content">
            <div class="modal-header todo-modal__header">
                <div>
                    <h2 id="todoModalTitle" class="modal-title h6 mb-0">To-Dos</h2>
                    <p class="text-muted small mb-0">Tasks without leaving this page</p>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="Close To-Dos"
                ></button>
            </div>
            <div class="modal-body todo-modal__body p-0">
                <div class="todo-modal__loading" data-todo-modal-loading hidden>
                    <div class="todo-skeleton todo-skeleton--line"></div>
                    <div class="todo-skeleton todo-skeleton--row"></div>
                    <div class="todo-skeleton todo-skeleton--row"></div>
                </div>
                <div class="todo-modal__error alert alert-danger d-none mx-3 mt-3" data-todo-modal-error role="alert"></div>
                <div class="todo-modal__content" data-todo-modal-content-host></div>
            </div>
        </div>
    </div>
</div>
@endcan
