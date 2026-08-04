<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    protected string $botToken;

    protected string $chatId;

    public function __construct()
    {
        $this->botToken = config('services.telegram.bot_token', '');
        $this->chatId = config('services.telegram.chat_id', '');
    }

    /**
     * Send a plain notification to the configured staff chat/group.
     */
    public function notifyStaff(string $text, ?string $overrideChatId = null): bool
    {
        $targetChatId = ! empty($overrideChatId) ? $overrideChatId : $this->chatId;

        if (empty($this->botToken) || empty($targetChatId)) {
            Log::warning('TELEGRAM_BOT_TOKEN o TELEGRAM_CHAT_ID no configurados. Notificación al staff omitida.');

            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        try {
            $response = Http::asForm()->post($url, [
                'chat_id' => $targetChatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if ($response->successful()) {
                return true;
            }

            Log::error('Error enviando notificación a Telegram', $response->json() ?? []);
        } catch (\Exception $e) {
            Log::error('Excepción en TelegramService: '.$e->getMessage());
        }

        return false;
    }
}
