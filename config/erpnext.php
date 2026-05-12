<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ERPNext Connection
    |--------------------------------------------------------------------------
    |
    | Base URL and authentication credentials for the ERPNext instance.
    | FleetOps connects to ERPNext as a financial backend only.
    | Option 3: Standalone FleetOps + ERPNext API integration.
    |
    */

    'base_url' => env('ERPNEXT_BASE_URL', 'http://localhost:8080'),

    'api_key' => env('ERPNEXT_API_KEY', ''),
    'api_secret' => env('ERPNEXT_API_SECRET', ''),

    // Alternative: session-based auth (for dev/testing)
    'username' => env('ERPNEXT_USERNAME', 'Administrator'),
    'password' => env('ERPNEXT_PASSWORD', 'admin'),

    // Auth method: 'token' (API key) or 'session' (username/password)
    'auth_method' => env('ERPNEXT_AUTH_METHOD', 'token'),

    /*
    |--------------------------------------------------------------------------
    | Company & Defaults
    |--------------------------------------------------------------------------
    */

    'company' => env('ERPNEXT_COMPANY', 'FleetOps'),
    'default_currency' => env('ERPNEXT_CURRENCY', 'KWD'),
    'cost_center' => env('ERPNEXT_COST_CENTER', 'Main - FO'),

    /*
    |--------------------------------------------------------------------------
    | Chart of Accounts Mapping
    |--------------------------------------------------------------------------
    |
    | Maps FleetOps entity types to ERPNext account names.
    | These must match the Chart of Accounts configured in ERPNext.
    |
    */

    'accounts' => [
        // Income accounts (per contract)
        'delivery_income' => env('ERPNEXT_ACCOUNT_DELIVERY_INCOME', '4120 - Service - FO'),

        // Cash accounts
        'cash_in_hand' => env('ERPNEXT_ACCOUNT_CASH', '1110 - Cash - FO'),
        'pending_cash' => env('ERPNEXT_ACCOUNT_PENDING_CASH', '1310 - Debtors - FO'),

        // Expense accounts
        'salary_expense' => env('ERPNEXT_ACCOUNT_SALARY', '5213 - Salary - FO'),
        'fuel_expense' => env('ERPNEXT_ACCOUNT_FUEL', '5216 - Travel Expenses - FO'),
        'maintenance_expense' => env('ERPNEXT_ACCOUNT_MAINTENANCE', '5208 - Office Maintenance Expenses - FO'),
        'insurance_expense' => env('ERPNEXT_ACCOUNT_INSURANCE', '5201 - Administrative Expenses - FO'),
        'violation_receivable' => env('ERPNEXT_ACCOUNT_VIOLATION', '1310 - Debtors - FO'),

        // Payable accounts
        'accounts_payable' => env('ERPNEXT_ACCOUNT_PAYABLE', '2110 - Creditors - FO'),

        // Asset accounts
        'vehicle_asset' => env('ERPNEXT_ACCOUNT_VEHICLE_ASSET', '1710 - Capital Equipment - FO'),
        'depreciation' => env('ERPNEXT_ACCOUNT_DEPRECIATION', '1780 - Accumulated Depreciation - FO'),

        // Advance accounts
        'advance_receivable' => env('ERPNEXT_ACCOUNT_ADVANCE_RECEIVABLE', '1320 - Employee Advances - FO'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Warehouse (for Stock/Custody)
    |--------------------------------------------------------------------------
    */

    'warehouse' => env('ERPNEXT_WAREHOUSE', 'Stores - FO'),

    /*
    |--------------------------------------------------------------------------
    | Payroll Settings
    |--------------------------------------------------------------------------
    */

    'payroll' => [
        // Only official salary is synced to ERPNext (bank salary)
        // Internal salary stays in FleetOps only
        'salary_component_basic' => 'Basic Salary',
        'payroll_frequency' => 'Monthly',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sync Settings
    |--------------------------------------------------------------------------
    |
    | Controls how FleetOps syncs data to ERPNext.
    | FleetOps is the source of truth. ERPNext is a financial mirror.
    | If ERPNext is down, FleetOps continues working normally.
    |
    */

    'sync' => [
        'enabled' => env('ERPNEXT_SYNC_ENABLED', true),

        // Queue name for sync jobs
        'queue' => env('ERPNEXT_SYNC_QUEUE', 'erpnext-sync'),

        // Retry configuration
        'max_retries' => env('ERPNEXT_MAX_RETRIES', 3),
        'retry_delay_seconds' => env('ERPNEXT_RETRY_DELAY', 30),
        'retry_backoff_multiplier' => 2, // exponential: 30s, 60s, 120s

        // Circuit breaker: stop trying after N consecutive failures
        'circuit_breaker' => [
            'failure_threshold' => 5,      // after 5 failures in window
            'window_seconds' => 600,       // 10 minute window
            'recovery_seconds' => 300,     // wait 5 minutes before retrying
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Client Settings
    |--------------------------------------------------------------------------
    */

    'http' => [
        'timeout' => env('ERPNEXT_HTTP_TIMEOUT', 30),
        'connect_timeout' => env('ERPNEXT_HTTP_CONNECT_TIMEOUT', 10),
        'verify_ssl' => env('ERPNEXT_VERIFY_SSL', false), // false for dev
    ],

];
