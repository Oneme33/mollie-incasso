<?php

namespace Lukraak\MollieIncasso\Tests;

use Lukraak\MollieIncasso\{Gateway, Failure};
final class FakeGateway implements Gateway
{
    public array $customers = [], $payments = [], $subs = [], $mandateRows = [], $keys = [], $calls = [];
    public bool $timeout = false, $cancelFails = false, $revokeFails = false, $hideFind = false, $readFails = false, $rejectSubscription = false, $methodsMissing = false;
    public ?\Closure $duringPreflight = null;
    public ?\Closure $duringCreate = null;
    public function methods(string $sequence, int $cents = 1): array
    {
        if ($this->methodsMissing) {
            return [];
        }
        return $sequence === 'first' ? ['ideal'] : ['directdebit'];
    }
    public function preflightMethods(): array
    {
        if ($this->duringPreflight) {
            ($this->duringPreflight)();
        }
        return ['first' => $this->methods('first'), 'recurring' => $this->methods('recurring')];
    }
    private function create(string $kind, array $body, string $key): array
    {
        // Real Mollie rule, found in the live test: a mandate already decides the method.
        if (isset($body['method'], $body['mandateId'])) {
            throw new Failure('mollie_rejected', false, false, "Unprocessable Entity: The 'method' and 'mandateId' parameters are mutually exclusive.");
        }
        $hash = hash('sha256', json_encode($body));
        if (isset($this->keys[$key])) {
            if ($this->keys[$key]['hash'] !== $hash) {
                throw new \RuntimeException('Different retry request!');
            }
            return $this->keys[$key]['resource'];
        }
        if ($this->duringCreate) {
            $callback = $this->duringCreate;
            $this->duringCreate = null;
            $callback();
        }
        $this->calls[$kind] = ($this->calls[$kind] ?? 0) + 1;
        $id = match ($kind) {
            'customer' => 'cst_',
            'subscription' => 'sub_',
            default => 'tr_',
        } . $this->calls[$kind];
        $row = $body + ['id' => $id, 'mode' => 'test', 'status' => $kind === 'subscription' ? 'active' : 'open', 'amountRefunded' => ['currency' => 'EUR', 'value' => '0.00'], 'amountChargedBack' => ['currency' => 'EUR', 'value' => '0.00']];
        if ($kind === 'payment' && isset($body['mandateId'])) {
            $row['method'] = 'directdebit';
        }
        if ($kind === 'payment' && $body['sequenceType'] === 'first') {
            $row['checkout'] = 'https://www.mollie.com/checkout/' . $id;
        }
        if ($kind === 'subscription') {
            $row['nextPaymentDate'] = $body['startDate'];
            $this->subs[$id] = $row;
        } elseif ($kind === 'customer') {
            $this->customers[$id] = $row;
        } else {
            $this->payments[$id] = $row;
        }
        $this->keys[$key] = ['hash' => $hash, 'resource' => $row];
        if ($this->timeout) {
            $this->timeout = false;
            throw new Failure('api_unavailable', true, true);
        }
        return $row;
    }
    public function createCustomer(array $payload, string $key): array
    {
        return $this->create('customer', $payload, $key);
    }
    public function createPayment(array $payload, string $key): array
    {
        return $this->create('payment', $payload, $key);
    }
    public function payment(string $id): array
    {
        if ($this->readFails) {
            throw new Failure('api_unavailable', true);
        }
        return $this->payments[$id] ?? throw new Failure('resource_not_found');
    }
    public function mandates(string $customer): array
    {
        return array_values(array_filter($this->mandateRows, fn($r) => $r['customerId'] === $customer));
    }
    public function mandate(string $customer, string $id): array
    {
        if (!isset($this->mandateRows[$id]) || $this->mandateRows[$id]['customerId'] !== $customer) {
            throw new Failure('resource_not_found');
        }
        return $this->mandateRows[$id];
    }
    public function revoke(string $customer, string $id): void
    {
        if ($this->revokeFails) {
            throw new Failure('api_unavailable', true, true);
        }
        $this->mandateRows[$id]['status'] = 'invalid';
    }
    public function createSubscription(string $customer, array $payload, string $key): array
    {
        if ($this->rejectSubscription) {
            throw new Failure('mollie_rejected', false, false, 'Veld: startDate — Unprocessable Entity: The start date is invalid');
        }
        return $this->create('subscription', $payload + ['customerId' => $customer], $key);
    }
    public function subscription(string $customer, string $id): array
    {
        return $this->subs[$id] ?? throw new Failure('resource_not_found');
    }
    public function subscriptions(string $customer): array
    {
        return array_values(array_filter($this->subs, fn($s) => $s['customerId'] === $customer));
    }
    public function cancel(string $customer, string $id): array
    {
        if ($this->cancelFails) {
            throw new Failure('api_unavailable', true, true);
        }
        $this->subs[$id]['status'] = 'canceled';
        return $this->subs[$id];
    }
    public function find(string $kind, string $customer, string $reference, string $instance, ?string $since = null): ?array
    {
        if ($this->hideFind) {
            return null;
        }
        $rows = match ($kind) {
            'customer' => $this->customers,
            'subscription' => $this->subs,
            default => $this->payments,
        };
        foreach ($rows as $r) {
            if (($r['metadata']['mi_command'] ?? '') === $reference && ($r['metadata']['mi_instance'] ?? '') === $instance && ($kind === 'customer' || $r['customerId'] === $customer)) {
                return $r;
            }
        }
        return null;
    }
}
