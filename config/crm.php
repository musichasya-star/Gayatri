<?php

return [
    'default_ai_mode' => env('CRM_DEFAULT_AI_MODE', 'auto_reply'),
    'ai_confidence_threshold' => (float) env('CRM_AI_CONFIDENCE_THRESHOLD', 0.65),
    'reminder_h1_enabled' => (bool) env('CRM_REMINDER_H1_ENABLED', true),
    'reminder_h0_enabled' => (bool) env('CRM_REMINDER_H0_ENABLED', true),
    'campaign_rate_limit_seconds' => (int) env('CRM_CAMPAIGN_RATE_LIMIT_SECONDS', 10),
    'booking_open_time' => env('CRM_BOOKING_OPEN_TIME', '09:00'),
    'booking_close_time' => env('CRM_BOOKING_CLOSE_TIME', '18:00'),

    'ai_data_automation' => [
        'enabled' => (bool) env('CRM_AI_DATA_AUTOMATION_ENABLED', true),
        'auto_create_customer' => (bool) env('CRM_AI_AUTO_CREATE_CUSTOMER', true),
        'auto_update_customer' => (bool) env('CRM_AI_AUTO_UPDATE_CUSTOMER', true),
        'auto_create_booking' => (bool) env('CRM_AI_AUTO_CREATE_BOOKING', true),
        'booking_requires_approval' => (bool) env('CRM_AI_BOOKING_REQUIRES_APPROVAL', true),
        'extraction_confidence_threshold' => (float) env('CRM_AI_DATA_EXTRACTION_CONFIDENCE_THRESHOLD', 0.75),
        'booking_confidence_threshold' => (float) env('CRM_AI_BOOKING_CONFIDENCE_THRESHOLD', 0.85),
        'approval_expire_hours' => (int) env('CRM_AI_AUTOMATION_APPROVAL_EXPIRE_HOURS', 24),
    ],

    'roles' => ['owner', 'manager', 'admin', 'sales', 'therapist'],
    'user_statuses' => ['active', 'inactive'],
];
