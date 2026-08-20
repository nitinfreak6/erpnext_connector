<?php

namespace App\Services\Shopify;

use App\Services\Config\NestedFieldResolver;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

/**
 * Shopify customers — GraphQL wire protocol with config-driven payloads.
 *
 * Fetch: generic GraphQL decode (camelCase nested arrays).
 * Push:  FieldMappingService::buildErpToEcomCustomerPayload() → CustomerInput.
 */
class ShopifyCustomerService
{
    /** @var array<int, array<string, mixed>> */
    private array $wireLog = [];

    /** @var list<string> */
    private const STRUCTURAL = ['id', 'updatedAt', 'createdAt'];

    /**
     * Customer query/output fields — not writable on CustomerInput mutations.
     * Often picked from fetch samples when sync mode is bidirectional.
     *
     * @var list<string>
     */
    private const READ_ONLY = [
        'amountSpent', 'amount_spent',
        'numberOfOrders', 'number_of_orders',
        'state',
        'verifiedEmail', 'verified_email',
        'validEmailAddress', 'valid_email_address',
        'lifetimeDuration', 'lifetime_duration',
        'displayName', 'display_name',
        'canDelete', 'can_delete',
        'createdAt', 'created_at',
        'updatedAt', 'updated_at',
        'lastOrder', 'last_order',
        'orders', 'statistics',
        'image', 'market', 'mergeable',
        'productSubscriberStatus', 'product_subscriber_status',
    ];

    /** Legacy REST-style keys → GraphQL CustomerInput keys. */
    private const INPUT_KEY_ALIASES = [
        'first_name'  => 'firstName',
        'last_name'   => 'lastName',
        'country'     => 'countryCode',
        'country_code' => 'countryCode',
        'province'    => 'provinceCode',
        'province_code' => 'provinceCode',
    ];

    public function __construct(
        private readonly ShopifyGraphQLService $graphql,
        private readonly NestedFieldResolver $fields,
        private readonly FieldMappingService $fieldMapping,
        private readonly SettingsService $settings,
    ) {}

    // ── Fragment ─────────────────────────────────────────────────────────

    private function customerFragment(): string
    {
        return <<<'GQL'
        fragment CustomerFields on Customer {
            id
            firstName
            lastName
            email
            phone
            note
            tags
            state
            verifiedEmail
            updatedAt
            createdAt
            emailMarketingConsent { marketingState }
            metafields(first: 20) {
                edges {
                    node {
                        id
                        namespace
                        key
                        value
                        type
                    }
                }
            }
            defaultAddress {
                id
                address1 address2
                city zip
                countryCodeV2
                provinceCode
                phone
            }
            addresses(first: 5) {
                address1 address2
                city zip
                countryCodeV2
                provinceCode
                phone
            }
        }
        GQL;
    }

    // ── Public API ───────────────────────────────────────────────────────

