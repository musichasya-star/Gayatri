<?php

namespace Tests\Concerns;

use App\Services\AI\AiInteractionPolicy;
use Mockery\MockInterface;

/**
 * Historical behavior only. No production setting can enable this policy.
 * Current security/runtime contracts are exercised without this test double.
 */
trait ExercisesLegacyAi
{
    protected function setUpExercisesLegacyAi(): void
    {
        $this->partialMock(AiInteractionPolicy::class, function (MockInterface $mock) {
            $mock->shouldReceive('allowsLegacyAutomation')->andReturnTrue();
        });
    }
}
