<?php

namespace perfectwebteam\anonymize;

use craft\console\Application as ConsoleApplication;
use perfectwebteam\anonymize\console\AnonymizeController;
use yii\base\BootstrapInterface;

/**
 * Registers `php craft anonymize/…`. Composer's yii2-extension installer lists
 * this class in vendor/yiisoft/extensions.php and Yii bootstraps it on every
 * request, so a project only has to require the package: no plugin to
 * install, nothing in project config. Web requests get nothing.
 */
final class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app): void
    {
        if ($app instanceof ConsoleApplication) {
            $app->controllerMap['anonymize'] = AnonymizeController::class;
        }
    }
}
