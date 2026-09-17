<?php

declare(strict_types=1);

namespace ImaticExternalLinks\Domain;

/**
 * Sanitizes untrusted enrichment payloads (from remote sources) into a small,
 * well-typed attribute bag. Defence in depth:
 *   - unknown keys are dropped (no attribute injection),
 *   - types are coerced to the declared shape,
 *   - strings are stripped of control characters and length-capped.
 *
 * This is NOT an HTML escaper — output escaping stays in the presentation
 * layer. Stripping tags here would corrupt legitimate names (e.g. `a<b>.txt`).
 */
final class MetaValidator
{
    private const MAX_STR = 500;

    /** @var array<string,string> allowed key => type (string|int|bool) */
    private $allowed = [
        'name'      => 'string',
        'title'     => 'string',
        'mime'      => 'string',
        'path'      => 'string',
        'icon'      => 'string',
        'fileid'    => 'string',
        'share'     => 'string',
        'thumbnail' => 'string',
        'size'      => 'int',
        'editable'  => 'bool',
        // Customer provider (internal Mantis records) — invoicing identifiers.
        'customer_id'   => 'string',
        'ico'           => 'string',
        'dic'           => 'string',
        'invoice_email' => 'string',
        'pohoda_id'     => 'string',
        'contact'       => 'string',
    ];

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed> whitelisted, type-safe, length-capped
     */
    public function sanitize(array $raw): array
    {
        $out = [];
        foreach ($this->allowed as $key => $type) {
            if (!array_key_exists($key, $raw)) {
                continue;
            }
            $value = $raw[$key];

            switch ($type) {
                case 'string':
                    if (!is_scalar($value)) {
                        continue 2; // skip non-scalar values silently
                    }
                    $out[$key] = self::cleanString((string) $value);
                    break;

                case 'int':
                    if (!is_numeric($value)) {
                        continue 2;
                    }
                    $out[$key] = (int) $value;
                    break;

                case 'bool':
                    $out[$key] = (bool) $value;
                    break;
            }
        }
        return $out;
    }

    private static function cleanString(string $value): string
    {
        // Drop C0 control chars and DEL, keep tab/newline out too (single-line meta).
        $clean = preg_replace('/[\x00-\x1F\x7F]/u', '', $value);
        if ($clean === null) {
            // preg_replace returns null on malformed UTF-8; fall back to a raw strip.
            $clean = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
            $clean = $clean === null ? '' : $clean;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($clean, 0, self::MAX_STR);
        }
        return substr($clean, 0, self::MAX_STR);
    }
}
