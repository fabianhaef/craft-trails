<?php

namespace anvildev\trails\listeners;

use anvildev\trails\models\Settings;
use anvildev\trails\services\AuditService;

/**
 * Craft 6's native content-approval workflow system
 * (`CraftCms\Cms\Workflow`, since 6.0.0) has no Craft 5 equivalent and no
 * legacy adapter bridge — it dispatches its own Laravel events directly,
 * so there's nothing to fall back to on Craft 5. The `class_exists()`
 * guard below keeps this inert there, since Craft 5 never autoloads these
 * classes at all.
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

        if (!class_exists(\CraftCms\Cms\Workflow\Events\WorkflowTransitioned::class)) {
            return;
        }

        $events = \Illuminate\Support\Facades\Event::class;

        $events::listen(
            \CraftCms\Cms\Workflow\Events\WorkflowTransitioned::class,
            function(\CraftCms\Cms\Workflow\Events\WorkflowTransitioned $event) {
                $draft = $event->draft;
                $run = $event->run;
                $actorElement = $event->actor?->asElement();

                $this->audit->log(
                    'workflow.' . $event->transition->value,
                    get_class($draft),
                    $draft->id ?? null,
                    [
                        'title' => $draft->title ?? null,
                        'actorUsername' => $actorElement?->username,
                        'actorEmail' => $actorElement?->email,
                        'runId' => $run->id,
                        'currentStage' => $run->currentStage,
                        'status' => $run->status?->value,
                        'workflow' => $run->workflow?->name,
                        'note' => $event->note,
                    ]
                );
            }
        );

        $events::listen(
            \CraftCms\Cms\Workflow\Events\WorkflowCommented::class,
            function(\CraftCms\Cms\Workflow\Events\WorkflowCommented $event) {
                $draft = $event->draft;
                $run = $event->run;
                $actorElement = $event->actor->asElement();

                $this->audit->log(
                    'workflow.commented',
                    get_class($draft),
                    $draft->id ?? null,
                    [
                        'title' => $draft->title ?? null,
                        'actorUsername' => $actorElement->username,
                        'actorEmail' => $actorElement->email,
                        'runId' => $run->id,
                        'stage' => $event->stage->name,
                        'note' => $event->note,
                    ]
                );
            }
        );
    }
}
