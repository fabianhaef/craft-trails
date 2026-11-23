<?php

namespace anvildev\trails\tests\Unit\Listeners;

use anvildev\trails\listeners\WorkflowEventListener;
use anvildev\trails\models\Settings;
use anvildev\trails\services\AuditService;
use anvildev\trails\tests\Support\TestCase;
use Mockery;

/**
 * register()'s Craft 6 path binds through Illuminate\Support\Facades\Event,
 * whose facade root this plugin's lightweight Yii test bootstrap doesn't
 * reliably keep bound across the full suite (order-dependent, not worth
 * fighting here) — that path is instead verified by dispatching real
 * WorkflowTransitioned/WorkflowCommented events against the live Craft 6
 * harness. This class only covers what's safe to assert without a bound
 * Laravel container.
 */
class WorkflowEventListenerTest extends TestCase
{
    public function testConstructorAcceptsDependencies(): void
    {
        $audit = Mockery::mock(AuditService::class);
        $settings = new Settings();
        $listener = new WorkflowEventListener($audit, $settings);
        $this->assertInstanceOf(WorkflowEventListener::class, $listener);
    }

    public function testRegisterNoOpsOnCraft5WithoutTouchingIlluminate(): void
    {
        if (class_exists(\CraftCms\Cms\Workflow\Events\WorkflowTransitioned::class)) {
            $this->markTestSkipped('Craft 6 is present; the bind path is verified against the live harness instead.');
        }

        $audit = Mockery::mock(AuditService::class);
        $settings = new Settings();
        $settings->logWorkflowEvents = true;
        $listener = new WorkflowEventListener($audit, $settings);

        $listener->register();
        $this->addToAssertionCount(1);
    }
}
