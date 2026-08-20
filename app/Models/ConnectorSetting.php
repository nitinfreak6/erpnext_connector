<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ConnectorSetting extends Model
{
    protected $fillable = [
        'group', 'key', 'label', 'value', 'default_value',
        'is_secret', 'is_active', 'description', 'sort_order',
    ];

    protected $casts = [
        'is_secret' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Store value, encrypting if secret.
     */
    public function setValueAttribute(?string $value): void
    {
        if ($this->is_secret && $value !== null && $value !== '') {
            $this->attributes['value'] = Crypt::encryptString($value);
        } else {
            $this->attributes['value'] = $value;
        }
    }

    /**
     * Decrypt value for secrets.
     */
    public function getDecryptedValue(): ?string
    {
        if (!$this->is_secret || empty($this->attributes['value'])) {
            return $this->attributes['value'] ?? null;
        }

        try {
            $value = Crypt::decryptString($this->attributes['value']);

            // Legacy rows double-encrypted before the settings save fix — unwrap once more.
            try {
                return Crypt::decryptString($value);
            } catch (\Throwable) {
                return $value;
            }
        } catch (\Throwable) {
            // Do not treat raw ciphertext as a usable secret (prevents re-saving garbage on every form post).
            return null;
        }
    }

    /**
     * Get masked version for display.
     */
    public function getMaskedValue(): string
    {
        $val = $this->getDecryptedValue();
        if (!$val) return '';
        if (!$this->is_secret) return $val;
        $len = strlen($val);
        return substr($val, 0, min(4, $len)) . str_repeat('•', min(20, max(0, $len - 4)));
    }
}
