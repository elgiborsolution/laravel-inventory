<?php

namespace ESolution\Inventory\Tests\Feature;

use ESolution\Inventory\Support\ModuleCatalog;
use ESolution\Inventory\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

final class BundledModulesTest extends TestCase
{
    private string $moduleConfigPath;

    protected function setUp(): void
    {
        $this->moduleConfigPath = sys_get_temp_dir() . '/inventory-modules-' . bin2hex(random_bytes(8));
        mkdir($this->moduleConfigPath);
        mkdir($this->moduleConfigPath . '/cache');
        parent::setUp();
    }

    protected function resolveApplication()
    {
        $app = parent::resolveApplication();
        $app->useBootstrapPath($this->moduleConfigPath);

        return $app;
    }

    protected function resolveApplicationConfiguration($app)
    {
        if ($app->configurationIsCached()) {
            // Testbench's default loader ignores the cache; use Laravel's real
            // bootstrapper for this host cache integration scenario.
            $app->bind(\Illuminate\Foundation\Bootstrap\LoadConfiguration::class, static fn() => new \Illuminate\Foundation\Bootstrap\LoadConfiguration());
        }
        parent::resolveApplicationConfiguration($app);
    }

    protected function getPackageProviders($app): array
    {
        $app->useConfigPath($this->moduleConfigPath);

        return parent::getPackageProviders($app);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach (glob($this->moduleConfigPath . '/cache/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->moduleConfigPath . '/cache');
        foreach (glob($this->moduleConfigPath . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->moduleConfigPath);
    }

    public function testPublishActivatesWmsOnNextBootAndLoadsOnlyItsMigrations(): void
    {
        $catalog = ModuleCatalog::all();
        $this->assertCount(9, $catalog);
        $this->assertNull($this->app->getProvider($catalog['wms']['provider']));
        $this->artisan('migrate')->assertSuccessful();
        $this->assertFalse(Schema::hasTable('invw_tasks'));
        $this->artisan('vendor:publish', ['--tag' => 'inventory-wms-config'])->assertSuccessful();
        $this->assertFileExists($this->moduleConfigPath . '/inventory-wms.php');
        $this->assertNull($this->app->getProvider($catalog['wms']['provider']));

        $this->refreshApplication();
        $this->assertNotNull($this->app->getProvider($catalog['wms']['provider']));
        $this->assertNull($this->app->getProvider($catalog['retail']['provider']));
        $this->artisan('inventory:modules')->expectsOutputToContain('pending')->assertSuccessful();
        $this->artisan('migrate')->assertSuccessful();
        $this->artisan('inventory:modules')->expectsOutputToContain('Complete')->assertSuccessful();

        unlink($this->moduleConfigPath . '/inventory-wms.php');
        $this->refreshApplication();
        $this->assertNull($this->app->getProvider($catalog['wms']['provider']));
    }

    public function testAllModuleTagsCanBePublishedWhileDisabled(): void
    {
        foreach (ModuleCatalog::all() as $name => $module) {
            $this->assertNull($this->app->getProvider($module['provider']));
            $this->artisan('vendor:publish', ['--tag' => 'inventory-' . $name . '-config'])->assertSuccessful();
            $this->assertFileExists($this->moduleConfigPath . '/inventory-' . $name . '.php');
        }
        $this->refreshApplication();
        foreach (ModuleCatalog::all() as $module) {
            $this->assertNotNull($this->app->getProvider($module['provider']));
        }
        $this->artisan('migrate')->assertSuccessful();
        $this->artisan('inventory:modules')->expectsOutputToContain('Not required')->assertSuccessful();
    }

    public function testPublishedModuleBootsWithCachedConfiguration(): void
    {
        $this->artisan('vendor:publish', ['--tag' => 'inventory-wms-config'])->assertSuccessful();
        $this->refreshApplication();
        $this->app['config']->set('inventory-wms.cache_test', 'retained');
        file_put_contents($this->app->getCachedConfigPath(), '<?php return ' . var_export($this->app['config']->all(), true) . ';');
        $this->refreshApplication();
        $this->assertTrue($this->app->configurationIsCached());
        $this->assertSame('retained', $this->app['config']->get('inventory-wms.cache_test'));
        $this->assertNotNull($this->app->getProvider(ModuleCatalog::all()['wms']['provider']));
        $this->assertNull($this->app->getProvider(ModuleCatalog::all()['retail']['provider']));
        $this->artisan('migrate')->assertSuccessful();
    }
}
