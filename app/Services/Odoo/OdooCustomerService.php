<?php

namespace App\Services\Odoo;

class OdooCustomerService
{
    private const PARTNER_FIELDS = [
        'id', 'name', 'email', 'phone', 'street', 'street2', 'city',
        'zip', 'state_id', 'country_id', 'is_company',
        'customer_rank', 'write_date', 'active',
    ];

    private $odoo;

    public function __construct(OdooService $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * Find a partner by email.
     */
    public function findByEmail(string $email): ?array
    {
        $results = $this->odoo->searchRead(
            'res.partner',
            [['email', '=', $email], ['active', '=', true]],
            self::PARTNER_FIELDS,
            ['limit' => 1]
        );

        return $results[0] ?? null;
    }

    /**
     * Get partners modified since write_date (customers only).
     */
    public function getModifiedSince(string $writeDate): array
    {
        return $this->odoo->searchRead(
            'res.partner',
            [
                ['write_date', '>', $writeDate],
                //['customer_rank', '>', 0],
                ['active', '=', true],
            ],
            self::PARTNER_FIELDS,
            ['order' => 'write_date asc', 'limit' => 500]
        );
    }

    /**
     * Create a partner in Odoo.
     */
    public function create(array $data): int
    {
        return $this->odoo->create('res.partner', $data);
    }

    /**
     * Update a partner.
     */
    public function update(int $partnerId, array $data): bool
    {
        return $this->odoo->write('res.partner', [$partnerId], $data);
    }

    /**
     * Resolve country_id from ISO2 code.
     */
    public function resolveCountry(string $code): ?int
    {
        $results = $this->odoo->searchRead(
            'res.country',
            [['code', '=', strtoupper($code)]],
            ['id'],
            ['limit' => 1]
        );

        return $results[0]['id'] ?? null;
    }

    /**
     * Resolve state_id from country_id and state code.
     */
    public function resolveState(int $countryId, string $code): ?int
    {
        $results = $this->odoo->searchRead(
            'res.country.state',
            [['country_id', '=', $countryId], ['code', '=', strtoupper($code)]],
            ['id'],
            ['limit' => 1]
        );

        return $results[0]['id'] ?? null;
    }
}
