<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardButton;
use SergiX44\Nutgram\Telegram\Types\Keyboard\InlineKeyboardMarkup;

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
     * Get a configured Nutgram instance.
     */
    public function getBot(): ?Nutgram
    {
        if (empty($this->botToken)) {
            return null;
        }

        try {
            return new Nutgram($this->botToken);
        } catch (\Throwable $e) {
            Log::error('Error instanciando Nutgram: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Send a plain notification to the configured staff chat/group using Nutgram/HTTP.
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

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Error enviando notificación a Telegram: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Send an ApprovalGate confirmation prompt with Inline Keyboard (Confirmar/Cancelar) buttons using Nutgram markup.
     */
    public function sendApprovalPrompt(string $chatId, string $actionDescription, string $token): bool
    {
        if (empty($this->botToken) || empty($chatId)) {
            return false;
        }

        $keyboard = InlineKeyboardMarkup::make()
            ->addRow(
                InlineKeyboardButton::make('✅ Confirmar', callback_data: "approve:{$token}"),
                InlineKeyboardButton::make('❌ Cancelar', callback_data: "cancel:{$token}")
            );

        $text = "⚠️ <b>APROBACIÓN REQUERIDA</b>\n\n{$actionDescription}\n\n<i>Presiona un botón para confirmar o cancelar la acción:</i>";
        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        try {
            $response = Http::post($url, [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'reply_markup' => json_decode(json_encode($keyboard), true),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Error enviando prompt de aprobación vía Telegram: '.$e->getMessage());

            return false;
        }
    }
}
