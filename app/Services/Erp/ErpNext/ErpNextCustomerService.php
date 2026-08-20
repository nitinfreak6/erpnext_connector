<?php

namespace App\Services\Erp\ErpNext;

use App\Models\ProductFieldConfig;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Support\Collection;

class ErpNextCustomerService
{
    public function __construct(
        private readonly ErpNextService $api,
        private readonly ErpNextAddressService $addresses,
        private readonly ErpNextContactService $contacts,
    ) {}

    /** @return list<array<string, mixed>> */
    public function getModifiedSince(string $writeDate): array
    {
        $filters = [['modified', '>', $this->normalizeDate($writeDate)]];

        foreach ($this->customListFilters() as $filter) {
            $filters[] = $filter;
        }

        $rows = $this->api->listDocs(
            'Customer',
            $filters,
            $this->api->fetchFieldsForEntity('customer', 'default', forList: true),
            limit: 200,
        );

        return array_map(fn ($r) => $this->normalizeDocument($r), $rows);
    }

    /** @return array<string, mixed>|null */
    public function getByName(string $name): ?array
    {
        $doc = $this->api->getDoc('Customer', $name);

        return $doc ? $this->normalizeDocument($doc) : null;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        $emailField = $this->customerErpFieldForEcom('email', 'ecom_to_erp')
            ?? $this->customerErpFieldForEcom('email', 'erp_to_ecom');

        if ($emailField === null) {
            throw new \RuntimeException(
                'Customer lookup requires a field config mapping email to an erp_field (e.g. email_id).'
            );
        }

        $rows = $this->api->listDocs('Customer', [
            [$emailField, '=', $email],
        ], $this->api->fetchFieldsForEntity('customer', 'default', forList: true), limit: 1);

        return isset($rows[0]) ? $this->normalizeDocument($rows[0]) : null;
    }

