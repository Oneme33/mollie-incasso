<?php

namespace Lukraak\MollieIncasso;

use Lukraak\MollieIncasso\Vendor\Mollie\Api\MollieApiClient;
final class MollieGateway implements Gateway
{
    private MollieApiClient $client;
    public function __construct(private readonly Config $config, private readonly string $mode, ?MollieApiClient $client = null)
    {
        $autoload = dirname(__DIR__) . '/dependencies/autoload.php';
        if (!is_file($autoload)) {
            throw new Failure('sdk_missing');
        }
        require_once $autoload;
        try {
            $this->client = $client ?? new MollieApiClient();
            $this->client->setRetryStrategy(new Vendor\Mollie\Api\Http\LinearRetryStrategy(0));
            $this->client->setApiKey($config->key($mode));
            $this->client->addVersionString('MollieIncasso/' . MOLLIE_INCASSO_VERSION);
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new Failure('sdk_initialization_failed');
        }
    }
    private function call(callable $callback, bool $mutates = false): mixed
    {
        $this->config->assertRun($this->mode, $mutates);
        try {
            return $callback();
        } catch (Failure $e) {
            throw $e;
        } catch (\Throwable $e) {
            $status = method_exists($e, 'getStatusCode') ? (int) $e->getStatusCode() : 0;
            if ($status === 404) {
                throw new Failure('resource_not_found');
            }
            if ($status === 401 || $status === 403) {
                throw new Failure('api_access_denied');
            }
            if ($status >= 400 && $status < 500 && !in_array($status, [408, 409, 429], true)) {
                throw new Failure('mollie_rejected');
            }
            throw new Failure($status === 429 ? 'api_rate_limited' : 'api_unavailable', true, $mutates);
        }
    }
    private function data(object $r, array $fields): array
    {
        $data = [];
        foreach ($fields as $field) {
            $data[$field] = $r->{$field} ?? null;
        }
        return json_decode(wp_json_encode($data), true);
    }
    private function customerData(object $r): array
    {
        return $this->data($r, ['id', 'metadata', 'mode']);
    }
    private function paymentData(object $r): array
    {
        $data = $this->data($r, ['id', 'mode', 'status', 'customerId', 'subscriptionId', 'mandateId', 'method', 'sequenceType', 'metadata', 'amount', 'amountRefunded', 'amountChargedBack', 'createdAt', 'paidAt']);
        $data['checkout'] = $r->_links->checkout->href ?? null;
        $code = (string) ($r->details->failureReason ?? $r->details->failureMessage ?? '');
        $data['failure'] = preg_match('/^[A-Z0-9_-]{1,40}$/D', $code) ? $code : ($r->status === 'failed' ? 'payment_failed' : '');
        return $data;
    }
    private function mandateData(object $r): array
    {
        $data = $this->data($r, ['id', 'status', 'method', 'customerId', 'createdAt']);
        $account = (string) ($r->details->consumerAccount ?? '');
        $data['masked'] = $account !== '' ? '•••• ' . substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($account)), -4) : '';
        return $data;
    }
    private function subscriptionData(object $r): array
    {
        return $this->data($r, ['id', 'mode', 'status', 'customerId', 'mandateId', 'amount', 'interval', 'startDate', 'nextPaymentDate', 'times', 'metadata']);
    }
    public function methods(string $sequence, int $cents = 1): array
    {
        return $this->call(function () use ($sequence, $cents) {
            $result = [];
            foreach ($this->client->methods->allEnabled(['sequenceType' => $sequence, 'amount' => Money::api($cents), 'locale' => 'nl_NL']) as $method) {
                $result[] = $method->id;
            }
            return $result;
        });
    }
    public function createCustomer(array $payload, string $key): array
    {
        return $this->call(function () use ($payload, $key) {
            $this->client->setIdempotencyKey($key);
            return $this->customerData($this->client->customers->create($payload));
        }, true);
    }
    public function createPayment(array $payload, string $key): array
    {
        return $this->call(function () use ($payload, $key) {
            $this->client->setIdempotencyKey($key);
            return $this->paymentData($this->client->payments->create($payload));
        }, true);
    }
    public function payment(string $id): array
    {
        return $this->call(fn() => $this->paymentData($this->client->payments->get($id, ['embed' => 'refunds,chargebacks'])));
    }
    public function mandates(string $customer): array
    {
        return $this->call(function () use ($customer) {
            $result = [];
            foreach ($this->client->mandates->iteratorForId($customer, null, 100) as $mandate) {
                $result[] = $this->mandateData($mandate);
                if (count($result) > 1000) {
                    throw new Failure('reconciliation_limit');
                }
            }
            return $result;
        });
    }
    public function mandate(string $customer, string $id): array
    {
        return $this->call(fn() => $this->mandateData($this->client->mandates->getForId($customer, $id)));
    }
    public function revoke(string $customer, string $id): void
    {
        $this->call(fn() => $this->client->mandates->revokeForId($customer, $id), true);
    }
    public function createSubscription(string $customer, array $payload, string $key): array
    {
        return $this->call(function () use ($customer, $payload, $key) {
            $this->client->setIdempotencyKey($key);
            return $this->subscriptionData($this->client->subscriptions->createForId($customer, $payload));
        }, true);
    }
    public function subscription(string $customer, string $id): array
    {
        return $this->call(fn() => $this->subscriptionData($this->client->subscriptions->getForId($customer, $id)));
    }
    public function subscriptions(string $customer): array
    {
        return $this->call(function () use ($customer) {
            $rows = [];
            foreach ($this->client->subscriptions->iteratorForId($customer, null, 100) as $s) {
                $rows[] = $this->subscriptionData($s);
                if (count($rows) > 2000) {
                    throw new Failure('reconciliation_limit');
                }
            }
            return $rows;
        });
    }
    public function cancel(string $customer, string $id): array
    {
        return $this->call(fn() => $this->subscriptionData($this->client->subscriptions->cancelForId($customer, $id)), true);
    }
    public function find(string $kind, string $customer, string $reference, string $instance, ?string $since = null): ?array
    {
        // An hour of margin covers clock skew; anything older cannot be the uncertain create.
        $cutoff = $since ? strtotime($since . ' UTC') - HOUR_IN_SECONDS : null;
        return $this->call(function () use ($kind, $customer, $reference, $instance, $cutoff) {
            $items = match ($kind) {
                'customer' => $this->client->customers->iterator(null, 100),
                'subscription' => $this->client->subscriptions->iteratorForId($customer, null, 100),
                default => $this->client->customerPayments->iteratorForId($customer, null, 100),
            };
            $count = 0;
            $found = null;
            foreach ($items as $r) {
                if ($cutoff && !empty($r->createdAt) && strtotime((string) $r->createdAt) < $cutoff) {
                    break;
                }
                if (++$count > 2000) {
                    throw new Failure('reconciliation_limit');
                }
                if (($r->metadata->mi_command ?? null) === $reference && ($r->metadata->mi_instance ?? null) === $instance) {
                    if ($found) {
                        throw new Failure('duplicate_remote_resource');
                    }
                    $found = match ($kind) {
                        'customer' => $this->customerData($r),
                        'subscription' => $this->subscriptionData($r),
                        default => $this->paymentData($r),
                    };
                }
            }
            return $found;
        });
    }
}
