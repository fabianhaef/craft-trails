<?php

namespace anvildev\trails\tests\Unit\Jobs;

use anvildev\trails\jobs\AnonymizeUserLogsJob;
use anvildev\trails\tests\Support\TestCase;
use ReflectionMethod;

class AnonymizeUserLogsJobTest extends TestCase
{
    public function testJobCanBeInstantiated(): void
    {
        $job = new AnonymizeUserLogsJob();
        $this->assertInstanceOf(AnonymizeUserLogsJob::class, $job);
    }

    public function testJobExtendsBaseJob(): void
    {
        $job = new AnonymizeUserLogsJob();
        $this->assertInstanceOf(\craft\queue\BaseJob::class, $job);
    }

    public function testScrubMetadataPiiReturnsNullForNullInput(): void
    {
        $method = new ReflectionMethod(AnonymizeUserLogsJob::class, 'scrubMetadataPii');
        $this->assertNull($method->invoke(new AnonymizeUserLogsJob(), null));
    }

    public function testScrubMetadataPiiLeavesNonJsonStringUntouched(): void
    {
        $method = new ReflectionMethod(AnonymizeUserLogsJob::class, 'scrubMetadataPii');
        $this->assertSame('not json', $method->invoke(new AnonymizeUserLogsJob(), 'not json'));
    }

    public function testScrubMetadataPiiLeavesRowsWithoutPiiKeysUntouched(): void
    {
        $method = new ReflectionMethod(AnonymizeUserLogsJob::class, 'scrubMetadataPii');
        $original = json_encode(['title' => 'Some Entry', 'runId' => 42]);
        $this->assertSame($original, $method->invoke(new AnonymizeUserLogsJob(), $original));
    }

    public function testScrubMetadataPiiScrubsAuthEventListenerShapedContext(): void
    {
        $method = new ReflectionMethod(AnonymizeUserLogsJob::class, 'scrubMetadataPii');
        $original = json_encode(['username' => 'jdoe', 'email' => 'jdoe@example.com']);

        $result = $method->invoke(new AnonymizeUserLogsJob(), $original);
        $decoded = json_decode($result, true);

        $this->assertSame('[deleted]', $decoded['username']);
        $this->assertNull($decoded['email']);
    }

    public function testScrubMetadataPiiScrubsWorkflowEventListenerShapedContext(): void
    {
        $method = new ReflectionMethod(AnonymizeUserLogsJob::class, 'scrubMetadataPii');
        $original = json_encode([
            'title' => 'Some Entry',
            'actorUsername' => 'jdoe',
            'actorEmail' => 'jdoe@example.com',
            'runId' => 42,
            'note' => null,
        ]);

        $result = $method->invoke(new AnonymizeUserLogsJob(), $original);
        $decoded = json_decode($result, true);

        $this->assertSame('[deleted]', $decoded['actorUsername']);
        $this->assertNull($decoded['actorEmail']);
        $this->assertSame('Some Entry', $decoded['title']);
        $this->assertSame(42, $decoded['runId']);
    }

    public function testScrubMetadataPiiIsIdempotent(): void
    {
        $method = new ReflectionMethod(AnonymizeUserLogsJob::class, 'scrubMetadataPii');
        $original = json_encode(['username' => 'jdoe', 'email' => 'jdoe@example.com']);

        $once = $method->invoke(new AnonymizeUserLogsJob(), $original);
        $twice = $method->invoke(new AnonymizeUserLogsJob(), $once);

        $this->assertSame($once, $twice);
    }
}
