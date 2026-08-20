<?php

namespace App\Services\Odoo;

class OdooCustomerService
{
    /** Fallback when fields_get is unavailable. */
    private const PARTNER_FIELDS_FALLBACK = [
        'id', 'name', 'email', 'phone', 'street', 'street2', 'city',
        'zip', 'state_id', 'country_id', 'is_company',
        'customer_rank', 'write_date', 'active',
    ];

    private $odoo;

    /** @var list<string>|null */
    private ?array $partnerFetchFields = null;

    public function __construct(OdooService $odoo)
    {
        $this->odoo = $odoo;
    }

    /**
     * Every readable res.partner column from Odoo fields_get (dynamic per install).
     *
     * @return list<string>
     */
    private function partnerFetchFields(): array
    {
        if ($this->partnerFetchFields !== null) {
            return $this->partnerFetchFields;
        }

        $names = app(OdooFieldNormalizer::class)->getSearchReadFieldNames('res.partner');

        $this->partnerFetchFields = $names !== [] ? $names : self::PARTNER_FIELDS_FALLBACK;

        return $this->partnerFetchFields;
    }

    /**
     * Find a partner by email (minimal fields — lookup only).
     */
    public function findByEmail(string $email): ?array
    {
        $results = $this->odoo->searchRead(
            'res.partner',
            [['email', '=', $email], ['active', '=', true]],
            ['id', 'email', 'name'],
            ['limit' => 1]
        );

        return $results[0] ?? null;
    }

    /**
     * Get partners modified since write_date — full Odoo record per partner.
     */
    public function getModifiedSince(string $writeDate): array
    {
        $results = $this->odoo->searchRead(
            'res.partner',
            [
                ['write_date', '>', $writeDate],
                ['active', '=', true],
            ],
            $this->partnerFetchFields(),
            ['order' => 'write_date asc', 'limit' => 500]
        );

        return array_map(fn (array $row) => $this->sanitizeFetchedRecord($row), $results);
    }

    /**
     * Read one partner by id — full Odoo record (used for single re-fetch / info page).
     */
    public function getById(int $id): ?array
    {
        $results = $this->odoo->searchRead(
            'res.partner',
            [['id', '=', $id]],
            $this->partnerFetchFields(),
            ['limit' => 1]
        );

        if (empty($results[0])) {
            return null;
        }

        return $this->sanitizeFetchedRecord($results[0]);
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

        return isset($results[0]['id']) ? (int) $results[0]['id'] : null;
    }

    /**
     * Resolve country_id from full country name (after value conditions e.g. IN → India).
     */
    public function resolveCountryIdByName(string $name): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        foreach ([['name', '=', $name], ['name', 'ilike', $name]] as $domain) {
            $results = $this->odoo->searchRead('res.country', [$domain], ['id'], ['limit' => 1]);
            if (!empty($results[0]['id'])) {
                return (int) $results[0]['id'];
            }
        }

        return null;
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

        return isset($results[0]['id']) ? (int) $results[0]['id'] : null;
    }

    /**
     * Resolve state_id from name or code, optionally scoped to a country.
     */
    public function resolveStateIdByName(string $name, ?int $countryId = null): ?int
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $baseDomain = $countryId ? [['country_id', '=', $countryId]] : [];

        foreach ([['name', '=', $name], ['name', 'ilike', $name], ['code', '=', strtoupper($name)]] as $clause) {
            $domain = array_merge($baseDomain, [$clause]);
            $results = $this->odoo->searchRead('res.country.state', $domain, ['id'], ['limit' => 1]);
            if (!empty($results[0]['id'])) {
                return (int) $results[0]['id'];
            }
        }

        return null;
    }

    public function readCountryCodeById(int $countryId): ?string
    {
        $results = $this->odoo->searchRead(
            'res.country',
            [['id', '=', $countryId]],
            ['code'],
            ['limit' => 1]
        );

        $code = $results[0]['code'] ?? null;

        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }

    public function readStateCodeById(int $stateId, ?int $countryId = null): ?string
    {
        $domain = [['id', '=', $stateId]];
        if ($countryId) {
            $domain[] = ['country_id', '=', $countryId];
        }

        $results = $this->odoo->searchRead('res.country.state', $domain, ['code'], ['limit' => 1]);
        $code    = $results[0]['code'] ?? null;

        return is_string($code) && $code !== '' ? strtoupper($code) : null;
    }

    public function readStateCodeByName(string $name, ?int $countryId = null): ?string
    {
        $stateId = $this->resolveStateIdByName($name, $countryId);

        return $stateId ? $this->readStateCodeById($stateId, $countryId) : null;
    }

    /** @param  array<string, mixed>  $record */
    private function sanitizeFetchedRecord(array $record): array
    {
        foreach ($record as $key => $value) {
            if (!is_string($value) || strlen($value) <= 200) {
                continue;
            }

            if (str_starts_with($key, 'image_') || $key === 'avatar_128') {
                $record[$key] = '[base64 ' . strlen($value) . ' chars truncated]';
            }
        }

        return $record;
    }
}
