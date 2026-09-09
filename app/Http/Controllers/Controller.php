<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * The company this request runs for, as the tenant middleware bound it.
     *
     * No fallback to company 1: a request with no company context sees nothing, rather than
     * another company's data.
     */
    protected function currentCompanyId(): int
    {
        if (app()->bound('current_company_id')) {
            return (int) app('current_company_id');
        }

        return (int) (auth()->user()?->company_id ?? 0);
    }
}
