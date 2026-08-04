<?php

namespace App\Domain\RuleEngine\Services;

use App\Domain\RuleEngine\Models\RuleEngineSetting;

class FeatureFlagService
{
    private const KEYS = ['enabled', 'mode', 'fail_open', 'log_mode'];

    public function getAll(): array
    {
        $dbSettings = RuleEngineSetting::pluck('value', 'key')->toArray();

        return [
            'enabled' => $this->castValue($dbSettings['enabled'] ?? config('rule-engine.enabled'), 'enabled'),
            'mode' => $dbSettings['mode'] ?? config('rule-engine.mode'),
            'fail_open' => $this->castValue($dbSettings['fail_open'] ?? config('rule-engine.fail_open'), 'fail_open'),
            'log_mode' => $dbSettings['log_mode'] ?? config('rule-engine.log_mode'),
        ];
    }

    public function get(string $key): mixed
    {
        $setting = RuleEngineSetting::find($key);

        if ($setting !== null) {
            return $this->castValue($setting->value, $key);
        }

        return config("rule-engine.{$key}");
    }

    public function update(array $data, ?\Illuminate\Contracts\Auth\Authenticatable $user = null): array
    {
        $oldValues = $this->getAll();

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $data)) {
                RuleEngineSetting::updateOrCreate(
                    ['key' => $key],
                    ['value' => (string) $data[$key]]
                );
            }
        }

        $newValues = $this->getAll();

        $changes = [];
        foreach (self::KEYS as $key) {
            $old = $oldValues[$key] ?? null;
            $new = $newValues[$key] ?? null;
            if ($old !== $new) {
                $changes[$key] = ['old' => $old, 'new' => $new];
            }
        }

        if (!empty($changes) && $user) {
            activity()
                ->causedBy($user)
                ->withProperties(['changes' => $changes])
                ->log('rule_engine_config_updated');
        }

        return $newValues;
    }

    private function castValue(mixed $value, string $key): mixed
    {
        if (in_array($key, ['enabled', 'fail_open'], true)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value;
        }

        return $value;
    }
}
