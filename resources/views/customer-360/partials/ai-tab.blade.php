@include('customer-360.partials.ai-advisor', ['insights' => $operationsAdvisorInsights ?? []])
@include('customer-360.partials.ai-assistant', ['aiAssistant' => $aiAssistant])
@include('customer-360.partials.ai-workbench', [
    'workbench' => $aiWorkbench,
    'incident' => $incident,
])
