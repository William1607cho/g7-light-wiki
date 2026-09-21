<?php

namespace Plugins\G7\Light\Wiki\Providers;

use App\Extension\BasePluginServiceProvider;
use Plugins\G7\Light\Wiki\Console\Commands\RebuildDocsCommand;

/**
 * g7-light-wiki 서비스 프로바이더.
 *
 * 코어 `PluginServiceProvider` 가 `plugins/<id>/src/Providers/*ServiceProvider.php` 를
 * 자동 발견해 등록한다. 라우트(`src/routes/api.php`)와 마이그레이션도 규약대로 자동이다.
 * 여기서는 artisan 명령만 더 등록한다.
 */
class LightWikiServiceProvider extends BasePluginServiceProvider
{
    protected string $pluginIdentifier = 'g7-light-wiki';

    /**
     * Register services.
     */
    public function register(): void
    {
        parent::register();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildDocsCommand::class,
            ]);
        }
    }
}
