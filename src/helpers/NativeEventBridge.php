<?php

namespace anvildev\trails\helpers;

/**
 * Binds a listener to a native Illuminate event, inert when the event
 * class doesn't exist. The shared gate for the handful of Craft-6-only
 * events (auth, workflow) that have no Yii-adapter bridge and must be
 * reached via Illuminate\Support\Facades\Event directly — unlike
 * EventBridgeService, which is for third-party plugins' own Yii events
 * and goes through logCustomEvent()'s reserved-prefix checks, this is for
 * Trails' own first-party listeners logging into its reserved namespaces.
 */
class NativeEventBridge
{
    /** @var array<string, bool> */
    private static array $existsCache = [];

    public static function listen(string $eventClass, callable $listener): void
    {
        if (!(self::$existsCache[$eventClass] ??= class_exists($eventClass))) {
            return;
        }

        \Illuminate\Support\Facades\Event::listen($eventClass, $listener);
    }
}
