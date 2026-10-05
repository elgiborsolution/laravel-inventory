<?php

namespace ESolution\Inventory\Services;

class PolicyEngine
{
    private array $rules = [];
    public function register(string $name, callable $rule): void
    {
        $this->rules[$name] = $rule;
    } public function evaluate(string $name, mixed ...$arguments): bool
    {
        return isset($this->rules[$name]) ? (bool) ($this->rules[$name])(...$arguments) : (bool) config("inventory.policies.{$name}.enabled", true);
    }
}
