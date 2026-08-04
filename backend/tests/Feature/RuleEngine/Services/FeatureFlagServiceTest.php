<?php

namespace Tests\Feature\RuleEngine\Services;

use App\Domain\RuleEngine\Models\RuleEngineSetting;
use App\Domain\RuleEngine\Services\FeatureFlagService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class FeatureFlagServiceTest extends TestCase
{
    use RefreshDatabase;

    private FeatureFlagService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FeatureFlagService;
    }

    public function test_get_all_returns_config_defaults_when_db_empty(): void
    {
        $config = $this->service->getAll();

        $this->assertArrayHasKey('enabled', $config);
        $this->assertArrayHasKey('mode', $config);
        $this->assertArrayHasKey('fail_open', $config);
        $this->assertArrayHasKey('log_mode', $config);
        $this->assertIsBool($config['enabled']);
        $this->assertIsString($config['mode']);
    }

    public function test_get_all_returns_db_values_when_present(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'false']);
        RuleEngineSetting::create(['key' => 'mode', 'value' => 'replace']);

        $config = $this->service->getAll();

        $this->assertFalse($config['enabled']);
        $this->assertSame('replace', $config['mode']);
    }

    public function test_get_returns_config_default_for_missing_key(): void
    {
        $value = $this->service->get('enabled');

        $this->assertIsBool($value);
    }

    public function test_get_returns_db_value_for_existing_key(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => 'false']);

        $value = $this->service->get('enabled');

        $this->assertFalse($value);
    }

    public function test_update_persists_to_database(): void
    {
        $this->service->update(['enabled' => false, 'mode' => 'replace']);

        $this->assertDatabaseHas('rule_engine_settings', [
            'key' => 'enabled',
        ]);
        $this->assertDatabaseHas('rule_engine_settings', [
            'key' => 'mode',
            'value' => 'replace',
        ]);
    }

    public function test_update_returns_updated_config(): void
    {
        $result = $this->service->update(['enabled' => false, 'mode' => 'disabled']);

        $this->assertFalse($result['enabled']);
        $this->assertSame('disabled', $result['mode']);
    }

    public function test_update_logs_activity_when_changes_made(): void
    {
        $user = User::factory()->create();

        $this->service->update(['mode' => 'replace'], $user);

        $log = Activity::where('description', 'rule_engine_config_updated')->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->causer_id);
        $this->assertArrayHasKey('mode', $log->properties['changes']);
    }

    public function test_update_does_not_log_when_no_user_provided(): void
    {
        $this->service->update(['enabled' => true]);

        $logs = Activity::where('description', 'rule_engine_config_updated')->count();

        $this->assertSame(0, $logs);
    }

    public function test_boolean_casting_from_db(): void
    {
        RuleEngineSetting::create(['key' => 'enabled', 'value' => '0']);
        RuleEngineSetting::create(['key' => 'fail_open', 'value' => '1']);

        $config = $this->service->getAll();

        $this->assertFalse($config['enabled']);
        $this->assertTrue($config['fail_open']);
    }

    public function test_partial_update_only_changes_specified_keys(): void
    {
        RuleEngineSetting::create(['key' => 'mode', 'value' => 'parallel']);

        $this->service->update(['enabled' => false]);

        $mode = $this->service->get('mode');
        $enabled = $this->service->get('enabled');

        $this->assertSame('parallel', $mode);
        $this->assertFalse($enabled);
    }
}
