# WhatsApp Template Dispatcher

**Principle:** Persist dispatch events. Route sends through outbox. Never call Interakt from controllers.

## Flow

```
Trigger (Manual / Automation / Scheduler / IRA / Webhook)
        ↓
WhatsAppAutomationDispatcher
        ↓
WhatsAppTemplateDispatcher
        ↓
Outbox (interakt.template.send)
        ↓
InteraktOutboundProcessorService → InteraktService
        ↓
whatsapp_template_dispatches + audit + optional note
        ↓
Customer360 Timeline
```

## Future automations

Call `WhatsAppAutomationDispatcher::dispatch()` with the appropriate `WhatsAppTemplate` and `WhatsAppTemplateTriggerSource`. No architectural changes required.
