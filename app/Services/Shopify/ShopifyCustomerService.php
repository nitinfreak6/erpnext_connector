<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Log;

class ShopifyCustomerService
{
    public function __construct(private readonly ShopifyGraphQLService $graphql) {}

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
            emailMarketingConsent { marketingState }
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

    /**
     * Create a customer in Shopify.
     */
    public function create(array $customerData): array
    {
        // Shopify requires email for customerCreate — skip customers with no email
        if (empty($customerData['email']) || $customerData['email'] === false) {
            throw new \RuntimeException('Shopify customerCreate: email is required but missing or empty for this customer.');
        }

        $input = $this->buildGraphQLInput($customerData);

        $mutation = $this->customerFragment() . <<<'GQL'
        mutation customerCreate($input: CustomerInput!) {
            customerCreate(input: $input) {
                customer { ...CustomerFields }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->graphql->query($mutation, ['input' => $input]);
        $errors = $this->graphql->extractUserErrors($data, 'customerCreate');

        if (!empty($errors)) {
            // If the only error is a duplicate email, find the existing customer and update instead.
            $isDuplicateEmail = count($errors) === 1
                && stripos($errors[0], 'email') !== false
                && stripos($errors[0], 'already been taken') !== false;

            if ($isDuplicateEmail && !empty($customerData['email'])) {
                Log::info("ShopifyCustomerService::create — email already exists, falling back to update for: {$customerData['email']}");

                $existing = $this->findByEmail($customerData['email']);
                if ($existing) {
                    $existingId = $existing['id'] ?? null;
                    if ($existingId) {
                        return $this->update((string) $existingId, $customerData);
                    }
                }
            }

            throw new \RuntimeException('Shopify customerCreate errors: ' . implode('; ', $errors));
        }

        return $this->normalizeCustomer($data['customerCreate']['customer']);
    }

    /**
     * Update a customer in Shopify.
     */
    public function update(string $shopifyCustomerId, array $customerData): array
    {
        $input       = $this->buildGraphQLInput($customerData);
        $input['id'] = $this->toGid('Customer', $shopifyCustomerId);

        $mutation = $this->customerFragment() . <<<'GQL'
        mutation customerUpdate($input: CustomerInput!) {
            customerUpdate(input: $input) {
                customer { ...CustomerFields }
                userErrors { field message }
            }
        }
        GQL;

        $data   = $this->graphql->query($mutation, ['input' => $input]);
        $errors = $this->graphql->extractUserErrors($data, 'customerUpdate');

        if (!empty($errors)) {
            throw new \RuntimeException('Shopify customerUpdate errors: ' . implode('; ', $errors));
        }

        return $this->normalizeCustomer($data['customerUpdate']['customer']);
    }

    /**
     * Find a customer by email.
     */
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
            $data     = $this->graphql->query($query, ['query' => "email:{$email}"]);
            $edges    = $data['customers']['edges'] ?? [];
            $customer = $edges[0]['node'] ?? null;

            return $customer ? $this->normalizeCustomer($customer) : null;
        } catch (\Throwable $e) {
            Log::warning("ShopifyCustomerService::findByEmail failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * List customers with pagination
     */
    public function list(array $filters = []): array
    {
        $limit = $filters['limit'] ?? 250;
        $cursor = $filters['cursor'] ?? null;
        
        $query = $this->customerFragment() . <<<'GQL'
        query ListCustomers($first: Int!, $after: String) {
            customers(first: $first, after: $after) {
                edges {
                    node { ...CustomerFields }
                    cursor
                }
                pageInfo {
                    hasNextPage
                    endCursor
                }
            }
        }
        GQL;

        $variables = ['first' => $limit];
        if ($cursor) {
            $variables['after'] = $cursor;
        }

        try {
            $result = $this->graphql->query($query, $variables);
            $edges  = $result['customers']['edges'] ?? [];
            
            $customers = [];
            foreach ($edges as $edge) {
                $customers[] = $this->normalizeCustomer($edge['node']);
            }

            return [
                'customers' => $customers,
                'pageInfo' => $result['customers']['pageInfo'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error("ShopifyCustomerService::list failed: " . $e->getMessage());
            return ['customers' => [], 'pageInfo' => null];
        }
    }

    /**
     * Build Shopify customer payload from Odoo partner data.
     * Returns REST-style array — converted to GraphQL in create/update.
     */
    public function buildPayload(array $partner): array
    {
        $nameParts = explode(' ', $partner['name'], 2);

        // Odoo returns false for empty fields — normalise to empty string / null
        $email  = ($partner['email']  && $partner['email']  !== false) ? (string) $partner['email']  : null;
        $phone  = ($partner['phone']  && $partner['phone']  !== false) ? (string) $partner['phone']  :
                  (($partner['mobile'] && $partner['mobile'] !== false) ? (string) $partner['mobile'] : null);

        $payload = [
            'first_name'        => $nameParts[0],
            'last_name'         => $nameParts[1] ?? '',
            'email'             => $email,
            'phone'             => $phone,
            'accepts_marketing' => !($partner['opt_out'] ?? false),
        ];

        if (!empty($partner['street'])) {
            $payload['addresses'] = [[
                'address1' => $partner['street']   ?? '',
                'address2' => $partner['street2']  ?? '',
                'city'     => $partner['city']     ?? '',
                'zip'      => $partner['zip']      ?? '',
                'country'  => is_array($partner['country_id']) ? $partner['country_id'][1] : '',
                'province' => is_array($partner['state_id'])   ? $partner['state_id'][1]   : '',
            ]];
        }

        return $payload;
    }

    // ── GraphQL input builder ────────────────────────────────────────────

    private function buildGraphQLInput(array $payload): array
    {
        $input = [];

        // Use !== null checks — isset() passes false through, which Shopify rejects as invalid String
        if (!empty($payload['first_name']))                    $input['firstName'] = (string) $payload['first_name'];
        if (array_key_exists('last_name', $payload))          $input['lastName']  = (string) ($payload['last_name'] ?? '');
        if (!empty($payload['email']) && $payload['email'] !== false) $input['email'] = (string) $payload['email'];
        if (!empty($payload['phone']) && $payload['phone'] !== false) $input['phone'] = (string) $payload['phone'];

        // REMOVED: emailMarketingConsent - Shopify requires separate mutation
        // If you need to update marketing consent, use customerEmailMarketingConsentUpdate mutation

        if (!empty($payload['addresses'])) {
            $input['addresses'] = array_map(fn($addr) => [
                'address1'       => $addr['address1']  ?? $addr['address'] ?? '',
                'address2'       => $addr['address2']  ?? '',
                'city'           => $addr['city']      ?? '',
                'zip'            => $addr['zip']       ?? '',
                'countryCode'    => $addr['country']   ?? $addr['country_code'] ?? '',
                'provinceCode'   => $addr['province']  ?? $addr['province_code'] ?? '',
                'phone'          => $addr['phone']     ?? '',
            ], $payload['addresses']);
        }

        return $input;
    }

    // ── Normalizer ───────────────────────────────────────────────────────

    private function normalizeCustomer(array $c): array
    {
        $address = $c['addresses'][0] ?? [];
        
        // Combine first and last name into full name
        $firstName = $c['firstName'] ?? '';
        $lastName = $c['lastName'] ?? '';
        $fullName = trim("{$firstName} {$lastName}");

        return [
            'id'                => $this->fromGid($c['id']),
            'name'              => $fullName ?: 'Shopify Customer',  // ← ADD THIS
            'first_name'        => $firstName,
            'last_name'         => $lastName,
            'email'             => $c['email']     ?? '',
            'phone'             => $c['phone']     ?? '',
            'accepts_marketing' => ($c['emailMarketingConsent']['marketingState'] ?? '') === 'SUBSCRIBED',
            'addresses'         => $c['addresses'] ?? [],
        ];
    }

    // ── GID helpers ──────────────────────────────────────────────────────

    private function toGid(string $type, string $id): string
    {
        if (str_starts_with($id, 'gid://')) return $id;
        return "gid://shopify/{$type}/{$id}";
    }

    private function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }
}