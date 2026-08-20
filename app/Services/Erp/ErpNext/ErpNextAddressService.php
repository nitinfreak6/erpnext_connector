<?php

namespace App\Services\Erp\ErpNext;

use App\Services\FieldMappingService;
use App\Services\SettingsService;

class ErpNextAddressService
{
    public function __construct(private readonly ErpNextService $api) {}

    /** @param  array<string, mixed>  $ecomCustomer */
    public function upsertForCustomer(array $ecomCustomer, string $customerName): ?string
    {
        $payload = app(FieldMappingService::class)->buildErpCustomerScopedPayload(
            $this->withAddressContext($ecomCustomer),
            'address',
        );

        if ($payload === [] || !$this->hasMeaningfulAddress($payload)) {
            return null;
        }

        $payload['doctype'] = 'Address';
        $payload['links']   = [[
            'link_doctype' => 'Customer',
            'link_name'    => $customerName,
        ]];

        $existing = $this->findLinkedToCustomer($customerName);
        if ($existing !== null) {
            unset($payload['doctype']);
            $this->api->updateDoc('Address', $existing, $payload);

            return $existing;
        }

        $result = $this->api->insertDoc('Address', $payload);

        return (string) ($result['name'] ?? '');
    }

    /** @param  array<string, mixed>  $ecomCustomer */
    private function withAddressContext(array $ecomCustomer): array
    {
        if (!empty($ecomCustomer['defaultAddress']) && is_array($ecomCustomer['defaultAddress'])) {
            return $ecomCustomer;
        }

        $first = $ecomCustomer['addresses'][0] ?? null;
        if (is_array($first)) {
            $ecomCustomer['defaultAddress'] = $first;
        }

        return $ecomCustomer;
    }

    /** @param  array<string, mixed>  $payload */
    private function hasMeaningfulAddress(array $payload): bool
    {
        foreach (['address_line1', 'address_line2', 'city', 'pincode', 'country'] as $key) {
            if (!empty($payload[$key])) {
                return true;
            }
        }

        return false;
    }

    private function findLinkedToCustomer(string $customerName): ?string
    {
        if ($customerName === '') {
            return null;
        }

        try {
            $rows = $this->api->listDocs(
                'Address',
                [
                    ['Dynamic Link', 'link_doctype', '=', 'Customer'],
                    ['Dynamic Link', 'link_name', '=', $customerName],
                ],
                ['name', 'address_type'],
                limit: 10,
            );
        } catch (\Throwable) {
            return null;
        }

        if ($rows === []) {
            return null;
        }

        foreach (['Shipping', 'Billing', 'Office'] as $preferredType) {
            foreach ($rows as $row) {
                if (strcasecmp((string) ($row['address_type'] ?? ''), $preferredType) === 0) {
                    $name = trim((string) ($row['name'] ?? ''));

                    return $name !== '' ? $name : null;
                }
            }
        }

        $name = trim((string) ($rows[0]['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /** @return array<string, mixed>|null */
    public function fetchPrimaryForCustomer(string $customerName): ?array
    {
        $linkedName = $this->findLinkedToCustomer($customerName);
        if ($linkedName === null) {
            return null;
        }

        $fields = $this->api->fetchFieldsForEntity('customer', 'address', 'erp_to_ecom');

        return $this->api->getDoc('Address', $linkedName, $fields);
    }
}
