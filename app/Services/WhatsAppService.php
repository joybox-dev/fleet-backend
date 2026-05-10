<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Http;

class WhatsAppService
{
    private $token;
    private $phoneNumberId;

    public function __construct()
    {
        $this->token = Setting::get('whatsapp_access_token');
        $this->phoneNumberId = Setting::get('whatsapp_phone_number_id');
    }

    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->phoneNumberId);
    }

    public function sendMessage(string $to, string $message): array
    {
        if (!$this->isConfigured()) {
            return ['success' => false, 'message' => 'WhatsApp API not configured.'];
        }

        $url = "https://graph.facebook.com/v19.0/{$this->phoneNumberId}/messages";
        
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $message,
            ],
        ];

        $response = Http::withToken($this->token)->post($url, $payload);

        if ($response->successful()) {
            return ['success' => true, 'data' => $response->json()];
        }

        return ['success' => false, 'message' => $response->json()['error']['message'] ?? 'Failed to send message.'];
    }
}
