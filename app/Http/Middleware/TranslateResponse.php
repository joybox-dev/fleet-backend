<?php

namespace App\Http\Middleware;

use App\Services\UiTranslator;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A login that chose English sends X-Ui-Language: en, and its JSON comes back with the system's own
 * text in English (see UiTranslator). Every other request — Arabic, no header, jobs, tests — is
 * untouched. Our own header, not Accept-Language: a browser or HTTP client sends that one by itself
 * (Symfony's test requests default to en-us), so it says nothing about the login's choice.
 */
class TranslateResponse
{
    public function __construct(private UiTranslator $translator) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof JsonResponse || ! UiTranslator::wantsEnglish()) {
            return $response;
        }

        $response->setData($this->translator->translatePayload($response->getData(true)));

        return $response;
    }
}
