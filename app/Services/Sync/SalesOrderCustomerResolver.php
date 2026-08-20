<?php

namespace App\Services\Sync;

use App\Models\SyncMapping;
use App\Services\Erp\ErpInterface;
use Illuminate\Support\Facades\Log;

/**
 * Links Shopify orders to synced ERP customers when PII is missing from the order payload.
 */
class SalesOrderCustomerResolver
{
    /**
     * @param  array<string, mixed>  $ecomOrder
     * @return array<string, mixed>
     */
    public function mergeSyncedCustomerOntoOrder(array $ecomOrder, string $ecomDriver): array
    {
        $mapping = $this->findSyncedCustomerMappingForOrder($ecomOrder, $ecomDriver);
        if ($mapping === null) {
            return $ecomOrder;
        }

        $customer = $this->orderCustomer($ecomOrder);
        $payload  = $mapping->payload();
        if (!is_array($payload)) {
            $payload = [];
        }

        if (($customer['id'] ?? '') === '' && !empty($mapping->ecom_id)) {
            $customer['id'] = (string) $mapping->ecom_id;
        }

        foreach (['email', 'firstName', 'first_name', 'lastName', 'last_name', 'phone'] as $key) {
            if (($customer[$key] ?? '') === '' && !empty($payload[$key])) {
                $customer[$key] = $payload[$key];
            }
        }

        if ($this->resolveOrderEmail($ecomOrder) === '' && !empty($payload['email'])) {
            $ecomOrder['email'] = $payload['email'];
        }

        if ($customer !== []) {
            $ecomOrder['customer'] = $customer;
        }

        return $ecomOrder;
    }

    /**
     * @param  array<string, mixed>  $ecomOrder
     */
    public function resolvePartnerId(array $ecomOrder, ErpInterface $erp, string $ecomDriver): int|string|null
    {
        $customer       = $this->orderCustomer($ecomOrder);
        $customerEcomId = (string) ($customer['id'] ?? '');

        if ($customerEcomId !== '') {
            $mapping = $this->mappingForCustomerEcomId($customerEcomId, $ecomDriver);
            if ($mapping?->erp_id) {
                return $this->normalizePartnerReference($mapping->erp_id);
            }
        }

        $byDirectory = $this->resolvePartnerIdFromSyncedCustomerDirectory($ecomOrder, $ecomDriver);
        if ($byDirectory) {
            return $byDirectory;
        }

        $byEmail = $this->resolvePartnerIdByEmail($ecomOrder, $erp, $ecomDriver);
        if ($byEmail) {
            return $byEmail;
        }

        return $this->resolvePartnerIdFromSingleSyncedCustomer($ecomDriver);
    }

