<?php

namespace App\Services\DataMigration;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\MigrationBatch;
use App\Models\MigrationRecordMap;
use App\Models\TaxCode;
use App\Models\User;
use App\Services\PasswordPolicy;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What an importer needs while it runs: the Internal Company, the
 * batch, the record map and the shared parsing/lookup rules -- so that,
 * for example, every module resolves a Company / Individual reference
 * the same way.
 */
class ImportContext
{
    /** @var array<int, string> warnings raised while importing the current record */
    public array $warnings = [];

    /** Set by an importer when the current record LINKED to one already here instead of creating it. */
    public bool $linked = false;

    /**
     * @param  array<string, string>  $decisions  source ref => "new" | "link:<company_individual id>"
     */
    public function __construct(
        public Company $company,
        public MigrationBatch $batch,
        public ?User $actor,
        public array $decisions = [],
    ) {}

    public function source(): string
    {
        return $this->batch->source;
    }

    public function sourceLabel(): string
    {
        return strtoupper($this->batch->source);
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }

    public function mapped(string $entity, string $sourceRef): ?MigrationRecordMap
    {
        return MigrationRecordMap::live()
            ->where('company_id', $this->company->id)
            ->where('source', $this->source())
            ->where('entity', $entity)
            ->where('source_ref', $sourceRef)
            ->first();
    }

    public function remember(string $entity, string $sourceRef, string $targetType, Model $target, string $action = MigrationRecordMap::ACTION_CREATED): void
    {
        MigrationRecordMap::create([
            'company_id' => $this->company->id,
            'source' => $this->source(),
            'entity' => $entity,
            'source_ref' => $sourceRef,
            'target_type' => $targetType,
            'target_id' => $target->getKey(),
            'action' => $action,
            'batch_id' => $this->batch->id,
        ]);
    }

    /**
     * The Company / Individual a row points at. By Source ID first
     * (exact, through the Company / Individual rows this source already
     * imported -- an ODOO child contact resolves to its parent), else
     * by name: an exact match ignoring case, spacing and punctuation,
     * then -- for ODOO's "Company, Person" display name -- the part
     * before the comma. Never a fuzzy guess: no match or more than one
     * fails the row.
     */
    public function customer(SourceRow $row, string $idKey = 'partner_id/id', string $nameKey = 'partner_id'): CompanyIndividual
    {
        $sourceId = $row->get($idKey);
        if ($sourceId !== null) {
            $map = $this->mapped('company_individuals', $sourceId);
            if ($map === null) {
                throw new RowFailed("{$this->sourceLabel()} Company / Individual {$sourceId} has not been imported yet -- import Company / Individual first.");
            }
            if ($map->target_type === 'contact') {
                return CompanyIndividual::findOrFail(Contact::findOrFail($map->target_id)->customer_id);
            }

            return CompanyIndividual::findOrFail($map->target_id);
        }

        $name = $row->get($nameKey);
        if ($name === null) {
            throw new RowFailed('No Company / Individual on this row.');
        }

        $found = $this->customerByName($name);
        if ($found === null && str_contains($name, ',')) {
            $found = $this->customerByName(trim(explode(',', $name, 2)[0]));
        }
        if ($found === null) {
            throw new RowFailed("No Company / Individual named \"{$name}\" -- import Company / Individual first.");
        }

        return $found;
    }

