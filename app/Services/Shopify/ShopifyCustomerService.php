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
     * Build Shopify customer payload from Odoo partner data.
     * Returns REST-style array — converted to GraphQL in create/update.
     */
    public function buildPayload(array $partner): array
    {
        $nameParts = explode(' ', $partner['name'], 2);

        $payload = [
            'first_name'        => $nameParts[0],
            'last_name'         => $nameParts[1] ?? '',
            'email'             => $partner['email'] ?? '',
            'phone'             => $partner['phone'] ?? $partner['mobile'] ?? '',
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

        if (isset($payload['first_name']))  $input['firstName'] = $payload['first_name'];
        if (isset($payload['last_name']))   $input['lastName']  = $payload['last_name'];
        if (isset($payload['email']))       $input['email']     = $payload['email'];
        if (isset($payload['phone']))       $input['phone']     = $payload['phone'] ?: null;

        if (isset($payload['accepts_marketing'])) {
            $input['emailMarketingConsent'] = [
                'marketingState'     => $payload['accepts_marketing'] ? 'SUBSCRIBED' : 'UNSUBSCRIBED',
                'marketingOptInLevel' => 'SINGLE_OPT_IN',
            ];
        }

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

        return [
            'id'                => $this->fromGid($c['id']),
            'first_name'        => $c['firstName'] ?? '',
            'last_name'         => $c['lastName']  ?? '',
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