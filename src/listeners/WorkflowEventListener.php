<?php

namespace anvildev\trails\listeners;

use anvildev\trails\helpers\NativeEventBridge;
use anvildev\trails\models\Settings;
use anvildev\trails\services\AuditService;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\User\Contracts\CraftUser;
use CraftCms\Cms\Workflow\Events\WorkflowCommented;
use CraftCms\Cms\Workflow\Events\WorkflowTransitioned;
use CraftCms\Cms\Workflow\Models\WorkflowRun;

/**
 * Craft 6's native content-approval workflow system
 * (`CraftCms\Cms\Workflow`, since 6.0.0) has no Craft 5 equivalent and no
 * legacy adapter bridge — it dispatches its own Laravel events directly,
 * so there's nothing to fall back to on Craft 5. NativeEventBridge keeps
 * this inert there, since Craft 5 never autoloads these classes at all.
 */
class WorkflowEventListener
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly Settings $settings,
    ) {
    }

    public function register(): void
    {
        if (!$this->settings->logWorkflowEvents) {
            return;
        }

        NativeEventBridge::listen(WorkflowTransitioned::class, function(WorkflowTransitioned $event) {
            $this->logWorkflowEvent(
                'workflow.' . $event->transition->value,
                $event->draft,
                $event->run,
                $event->actor,
                $event->note,
                [
                    'workflowId' => $event->run->workflowId,
                    'currentStage' => $event->run->currentStage,
                    'status' => $event->run->status?->value,
                ]
            );
        });

        NativeEventBridge::listen(WorkflowCommented::class, function(WorkflowCommented $event) {
            $this->logWorkflowEvent(
                'workflow.commented',
                $event->draft,
                $event->run,
                $event->actor,
                $event->note,
                ['stage' => $event->stage->name]
            );
        });
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function logWorkflowEvent(
        string $eventType,
        ElementInterface $draft,
        WorkflowRun $run,
        ?CraftUser $actor,
        ?string $note,
        array $extra = [],
    ): void {
        $actorElement = $actor?->asElement();

        $this->audit->log(
            $eventType,
            get_class($draft),
            $draft->id ?? null,
            array_merge([
                'title' => $draft->title ?? null,
                'actorUsername' => $actorElement?->username,
                'actorEmail' => $actorElement?->email,
                'runId' => $run->id,
                'note' => $note,
            ], $extra)
        );
    }
}
