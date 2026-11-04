<?php

namespace App\Services\AI;

class AiInformationReply
{
    public function respond(string $message, array $policy, array $context = []): array
    {
        $result = app(AiConversationOrchestrator::class)->respond($message, $policy, $context);

        return app(AiResponseComposer::class)->compose($result, $context);
    }
}
