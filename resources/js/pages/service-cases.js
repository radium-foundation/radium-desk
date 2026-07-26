import { initServiceCaseShow } from '../service-case-show';
import { initMentionTextareas } from '../core/mention-textareas';

document.addEventListener('DOMContentLoaded', () => {
    initServiceCaseShow();
    initMentionTextareas(document.querySelector('[data-service-case-show]') ?? document);
});
