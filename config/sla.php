<?php

return [

    /*
    | When enabled, service-case SLA elapsed time counts only business hours
    | (shift windows minus lunch, weekly offs, company holidays, and assignee
    | approved leave). Wall-clock hours remain the default until verified.
    */
    'business_hours_enabled' => (bool) env('SLA_BUSINESS_HOURS_ENABLED', false),

];
