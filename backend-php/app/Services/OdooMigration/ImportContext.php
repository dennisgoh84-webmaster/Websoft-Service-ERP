<?php

namespace App\Services\OdooMigration;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\OdooImportRun;
use App\Models\OdooRecordMap;
use App\Models\TaxCode;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * What an importer needs while it runs: the company, the run, the ID
 * map, and the shared parsing/lookup rules -- so that, for example,
 * every importer resolves an Odoo customer reference the same way.
 */
class ImportContext
{
    /** @var array<int, string> warnings raised while importing the current record */
    public array $warnings = [];

    /** @param  array<string, mixed>  $options */
    public function __construct(
        public Company $company,
        public OdooImportRun $run,
        public ?User $actor,
        public array $options = [],
    ) {}

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function mapped(string $entity, string $odooRef): ?OdooRecordMap
    {
        return OdooRecordMap::where('company_id', $this->company->id)
            ->where('entity', $entity)
            ->where('odoo_ref', $odooRef)
            ->first();
    }

    public function remember(string $entity, string $odooRef, string $targetType, Model $target): void
    {
        OdooRecordMap::create([
            'company_id' => $this->company->id,
            'entity' => $entity,
            'odoo_ref' => $odooRef,
            'target_type' => $targetType,
            'target_id' => $target->getKey(),
            'import_run_id' => $this->run->id,
        ]);
    }

    /**
     * The Company/Individual an Odoo `partner_id` points at. By External
     * ID first (`partner_id/id`, exact, through the contacts already
     * imported -- a child contact resolves to its parent company), else
     * by display name: an exact, case-insensitive name match, then --
     * for Odoo's "Company, Person" display name of a child contact --
     * the part before the comma. Never a fuzzy guess: no match or more
     * than one is a failed row.
     */
    public function customer(OdooRow $row, string $field = 'partner_id', string ...$labels): CompanyIndividual
    {
        $externalId = $row->get("{$field}/id", ...array_map(fn ($l) => "{$l}/external id", $labels));
        if ($externalId !== null) {
            $map = $this->mapped('contacts', $externalId);
            if ($map === null) {
                throw new RowFailed("Odoo contact {$externalId} has not been imported -- run the contacts import first.");
            }
            if ($map->target_type === 'contact') {
                return CompanyIndividual::findOrFail(Contact::findOrFail($map->target_id)->customer_id);
            }

            return CompanyIndividual::findOrFail($map->target_id);
        }

        $name = $row->get($field, ...$labels);
        if ($name === null) {
            throw new RowFailed("No customer ({$field}) on this row.");
        }

        $found = $this->customerByName($name);
        if ($found === null && str_contains($name, ',')) {
            $found = $this->customerByName(trim(explode(',', $name, 2)[0]));
        }
        if ($found === null) {
            throw new RowFailed("No Company/Individual named \"{$name}\" -- import it with the contacts first.");
        }

        return $found;
    }

    private function customerByName(string $name): ?CompanyIndividual
    {
        $matches = CompanyIndividual::where('company_id', $this->company->id)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(trim($name))])
            ->get();
        if ($matches->count() > 1) {
            throw new RowFailed("More than one Company/Individual is named \"{$name}\" -- use an import-compatible export so the row carries partner_id/id.");
        }

        return $matches->first();
    }

    /** A staff user of this company, by full name, email or username (case-insensitive). */
    public function user(string $nameOrEmail): ?User
    {
        $needle = mb_strtolower(trim($nameOrEmail));
        $matches = User::where('company_id', $this->company->id)
            ->where(fn ($q) => $q->whereRaw('lower(full_name) = ?', [$needle])
                ->orWhereRaw('lower(email) = ?', [$needle])
                ->orWhereRaw('lower(username) = ?', [$needle]))
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * A money amount exactly as Odoo exported it -- never recomputed.
     * Thousands separators and a leading currency code/symbol are
     * tolerated; anything else is a failed row.
     */
    public function money(?string $value, string $label, bool $required = true): ?Money
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new RowFailed("No {$label} on this row.");
            }

            return null;
        }
        $clean = preg_replace('/^(SGD|S\$|\$)\s*/i', '', str_replace([',', ' '], '', $value));
        if (! is_numeric($clean)) {
            throw new RowFailed("{$label} \"{$value}\" is not a number.");
        }

        return Money::of($clean)->quantize(2);
    }

    /** ISO (Y-m-d, optionally with a time) as Odoo exports it, or Singapore d/m/Y. */
    public function date(?string $value, string $label, bool $required = true): ?Carbon
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new RowFailed("No {$label} on this row.");
            }

            return null;
        }
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd/m/Y'] as $format) {
            $parsed = \DateTime::createFromFormat('!'.$format, $value);
            $errors = \DateTime::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return Carbon::instance($parsed)->startOfDay();
            }
        }

        throw new RowFailed("{$label} \"{$value}\" is not a date (expected YYYY-MM-DD or DD/MM/YYYY).");
    }

    /** Odoo writes booleans as True/False (CSV) or 1/0. */
    public static function truthy(?string $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 't'], true);
    }

    /**
     * Refuse a row in a currency other than SGD: every amount column in
     * this system is SGD, and converting a historical foreign-currency
     * document at some rate would be inventing figures.
     */
    public function requireSgd(OdooRow $row): void
    {
        $currency = $row->get('currency_id', 'currency');
        if ($currency !== null && strtoupper($currency) !== 'SGD') {
            throw new RowFailed("Currency is {$currency}; only SGD documents can be migrated.");
        }
    }

    /**
     * The GST code for a migrated document. A `tax_code` column (added
     * by hand) wins; otherwise a document that carried GST is
     * standard-rated (SR -- Webmaster's confirmed treatment of its own
     * supplies). A document with no GST could be zero-rated, exempt or
     * out of scope, and which one is not ours to guess, so it needs the
     * column.
     *
     * @return array{0: string, 1: string} [code, rate percent as Odoo charged it]
     */
    public function taxCode(OdooRow $row, Money $net, Money $tax): array
    {
        $code = $row->get('tax_code');
        if ($code === null) {
            if ($tax->toFloat() == 0.0) {
                throw new RowFailed('No GST on this document -- add a tax_code column (ZR, ES or OS) saying which.');
            }
            $code = 'SR';
        }
        $code = strtoupper($code);
        if (! TaxCode::where('company_id', $this->company->id)->where('code', $code)->exists()) {
            throw new RowFailed("Tax code {$code} is not set up in Tax Types.");
        }
        // The rate Odoo actually charged, recovered from its own
        // figures rather than today's table, so a 7%/8%-era document
        // keeps the rate it was issued at.
        $rate = $net->toFloat() == 0.0 ? Money::of(0) : $tax->multipliedBy(100)->dividedBy($net->toString())->quantize(2);

        return [$code, $rate->toString()];
    }

    /** A document number kept from Odoo must not collide with one already here. */
    public function requireUnusedNumber(string $modelClass, string $column, string $number): void
    {
        if (mb_strlen($number) > 50) {
            throw new RowFailed("Number \"{$number}\" is longer than 50 characters.");
        }
        if ($modelClass::where('company_id', $this->company->id)->where($column, $number)->exists()) {
            throw new RowFailed("{$number} already exists here.");
        }
    }
}
