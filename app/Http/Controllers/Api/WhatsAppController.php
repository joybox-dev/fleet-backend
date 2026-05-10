<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;

class WhatsAppController extends Controller
{
    private $whatsapp;

    public function __construct(WhatsAppService $whatsapp)
    {
        $this->whatsapp = $whatsapp;
    }

    public function testConnection()
    {
        if (!$this->whatsapp->isConfigured()) {
            return response()->json(['success' => false, 'message' => 'WhatsApp API not configured.']);
        }

        // Just checking if configured for now. A real test would send a message or query account status.
        return response()->json(['success' => true, 'message' => 'WhatsApp is configured and ready.']);
    }

    public function sendMessage(Request $request)
    {
        $request->validate([
            'to' => 'required|string',
            'message' => 'required|string',
        ]);

        $result = $this->whatsapp->sendMessage($request->to, $request->message);

        return response()->json($result);
    }
}
