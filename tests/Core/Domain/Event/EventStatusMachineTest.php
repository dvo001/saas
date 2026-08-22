<?php

declare(strict_types=1);

namespace App\Tests\Core\Domain\Event;

use App\Core\Domain\Event\EventStatus;
use App\Core\Domain\Event\EventStatusMachine;
use PHPUnit\Framework\TestCase;

final class EventStatusMachineTest extends TestCase
{
    public function testHappyPathIsStrictlyForwardOnly(): void
    {
        $machine = new EventStatusMachine();
        $machine->assertTransition(EventStatus::Draft, EventStatus::Preparation);
        $machine->assertTransition(EventStatus::Preparation, EventStatus::Running);
        $machine->assertTransition(EventStatus::Running, EventStatus::Completed, confirmed: true);
        $machine->assertTransition(EventStatus::Completed, EventStatus::Archived, confirmed: true);
        self::assertSame([], $machine->allowedTargets(EventStatus::Archived));
    }

    public function testCancellationRequiresReasonAndConfirmation(): void
    {
        $this->expectException(\DomainException::class);
        (new EventStatusMachine())->assertTransition(EventStatus::Running, EventStatus::Cancelled, '', true);
    }

    public function testCompletedEventCannotReturnToRunning(): void
    {
        $this->expectException(\DomainException::class);
        (new EventStatusMachine())->assertTransition(EventStatus::Completed, EventStatus::Running, confirmed: true);
    }
}