    /** @param  array<string, mixed>  $ecomOrder */
    public function resolveOrderEmail(array $ecomOrder): string
    {
        $customer = $this->orderCustomer($ecomOrder);
        $billing  = $this->orderAddress($ecomOrder, 'billing');
        $shipping = $this->orderAddress($ecomOrder, 'shipping');

        foreach ([
            $ecomOrder['email'] ?? null,
            $ecomOrder['contactEmail'] ?? null,
            $customer['email'] ?? null,
            $customer['defaultEmailAddress']['emailAddress'] ?? null,
            $billing['email'] ?? null,
            $shipping['email'] ?? null,
        ] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '') {
                return $email;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<string>
     */
    public function orderCustomerNameCandidates(array $order): array
    {
        $candidates = [];

        foreach (['billing', 'shipping'] as $kind) {
            $addr  = $this->orderAddress($order, $kind);
            $first = strtolower(trim((string) ($addr['first_name'] ?? $addr['firstName'] ?? '')));
            $last  = strtolower(trim((string) ($addr['last_name'] ?? $addr['lastName'] ?? '')));
            $full  = trim("{$first} {$last}");

            if ($first !== '') {
                $candidates[] = $first;
            }
            if ($full !== '') {
                $candidates[] = $full;
            }
        }

        $customer = $this->orderCustomer($order);
        foreach (['first_name', 'firstName', 'displayName', 'name'] as $key) {
            $value = strtolower(trim((string) ($customer[$key] ?? '')));
            if ($value !== '') {
                $candidates[] = $value;
            }
        }

        return array_values(array_unique($candidates));
    }

    /** @param  array<string, mixed>  $ecomOrder */
    private function findSyncedCustomerMappingForOrder(array $ecomOrder, string $ecomDriver): ?SyncMapping
    {
        $customer       = $this->orderCustomer($ecomOrder);
        $customerEcomId = (string) ($customer['id'] ?? '');

        if ($customerEcomId !== '') {
            $mapping = $this->mappingForCustomerEcomId($customerEcomId, $ecomDriver);
            if ($mapping) {
                return $mapping;
            }
        }

        $email = strtolower($this->resolveOrderEmail($ecomOrder));
        if ($email !== '') {
            foreach ($this->syncedCustomerMappings($ecomDriver) as $mapping) {
                $payload = $mapping->payload();
                if (!is_array($payload)) {
                    continue;
                }

                $mappedEmail = strtolower(trim((string) ($payload['email'] ?? '')));
                if ($mappedEmail !== '' && $mappedEmail === $email) {
                    return $mapping;
                }
            }
        }

        $nameCandidates = $this->orderCustomerNameCandidates($ecomOrder);
        $phoneCandidates = $this->orderPhoneCandidates($ecomOrder);

        if ($nameCandidates === [] && $phoneCandidates === []) {
            return null;
        }

        foreach ($this->syncedCustomerMappings($ecomDriver) as $mapping) {
            if ($this->mappingMatchesOrderIdentity($mapping, $nameCandidates, $phoneCandidates)) {
                return $mapping;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $ecomOrder */
    private function resolvePartnerIdFromSyncedCustomerDirectory(array $ecomOrder, string $ecomDriver): int|string|null
    {
        $mapping = $this->findSyncedCustomerMappingForOrder($ecomOrder, $ecomDriver);

        return $mapping?->erp_id
            ? $this->normalizePartnerReference($mapping->erp_id)
            : null;
    }

    /** @param  array<string, mixed>  $ecomOrder */
    private function resolvePartnerIdByEmail(array $ecomOrder, ErpInterface $erp, string $ecomDriver): int|string|null
    {
        $email = strtolower($this->resolveOrderEmail($ecomOrder));
        if ($email === '') {
            return null;
        }

        $found = $erp->findCustomerByEmail($email);
        if (is_array($found) && !empty($found['id'])) {
            return $this->normalizePartnerReference($found['id']);
        }

        foreach ($this->syncedCustomerMappings($ecomDriver) as $mapping) {
            $payload = $mapping->payload();
            if (!is_array($payload)) {
                continue;
            }

            $mappedEmail = strtolower(trim((string) ($payload['email'] ?? '')));
            if ($mappedEmail !== '' && $mappedEmail === $email) {
                return $this->normalizePartnerReference($mapping->erp_id);
            }
        }

        return null;
    }

    private function resolvePartnerIdFromSingleSyncedCustomer(string $ecomDriver): int|string|null
    {
        $mappings = $this->syncedCustomerMappings($ecomDriver);
        if ($mappings->count() !== 1) {
            return null;
        }

        $mapping = $mappings->first();
        if (!$mapping?->erp_id) {
            return null;
        }

        Log::info('SalesOrderCustomerResolver: using sole synced customer for order without PII', [
            'erp_id'  => $mapping->erp_id,
            'ecom_id' => $mapping->ecom_id,
        ]);

        return $this->normalizePartnerReference($mapping->erp_id);
    }

    /** @return \Illuminate\Support\Collection<int, SyncMapping> */
    private function syncedCustomerMappings(string $ecomDriver)
    {
        return SyncMapping::query()
            ->where('entity_type', 'customer')
            ->where(function ($q) use ($ecomDriver) {
                $q->where('ecom_driver', $ecomDriver)
                    ->orWhereNull('ecom_driver');
            })
            ->whereNotNull('erp_id')
            ->where('erp_id', '!=', '')
            ->get();
    }

    private function mappingForCustomerEcomId(string $customerEcomId, string $ecomDriver): ?SyncMapping
    {
        return SyncMapping::query()
            ->where('entity_type', 'customer')
            ->where('ecom_id', $customerEcomId)
            ->whereNotNull('erp_id')
            ->where('erp_id', '!=', '')
            ->where(function ($q) use ($ecomDriver) {
                $q->where('ecom_driver', $ecomDriver)
                    ->orWhereNull('ecom_driver');
            })
            ->first();
    }

    /** @param  list<string>  $nameCandidates
     * @param  list<string>  $phoneCandidates
     */
    private function mappingMatchesOrderIdentity(SyncMapping $mapping, array $nameCandidates, array $phoneCandidates): bool
    {
        $payload = $mapping->payload();
        $payload = is_array($payload) ? $payload : [];

        if ($phoneCandidates !== []) {
            $phones = array_filter([
                strtolower(trim((string) ($payload['phone'] ?? ''))),
                strtolower(trim((string) ($payload['mobile_no'] ?? ''))),
            ]);

            foreach ($phoneCandidates as $candidate) {
                if ($candidate !== '' && in_array($candidate, $phones, true)) {
                    return true;
                }
            }
        }

        if ($nameCandidates === []) {
            return false;
        }

        $names = array_filter([
            strtolower(trim((string) $mapping->ecom_handle)),
            strtolower(trim((string) ($payload['firstName'] ?? $payload['first_name'] ?? ''))),
            strtolower(trim((string) ($payload['name'] ?? $payload['customer_name'] ?? ''))),
            strtolower(trim((string) $mapping->erp_id)),
        ]);

        foreach ($nameCandidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            foreach ($names as $name) {
                if ($name === '') {
                    continue;
                }

                if ($candidate === $name
                    || str_starts_with($name, $candidate . ' ')
                    || str_starts_with($candidate, $name . ' ')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function orderCustomer(array $order): array
    {
        return is_array($order['customer'] ?? null) ? $order['customer'] : [];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function orderAddress(array $order, string $kind): array
    {
        $snake = $kind === 'billing' ? 'billing_address' : 'shipping_address';
        $camel = $kind === 'billing' ? 'billingAddress' : 'shippingAddress';

        foreach ([$snake, $camel] as $key) {
            if (is_array($order[$key] ?? null)) {
                return $order[$key];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $order
     * @return list<string>
     */
    private function orderPhoneCandidates(array $order): array
    {
        $phones = [];

        foreach (['billing', 'shipping'] as $kind) {
            $addr = $this->orderAddress($order, $kind);
            $raw  = preg_replace('/\D+/', '', (string) ($addr['phone'] ?? ''));
            if ($raw !== '') {
                $phones[] = $raw;
            }
        }

        $customer = $this->orderCustomer($order);
        $raw      = preg_replace('/\D+/', '', (string) ($customer['phone'] ?? ''));
        if ($raw !== '') {
            $phones[] = $raw;
        }

        return array_values(array_unique($phones));
    }

    private function normalizePartnerReference(mixed $value): int|string|null
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_array($value)) {
            $value = $value[0] ?? $value[1] ?? null;
        }

        $reference = ltrim(trim((string) $value), '#');
        if ($reference === '') {
            return null;
        }

        return is_numeric($reference) ? (int) $reference : $reference;
    }
}
