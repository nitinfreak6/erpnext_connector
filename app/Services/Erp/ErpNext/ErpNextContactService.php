<?php

namespace App\Services\Erp\ErpNext;

use App\Services\FieldMappingService;

class ErpNextContactService
{
    public function __construct(private readonly ErpNextService $api) {}

    /** @param  array<string, mixed>  $ecomCustomer */
    public function upsertForCustomer(array $ecomCustomer, string $customerName): ?string
    {
        $payload = app(FieldMappingService::class)->buildErpCustomerScopedPayload(
            $ecomCustomer,
            'contact',
        );

        if ($payload === [] || !$this->hasMeaningfulContact($payload)) {
            return null;
        }

        $payload['doctype'] = 'Contact';
        $payload['links']   = [[
            'link_doctype' => 'Customer',
            'link_name'    => $customerName,
        ]];

        $existing = $this->findLinkedToCustomer($customerName);
        if ($existing !== null) {
            unset($payload['doctype']);
            $this->api->updateDoc('Contact', $existing, $payload);

            return $existing;
        }

        $result = $this->api->insertDoc('Contact', $payload);

        return (string) ($result['name'] ?? '');
    }

    /** @param  array<string, mixed>  $payload */
    private function hasMeaningfulContact(array $payload): bool
    {
        foreach (['first_name', 'last_name', 'email_id', 'mobile_no', 'phone'] as $key) {
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
                'Contact',
                [
                    ['Dynamic Link', 'link_doctype', '=', 'Customer'],
                    ['Dynamic Link', 'link_name', '=', $customerName],
                ],
                ['name'],
                limit: 1,
            );
        } catch (\Throwable) {
            return null;
        }

        $name = $rows[0]['name'] ?? null;

        return ($name !== null && $name !== '') ? (string) $name : null;
    }

    /** @return array<string, mixed>|null */
    public function fetchPrimaryForCustomer(string $customerName): ?array
    {
        $linkedName = $this->findLinkedToCustomer($customerName);
        if ($linkedName === null) {
            return null;
        }

        $fields = $this->api->fetchFieldsForEntity('customer', 'contact', 'erp_to_ecom');

        return $this->api->getDoc('Contact', $linkedName, $fields);
    }
}
