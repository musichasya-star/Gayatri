<?php

namespace App\Services\WhatsApp;

interface WhatsAppGateway
{
    public function listSessions(): array;

    public function createSession(string $name, array $config = []): array;

    public function updateSession(string $name, array $config = []): array;

    public function getSession(string $name): array;

    public function startSession(string $name): array;

    public function stopSession(string $name): array;

    public function restartSession(string $name): array;

    public function logoutSession(string $name): array;

    public function getQr(string $session): array;

    public function getMe(string $session): array;

    public function sendText(string $session, string $chatId, string $text): array;

    public function startTyping(string $session, string $chatId): void;

    public function stopTyping(string $session, string $chatId): void;
}