    public function create(array $customerData): array
    {
        if (empty($customerData['email']) || $customerData['email'] === false) {
            throw new \RuntimeException('Shopify customerCreate: email is required but missing or empty for this customer.');
        }

        [$input, $address] = $this->partitionCustomerPayload($customerData);

        $mutation = $this->customerFragment() . <<<'GQL'
        mutation customerCreate($input: CustomerInput!) {
            customerCreate(input: $input) {
                customer { ...CustomerFields }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->gql('customerCreate', $mutation, ['input' => $input], $input);
        $errors = $this->graphql->extractUserErrors($data, 'customerCreate');

        if (!empty($errors)) {
            $isDuplicateEmail = count($errors) === 1
                && stripos($errors[0], 'email') !== false
                && stripos($errors[0], 'already been taken') !== false;

            if ($isDuplicateEmail && !empty($customerData['email'])) {
                Log::info("ShopifyCustomerService::create — email already exists, falling back to update for: {$customerData['email']}");

                $existing = $this->findByEmail($customerData['email']);
                if ($existing && !empty($existing['id'])) {
                    return $this->update((string) $existing['id'], $customerData);
                }
            }

            throw new \RuntimeException('Shopify customerCreate errors: ' . implode('; ', $errors));
        }

        $customer = $this->decodeCustomer($data['customerCreate']['customer']);
        $this->syncDefaultAddress((string) $customer['id'], $address, $customerData);

        return $customer;
    }

    public function update(string $shopifyCustomerId, array $customerData): array
    {
        [$input, $address] = $this->partitionCustomerPayload($customerData);
        $input['id']       = $this->toGid('Customer', $shopifyCustomerId);

        $mutation = $this->customerFragment() . <<<'GQL'
        mutation customerUpdate($input: CustomerInput!) {
            customerUpdate(input: $input) {
                customer { ...CustomerFields }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->gql('customerUpdate', $mutation, ['input' => $input], $input);
        $errors = $this->graphql->extractUserErrors($data, 'customerUpdate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify customerUpdate errors: ' . implode('; ', $errors));
        }

        $customer = $this->decodeCustomer($data['customerUpdate']['customer']);
        $this->syncDefaultAddress($shopifyCustomerId, $address, $customerData);

        return $customer;
    }

    public function findByEmail(string $email): ?array
    {
        $query = $this->customerFragment() . <<<'GQL'
        query findCustomerByEmail($query: String!) {
            customers(first: 1, query: $query) {
                edges { node { ...CustomerFields } }
            }
        }
        GQL;

        try {
            $data     = $this->gql('findCustomerByEmail', $query, ['query' => "email:{$email}"]);
            $edges    = $data['customers']['edges'] ?? [];
            $customer = $edges[0]['node'] ?? null;

            return $customer ? $this->decodeCustomer($customer) : null;
        } catch (\Throwable $e) {
            Log::warning("ShopifyCustomerService::findByEmail failed: " . $e->getMessage());
            return null;
        }
    }

    public function list(array $filters = []): array
    {
        $limit        = (int) ($filters['limit'] ?? 250);
        $cursor       = $filters['cursor'] ?? null;
        $updatedAtMin = $filters['updated_at_min'] ?? null;

        $queryParts = [];
        if ($updatedAtMin) {
            $isoMin = \Carbon\Carbon::parse($updatedAtMin)->utc()->format('Y-m-d\TH:i:s\Z');
            $queryParts[] = "updated_at:>'{$isoMin}'";
        }
        $searchQuery = !empty($queryParts) ? implode(' AND ', $queryParts) : null;

        $query = $this->customerFragment() . ($searchQuery
            ? <<<'GQL'
            query ListCustomers($first: Int!, $after: String, $query: String) {
                customers(first: $first, after: $after, query: $query) {
                    edges { node { ...CustomerFields } cursor }
                    pageInfo { hasNextPage endCursor }
                }
            }
            GQL
            : <<<'GQL'
            query ListCustomers($first: Int!, $after: String) {
                customers(first: $first, after: $after) {
                    edges { node { ...CustomerFields } cursor }
                    pageInfo { hasNextPage endCursor }
                }
            }
            GQL);

        $variables = ['first' => $limit];
        if ($cursor) {
            $variables['after'] = $cursor;
        }
        if ($searchQuery) {
            $variables['query'] = $searchQuery;
        }

        try {
            $result = $this->graphql->query($query, $variables);
            $edges  = $result['customers']['edges'] ?? [];

            $customers = [];
            foreach ($edges as $edge) {
                $customers[] = $this->decodeCustomer($edge['node']);
            }

            return [
                'customers' => $customers,
                'pageInfo'  => $result['customers']['pageInfo'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error("ShopifyCustomerService::list failed: " . $e->getMessage());
            return ['customers' => [], 'pageInfo' => null];
        }
    }

    /**
     * Odoo partner → config-driven GraphQL CustomerInput payload.
     */
    public function buildPayload(array $partner): array
    {
        return $this->fieldMapping->buildErpToEcomCustomerPayload(
            $partner,
            $this->settings->ecomDriver(),
            $this->settings->erpDriver()
        );
    }

    // ── GraphQL input builder (config paths → CustomerInput) ─────────────

    private function buildGraphQLInput(array $payload): array
    {
        [$profile] = $this->partitionCustomerPayload($payload);

        return $profile;
    }

    /**
     * CustomerInput accepts profile fields only — addresses use customerAddressCreate/Update.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>|null}
     */
    private function partitionCustomerPayload(array $payload): array
    {
        $prepared = $this->prepareGraphQLInput($payload);
        $address  = $this->extractAddressInput($prepared);

        unset($prepared['defaultAddress'], $prepared['addresses']);

        return [$this->finalizeCustomerInput($prepared), $address];
    }

    /** @param array<string, mixed> $prepared */
    private function extractAddressInput(array $prepared): ?array
    {
        $raw = null;

        if (!empty($prepared['defaultAddress']) && is_array($prepared['defaultAddress'])) {
            $raw = $prepared['defaultAddress'];
        } elseif (!empty($prepared['addresses']) && is_array($prepared['addresses'])) {
            $addresses = $prepared['addresses'];
            if ($this->isAssociativeAddressMap($addresses)) {
                $addresses = [$addresses];
            }

            $raw = is_array($addresses[0] ?? null) ? $addresses[0] : null;
        }

        if ($raw === null) {
            return null;
        }

        $normalized = $this->normalizeAddressInput($raw);

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareGraphQLInput(array $payload): array
    {
        $output = [];

        foreach ($payload as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_')) {
                continue;
            }

            if (in_array($key, self::STRUCTURAL, true)) {
                continue;
            }

            if ($this->isReadOnlyCustomerField($key)) {
                Log::debug("ShopifyCustomerService: skipping read-only Customer field in push payload: {$key}");
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            $graphKey = self::INPUT_KEY_ALIASES[$key] ?? $key;

            if ($this->isReadOnlyCustomerField($graphKey)) {
                Log::debug("ShopifyCustomerService: skipping read-only Customer field in push payload: {$graphKey}");
                continue;
            }

            if (str_contains($graphKey, '.')) {
                $this->fields->set($output, $graphKey, $value);
            } else {
                $output[$graphKey] = $value;
            }
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function finalizeCustomerInput(array $input): array
    {
        unset($input['defaultAddress'], $input['addresses']);

        return $this->pruneReadOnlyCustomerFields($input);
    }

    /** @param array<string, mixed>|null $address */
    private function syncDefaultAddress(string $shopifyCustomerId, ?array $address, array $customerData = []): void
    {
        if ($address === null || $address === []) {
            return;
        }

        $customerGid         = $this->toGid('Customer', $shopifyCustomerId);
        $existingAddressGid  = $this->resolveDefaultAddressGid($shopifyCustomerId, $customerData);

        if ($existingAddressGid !== null) {
            $this->updateCustomerAddress($customerGid, $existingAddressGid, $address);
            return;
        }

        $this->createCustomerAddress($customerGid, $address);
    }

    private function resolveDefaultAddressGid(string $shopifyCustomerId, array $customerData): ?string
    {
        foreach ([
            $customerData['defaultAddress']['id'] ?? null,
            $customerData['_shopify_default_address_id'] ?? null,
        ] as $id) {
            if ($id !== null && $id !== '') {
                return $this->toAddressGid((string) $id);
            }
        }

        try {
            $query = <<<'GQL'
            query customerDefaultAddress($id: ID!) {
                customer(id: $id) {
                    defaultAddress { id }
                }
            }
            GQL;

            $data = $this->graphql->query($query, [
                'id' => $this->toGid('Customer', $shopifyCustomerId),
            ]);

            $gid = $data['customer']['defaultAddress']['id'] ?? null;

            return is_string($gid) && $gid !== '' ? $gid : null;
        } catch (\Throwable $e) {
            Log::debug('ShopifyCustomerService: could not resolve default address id: ' . $e->getMessage());

            return null;
        }
    }

    /** @param array<string, mixed> $address */
    private function createCustomerAddress(string $customerGid, array $address): void
    {
        $mutation = <<<'GQL'
        mutation customerAddressCreate($customerId: ID!, $address: MailingAddressInput!, $setAsDefault: Boolean) {
            customerAddressCreate(customerId: $customerId, address: $address, setAsDefault: $setAsDefault) {
                address { id address1 city countryCodeV2 provinceCode zip phone }
                userErrors { field message }
            }
        }
        GQL;

        $variables = [
            'customerId'   => $customerGid,
            'address'      => $address,
            'setAsDefault' => true,
        ];

        $data   = $this->gql('customerAddressCreate', $mutation, $variables, $variables);
        $errors = $this->graphql->extractUserErrors($data, 'customerAddressCreate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify customerAddressCreate errors: ' . implode('; ', $errors));
        }
    }

    /** @param array<string, mixed> $address */
    private function updateCustomerAddress(string $customerGid, string $addressGid, array $address): void
    {
        $mutation = <<<'GQL'
        mutation customerAddressUpdate($customerId: ID!, $addressId: ID!, $address: MailingAddressInput!, $setAsDefault: Boolean) {
            customerAddressUpdate(customerId: $customerId, addressId: $addressId, address: $address, setAsDefault: $setAsDefault) {
                address { id address1 city countryCodeV2 provinceCode zip phone }
                userErrors { field message }
            }
        }
        GQL;

        $variables = [
            'customerId'   => $customerGid,
            'addressId'    => $addressGid,
            'address'      => $address,
            'setAsDefault' => true,
        ];

        $data   = $this->gql('customerAddressUpdate', $mutation, $variables, $variables);
        $errors = $this->graphql->extractUserErrors($data, 'customerAddressUpdate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify customerAddressUpdate errors: ' . implode('; ', $errors));
        }
    }

    private function toAddressGid(string $id): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/MailingAddress/{$id}?model_name=CustomerAddress";
    }

    /** @param array<string, mixed> $input */
    private function pruneReadOnlyCustomerFields(array $input): array
    {
        foreach (array_keys($input) as $key) {
            if (!$this->isReadOnlyCustomerField($key)) {
                continue;
            }

            Log::debug("ShopifyCustomerService: removed read-only Customer field from GraphQL input: {$key}");
            unset($input[$key]);
        }

        return $input;
    }

    private function isReadOnlyCustomerField(string $key): bool
    {
        if (in_array($key, self::READ_ONLY, true)) {
            return true;
        }

        // Nested paths from field config, e.g. amountSpent.amount
        $root = explode('.', $key, 2)[0];

        return in_array($root, self::READ_ONLY, true);
    }

    /**
     * True when config mapped addresses.* into one object (not a list of addresses).
     *
     * @param  array<string, mixed>  $data
     */
    private function isAssociativeAddressMap(array $data): bool
    {
        if ($data === [] || array_is_list($data)) {
            return false;
        }

        static $addressKeys = [
            'address1', 'address2', 'address', 'city', 'zip',
            'country', 'countryCode', 'countryCodeV2', 'country_code',
            'province', 'provinceCode', 'province_code', 'phone',
        ];

        foreach (array_keys($data) as $key) {
            if (in_array($key, $addressKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $addr */
    private function normalizeAddressInput(array $addr): array
    {
        $mapped = [];

        $pairs = [
            'address1'     => ['address1', 'address'],
            'address2'     => ['address2'],
            'city'         => ['city'],
            'zip'          => ['zip'],
            // Prefer explicit ISO paths from field config before generic `country`
            // (which may hold country_id many2one and must not beat country_code).
            'countryCode'  => ['countryCodeV2', 'country_code', 'countryCode', 'country'],
            'provinceCode' => ['provinceCode', 'province_code', 'province'],
            'phone'        => ['phone'],
        ];

        foreach ($pairs as $target => $sources) {
            foreach ($sources as $source) {
                if (!array_key_exists($source, $addr)) {
                    continue;
                }

                $scalar = $this->coerceAddressScalar($addr[$source]);
                if ($scalar === null || $scalar === '') {
                    continue;
                }

                if ($target === 'countryCode' && !$this->isShopifyCountryCode($scalar)) {
                    continue;
                }

                if ($target === 'provinceCode' && !$this->isShopifyProvinceCode($scalar)) {
                    continue;
                }

                $mapped[$target] = $scalar;
                break;
            }
        }

        return $mapped;
    }

    /** Odoo many2one [id, "Label"] and scalars → string for MailingAddressInput. */
    private function coerceAddressScalar(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_array($value)) {
            if ($value === []) {
                return null;
            }

            // Many2one tuple [database_id, "Label"] — never use the numeric id as a code.
            if (array_key_exists(0, $value) && array_key_exists(1, $value) && is_numeric($value[0])) {
                return null;
            }

            if (array_key_exists(1, $value) && is_string($value[1]) && $value[1] !== '') {
                return $value[1];
            }

            if (array_key_exists(0, $value) && !is_array($value[0])) {
                return (string) $value[0];
            }

            return null;
        }

        return (string) $value;
    }

    private function isShopifyCountryCode(string $value): bool
    {
        return (bool) preg_match('/^[A-Z]{2}$/', strtoupper(trim($value)));
    }

    private function isShopifyProvinceCode(string $value): bool
    {
        $v = strtoupper(trim($value));

        if ($v === '' || ctype_digit($v)) {
            return false;
        }

        return (bool) preg_match('/^[A-Z0-9-]{1,6}$/', $v);
    }

    // ── GraphQL decode ───────────────────────────────────────────────────

    private function decodeCustomer(array $node): array
    {
        $decoded = $this->decodeGraphqlValue($node);
        $decoded = is_array($decoded) ? $decoded : [];

        if (!empty($decoded['tags']) && is_array($decoded['tags'])) {
            $decoded['tags'] = implode(', ', $decoded['tags']);
        }

        // Sync cursor helpers (FetchEcomCustomersOnlyJob reads either key).
        if (!empty($decoded['updatedAt'])) {
            $decoded['updated_at'] = $decoded['updatedAt'];
        }

        $decoded = $this->enrichFetchedCustomer($decoded);

        return $decoded;
    }

    /**
     * Mirror Shopify read-only address keys so field config paths like
     * addresses.countryCode resolve (GraphQL returns countryCodeV2).
     *
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function enrichFetchedCustomer(array $customer): array
    {
        if (!empty($customer['defaultAddress']) && is_array($customer['defaultAddress'])) {
            $customer['defaultAddress'] = $this->enrichFetchedAddress($customer['defaultAddress']);
        }

        if (!empty($customer['addresses']) && is_array($customer['addresses'])) {
            $customer['addresses'] = array_map(
                fn ($addr) => is_array($addr) ? $this->enrichFetchedAddress($addr) : $addr,
                $customer['addresses']
            );
        }

        return $customer;
    }

    /** @param array<string, mixed> $address */
    private function enrichFetchedAddress(array $address): array
    {
        $iso = $address['countryCodeV2'] ?? $address['country_code'] ?? null;

        if ($iso !== null && $iso !== '') {
            if (!isset($address['countryCode'])) {
                $address['countryCode'] = $iso;
            }
            if (!isset($address['country_code'])) {
                $address['country_code'] = $iso;
            }
        }

        $province = $address['provinceCode'] ?? $address['province_code'] ?? null;

        if ($province !== null && $province !== '') {
            if (!isset($address['province'])) {
                $address['province'] = $province;
            }
            if (!isset($address['province_code'])) {
                $address['province_code'] = $province;
            }
        }

        return $address;
    }

    private function decodeGraphqlValue(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, 'gid://')) {
            return $this->fromGid($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists('edges', $value) && is_array($value['edges'])) {
            return array_map(
                fn ($edge) => $this->decodeGraphqlValue($edge['node'] ?? $edge),
                $value['edges']
            );
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->decodeGraphqlValue($item);
        }

        return $out;
    }

    private function toGid(string $type, string $id): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return "gid://shopify/{$type}/{$id}";
    }

    private function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }

    public function delete(string|int $id): void
    {
        $gid = $this->toGid('Customer', (string) $id);

        $mutation = <<<'GQL'
        mutation customerDelete($input: CustomerDeleteInput!) {
            customerDelete(input: $input) {
                deletedCustomerId
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->gql('customerDelete', $mutation, ['input' => ['id' => $gid]]);
        $errors = $data['customerDelete']['userErrors'] ?? [];

        if ($errors !== []) {
            throw new \RuntimeException('Shopify customerDelete failed: ' . ($errors[0]['message'] ?? 'unknown'));
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function takeWireLog(): array
    {
        $log           = $this->wireLog;
        $this->wireLog = [];

        return $log;
    }

    /** @return array<string, mixed> */
    private function gql(string $action, string $query, array $variables = [], ?array $wireInput = null): array
    {
        $this->recordWire($action, $query, $variables, $wireInput);
        $data = $this->graphql->query($query, $variables);
        $this->recordResponse($data);

        return $data;
    }

    private function recordWire(string $action, string $query, array $variables, ?array $wireInput = null): void
    {
        $entry = [
            'action'    => $action,
            'query'     => $query,
            'variables' => $variables,
            'endpoint'  => 'graphql.json',
        ];

        if ($wireInput !== null) {
            $entry['wire_input'] = $wireInput;
        }

        $this->wireLog[] = $entry;
    }

    private function recordResponse(mixed $response): void
    {
        if ($this->wireLog !== []) {
            $this->wireLog[count($this->wireLog) - 1]['response'] = $response;
        }
    }
}