    /** Lower case, letters and digits only: "Harbour Logistics Pte. Ltd." == "harbour logistics pte ltd". */
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name));
    }

    /** @return Collection<int, CompanyIndividual> */
    public function customersNamed(string $name)
    {
        return CompanyIndividual::where('company_id', $this->company->id)
            ->whereRaw("regexp_replace(lower(name), '[^a-z0-9]', '', 'g') = ?", [self::nameKey($name)])
            ->get();
    }

    private function customerByName(string $name): ?CompanyIndividual
    {
        $matches = $this->customersNamed($name);
        if ($matches->count() > 1) {
            throw new RowFailed("More than one Company / Individual is named \"{$name}\" -- map a Source ID column instead.");
        }

        return $matches->first();
    }

    /**
     * A staff user of this Internal Company, by full name, email or
     * username (case-insensitive). Someone the old system knew who is
     * not a user here -- usually a former employee -- is created as an
     * INACTIVE user (decided 2026-09-25), who can never sign in, so
     * their history keeps its name. Remembered in the record map, so a
     * roll back removes them again if nothing else uses them.
     */
    public function staff(string $nameOrEmail, bool $createIfMissing = true): ?User
    {
        $needle = mb_strtolower(trim($nameOrEmail));
        $matches = User::where('company_id', $this->company->id)
            ->where(fn ($q) => $q->whereRaw('lower(full_name) = ?', [$needle])
                ->orWhereRaw('lower(email) = ?', [$needle])
                ->orWhereRaw('lower(username) = ?', [$needle]))
            ->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->count() > 1 || ! $createIfMissing) {
            return null;
        }

        $name = trim($nameOrEmail);
        $base = Str::slug($name, '.') ?: 'staff';
        $username = "{$base}.{$this->source()}";
        for ($n = 2; User::where('username', $username)->exists(); $n++) {
            $username = "{$base}.{$this->source()}{$n}";
        }
        $user = User::create([
            'company_id' => $this->company->id,
            'username' => $username,
            // A reserved, undeliverable domain: this user can never
            // receive a sign-in code or reset link.
            'email' => "{$username}@migrated.invalid",
            'hashed_password' => PasswordPolicy::hash(Str::random(40).'aA1!'),
            'full_name' => $name,
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'must_change_password' => true,
            'force_password_change_on_login' => true,
            'is_active' => false,
        ]);
        $this->remember('staff_users', "{$name}", 'user', $user);
        $this->warn("Created inactive staff user \"{$name}\" ({$username}).");

        return $user;
    }

    /**
     * A money amount exactly as the old system exported it -- never
     * recomputed. Thousands separators and a leading currency code or
     * symbol are tolerated; anything else fails the row.
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

    /** Singapore DD/MM/YYYY, or ISO YYYY-MM-DD (optionally with a time) as ODOO and Excel write it. */
    /**
     * The run's cut-off date (Dennis, 2026-09-26, decision page): it
     * covers transactions only -- customers, contacts and contracts come
     * across whole -- and a transaction dated before it is left out
     * unless it is still open (unpaid, active, not closed), which is
     * brought in whatever its date. A row with no date is brought in.
     *
     * @throws RowSkipped
     */
    public function skipBeforeCutoff(?Carbon $documentDate, bool $stillOpen, string $what): void
    {
        $cutoff = $this->batch->cutoff_date;
        if ($cutoff === null || $documentDate === null || $stillOpen) {
            return;
        }
        $cutoff = Carbon::parse($cutoff)->startOfDay();
        if ($documentDate->copy()->startOfDay()->lt($cutoff)) {
            throw new RowSkipped(sprintf('%s is dated %s, before the cut-off date %s, and is closed -- left out.',
                $what, $documentDate->format('d/m/Y'), $cutoff->format('d/m/Y')));
        }
    }

    public function date(?string $value, string $label, bool $required = true): ?Carbon
    {
        if ($value === null || $value === '') {
            if ($required) {
                throw new RowFailed("No {$label} on this row.");
            }

            return null;
        }
        foreach (['d/m/Y', 'd/m/Y H:i', 'd/m/Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'd-m-Y'] as $format) {
            $parsed = \DateTime::createFromFormat('!'.$format, $value);
            $errors = \DateTime::getLastErrors();
            if ($parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return Carbon::instance($parsed)->startOfDay();
            }
        }

        throw new RowFailed("{$label} \"{$value}\" is not a real date (DD/MM/YYYY).");
    }

    /** True/False (CSV), 1/0, Yes/No. */
    public static function truthy(?string $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['1', 'true', 'yes', 'y', 't'], true);
    }

    /**
     * Refuse a row in a currency other than SGD: every amount column
     * here is SGD, and converting a historical foreign-currency
     * document at some rate would be inventing figures.
     */
    public function requireSgd(SourceRow $row): void
    {
        $currency = $row->get('currency_id');
        if ($currency !== null && strtoupper($currency) !== 'SGD') {
            throw new RowFailed("Currency is {$currency}; only SGD documents can be migrated.");
        }
    }

    /**
     * The GST code for a migrated document. A mapped tax code column
     * wins; otherwise a document that carried GST is standard-rated
     * (SR -- Webmaster's confirmed treatment of its own supplies). A
     * document with no GST could be zero-rated, exempt or out of
     * scope, and which one is not ours to guess, so it needs the
     * column.
     *
     * @return array{0: string, 1: string} [code, rate percent as actually charged]
     */
    public function taxCode(SourceRow $row, Money $net, Money $tax): array
    {
        $code = $row->get('tax_code');
        if ($code === null) {
            if ($tax->toFloat() == 0.0) {
                throw new RowFailed('No GST on this document -- map a Tax code column (ZR, ES or OS) saying which.');
            }
            $code = 'SR';
        }
        $code = strtoupper($code);
        if (! TaxCode::where('company_id', $this->company->id)->where('code', $code)->exists()) {
            throw new RowFailed("Tax code {$code} is not set up in Tax Types.");
        }
        // The rate actually charged, recovered from the document's own
        // figures rather than today's table, so a 7%/8%-era document
        // keeps the rate it was issued at.
        $rate = $net->toFloat() == 0.0 ? Money::of(0) : $tax->multipliedBy(100)->dividedBy($net->toString())->quantize(2);

        return [$code, $rate->toString()];
    }

    /** A document number kept from the old system must not collide with one already here. */
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
