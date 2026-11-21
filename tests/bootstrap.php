<?php

/**
 * PHPUnit Bootstrap File
 */

define('CRAFT_TESTS_PATH', __DIR__);
define('CRAFT_VENDOR_PATH', dirname(__DIR__) . '/vendor');
define('CRAFT_BASE_PATH', dirname(__DIR__));

// Load Composer autoloader. The harness only loads this plugin's own
// `autoload` section as a path-repo dependency, not its `autoload-dev` one,
// so tests\Support\TestCase etc. won't resolve without registering that
// mapping manually. Plain `require` (not `require_once`): PHPUnit's own
// bootstrap loader already required autoload.php once, which would
// otherwise return `true` here instead of the loader instance.
$loader = require CRAFT_VENDOR_PATH . '/autoload.php';
$loader->setPsr4('anvildev\\trails\\tests\\', [__DIR__]);

defined('YII_DEBUG') or define('YII_DEBUG', true);
defined('YII_ENV') or define('YII_ENV', 'test');

// Load Yii
require_once CRAFT_VENDOR_PATH . '/yiisoft/yii2/Yii.php';

// Load Craft class (extends Yii, provides Craft::t() etc.). Craft 6 moved
// the global \Craft facade out of craftcms/cms entirely, into the
// yii2-adapter package instead — the original path simply doesn't exist
// there. Craft 5 still has it at the original path.
$craftFacade = CRAFT_VENDOR_PATH . '/craftcms/cms/src/Craft.php';
if (!file_exists($craftFacade)) {
    $craftFacade = CRAFT_VENDOR_PATH . '/craftcms/yii2-adapter/legacy/Craft.php';
}
require_once $craftFacade;

// On Craft 6, craft\base\Component and friends only resolve to the real
// CraftCms\Cms\... classes once craftcms/yii2-adapter's class_alias() calls
// have run — normal app boot (Laravel's service-provider discovery) does
// this automatically, a bare require of the autoloader does not. No-op on
// Craft 5, where the adapter isn't installed at all.
if (class_exists(\CraftCms\Yii2Adapter\ClassAliases::class)) {
    (new \CraftCms\Yii2Adapter\ClassAliases())->register();
}

// Create a minimal Yii application for testing
$config = [
    'id' => 'trails-test',
    'basePath' => CRAFT_BASE_PATH,
    'vendorPath' => CRAFT_VENDOR_PATH,
    'components' => [
        'i18n' => [
            'class' => 'yii\i18n\I18N',
            'translations' => [
                'trails' => [
                    'class' => 'yii\i18n\PhpMessageSource',
                    'basePath' => CRAFT_BASE_PATH . '/src/translations',
                ],
            ],
        ],
    ],
];

new yii\console\Application($config);

// Craft 6's Craft::t() routes through a real Laravel Facade (I18N) instead
// of the yii\console\Application's own 'i18n' component above — with no
// facade root set, it hard-throws ("A facade root has not been set")
// rather than falling back to the configured Yii component. Stand in a
// container binding a translate() that does the same placeholder
// interpolation the Yii component above provides. No-op on Craft 5, where
// Illuminate\Support\Facades\Facade isn't installed.
if (class_exists(\Illuminate\Support\Facades\Facade::class)) {
    $container = new \Illuminate\Container\Container();
    $container->instance(\CraftCms\Cms\Translation\I18N::class, new class {
        public function translate(\Stringable|string|null $message, array $parameters = [], ?string $category = null, ?string $locale = null): string
        {
            $message = (string) $message;
            foreach ($parameters as $key => $value) {
                $message = str_replace('{' . $key . '}', (string) $value, $message);
            }

            return $message;
        }
    });
    \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
}
