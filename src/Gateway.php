<?php

namespace Lukraak\MollieIncasso;

interface Gateway
{
    public function methods(string $sequence, int $cents = 1): array;
    public function createCustomer(array $payload, string $key): array;
    public function createPayment(array $payload, string $key): array;
    public function payment(string $id): array;
    public function mandates(string $customer): array;
    public function mandate(string $customer, string $id): array;
    public function revoke(string $customer, string $id): void;
    public function createSubscription(string $customer, array $payload, string $key): array;
    public function subscription(string $customer, string $id): array;
    public function subscriptions(string $customer): array;
    public function cancel(string $customer, string $id): array;
    public function find(string $kind, string $customer, string $reference, string $instance): ?array;
}