    /** @param  array<string, mixed>  $hints  Optional firstName, lastName, phone from order */
    public function findOrCreateByReference(mixed $reference, array $hints = []): string
    {
        if (is_array($reference)) {
            $reference = $reference[0] ?? $reference[1] ?? '';
        }

        $reference = trim((string) $reference);
        if ($reference === '') {
            throw new \RuntimeException('Sales Order requires a customer.');
        }

        if ($this->getByName($reference) !== null) {
            return $reference;
        }

        if (str_contains($reference, '@')) {
            $found = $this->findByEmail($reference);
            if ($found !== null) {
                return (string) ($found['id'] ?? $reference);
            }

            $emailField = $this->customerErpFieldForEcom('email', 'ecom_to_erp');
            $nameField  = $this->customerErpFieldForEcom('firstName', 'ecom_to_erp');
            $phoneField = $this->customerErpFieldForEcom('phone', 'ecom_to_erp');

            if ($emailField === null) {
                throw new \RuntimeException(
                    'Customer auto-create requires ecom→erp field config mapping email to erp_field (e.g. email_id).'
                );
            }

            $name = trim(($hints['customer_name'] ?? $hints['firstName'] ?? $hints['first_name'] ?? '')
                . ' ' . ($hints['lastName'] ?? $hints['last_name'] ?? ''));

            $payload = array_filter([
                $emailField => $reference,
                $nameField  => $name !== '' ? $name : explode('@', $reference)[0],
                $phoneField => $hints['mobile_no'] ?? $hints['phone'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            return $this->create($payload);
        }

        return $reference;
    }

    /** @param  array<string, mixed>  $data */
    public function create(array $data): string
    {
        $payload = array_merge($this->customCreateDefaults(), $this->fromMapped($data));
        $payload['doctype'] = 'Customer';

        $result = $this->api->insertDoc('Customer', $payload);

        return (string) ($result['name'] ?? '');
    }

    /** @param  array<string, mixed>  $data */
    public function update(string $name, array $data): bool
    {
        unset($data['id'], $data['name']);
        $payload = $this->fromMapped($data);

        if ($payload === []) {
            return true;
        }

        $this->api->updateDoc('Customer', $name, $payload);

        return true;
    }

    /**
     * Create/update linked Address + Contact from e-commerce customer fetch payload.
     *
     * @param  array<string, mixed>  $ecomCustomer
     * @return array{address_id?: string, contact_id?: string}
     */
    public function syncLinkedDocumentsFromEcom(array $ecomCustomer, string $customerName): array
    {
        if ($customerName === '') {
            return [];
        }

        $out = [];

        $addressId = $this->addresses->upsertForCustomer($ecomCustomer, $customerName);
        if ($addressId !== null && $addressId !== '') {
            $out['address_id'] = $addressId;
        }

        $contactId = $this->contacts->upsertForCustomer($ecomCustomer, $customerName);
        if ($contactId !== null && $contactId !== '') {
            $out['contact_id'] = $contactId;
        }

        return $out;
    }

    public function delete(string $name): bool
    {
        return $this->api->deleteDoc('Customer', $name);
    }

    /** @param  array<string, mixed>  $row */
    public function normalizeDocument(array $row): array
    {
        $row['id']         = (string) ($row['name'] ?? '');
        $row['write_date'] = $this->normalizeDate($row['modified'] ?? '');

        if (empty($row['email']) && !empty($row['email_id'])) {
            $row['email'] = $row['email_id'];
        }

        $customerName = (string) ($row['name'] ?? '');
        if ($customerName !== '') {
            $address = $this->addresses->fetchPrimaryForCustomer($customerName);
            if (is_array($address) && $address !== []) {
                $row['_address'] = $address;
            }

            $contact = $this->contacts->fetchPrimaryForCustomer($customerName);
            if (is_array($contact) && $contact !== []) {
                $row['_contact'] = $contact;
            }
        }

        return $row;
    }

    /** @param  array<string, mixed>  $data */
    private function fromMapped(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (str_starts_with((string) $key, '_')) {
                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: mixed}> */
    private function customListFilters(): array
    {
        $filters = [];

        foreach ($this->customDefaultConfigs() as $config) {
            if (!str_starts_with(trim((string) ($config->ecom_field ?? '')), '_filter_')) {
                continue;
            }

            $field = $this->configErpRoot($config);
            if ($field === '') {
                continue;
            }

            $filters[] = [$field, '=', $this->castListFilterValue($config->default_value)];
        }

        return $filters;
    }

    /** @return array<string, mixed> */
    private function customCreateDefaults(): array
    {
        $defaults = [];

        foreach ($this->customDefaultConfigs() as $config) {
            if (str_starts_with(trim((string) ($config->ecom_field ?? '')), '_filter_')) {
                continue;
            }

            $field = $this->configErpRoot($config);
            if ($field === '' || ($config->default_value ?? '') === '') {
                continue;
            }

            $defaults[$field] = $config->default_value;
        }

        return $defaults;
    }

    /** @return Collection<int, ProductFieldConfig> */
    private function customDefaultConfigs(): Collection
    {
        $settings = app(SettingsService::class);

        return app(FieldMappingService::class)->getMappings(
            'customer',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            'default',
            'ecom_to_erp',
        )->filter(fn (ProductFieldConfig $c) => ($c->field_type ?? '') === 'custom'
            && trim((string) ($c->erp_field ?? '')) !== ''
            && ($c->default_value ?? '') !== '');
    }

    private function customerErpFieldForEcom(string $ecomField, string $direction): ?string
    {
        $settings = app(SettingsService::class);
        $config   = app(FieldMappingService::class)->getMappings(
            'customer',
            $settings->ecomDriver(),
            $settings->erpDriver(),
            'default',
            $direction,
        )->first(fn (ProductFieldConfig $c) => trim((string) ($c->ecom_field ?? '')) === $ecomField);

        $root = $config ? $this->configErpRoot($config) : null;

        return $root !== '' ? $root : null;
    }

    private function configErpRoot(ProductFieldConfig $config): string
    {
        return trim(explode('.', (string) ($config->erp_field ?? $config->odoo_field ?? ''))[0]);
    }

    private function castListFilterValue(mixed $value): mixed
    {
        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return 1;
        }

        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return 0;
        }

        return is_numeric($value) ? $value + 0 : $value;
    }

    private function normalizeDate(string $value): string
    {
        return $value === '' ? '2000-01-01 00:00:00' : date('Y-m-d H:i:s', strtotime($value) ?: time());
    }
}
