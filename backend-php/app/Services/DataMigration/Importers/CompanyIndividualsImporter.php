<?php

namespace App\Services\DataMigration\Importers;

use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Services\DataMigration\EntityImporter;
use App\Services\DataMigration\ImportContext;
use App\Services\DataMigration\NeedsDecision;
use App\Services\DataMigration\RowFailed;
use App\Services\DataMigration\SourceRecord;
use App\Services\DataMigration\SourceRow;
use Illuminate\Database\Eloquent\Model;

/**
 * ODOO Contacts / ZSOFT Customers -> Company / Individual (or Contact).
 *
 * A row with no parent becomes a Company / Individual. An ODOO row with
 * a parent ("Related Company") is a person at that company, so it
 * becomes a Contact under the parent -- parents are imported first,
 * whatever order the file is in.
 *
 * Nothing is duplicated (decided 2026-09-25):
 * - the same UEN or GST registration no. as a record already here
 *   LINKS to that record automatically;
 * - the same name only (ignoring case, spacing and punctuation) waits
 *   for the user to choose Link or Create new in the dry-run preview;
 * - a linked record is never merged into or overwritten, and a roll
 *   back never removes it.
 *
 * PDPA consent is never set by migration: neither old system records
 * it, and consent is not something to assume.
 */
class CompanyIndividualsImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'company_individuals';
    }

    public function label(): string
    {
        return 'Company / Individual';
    }

    public function fields(): array
    {
        return self::sourceIdField('The old system\'s id or customer code. If left unmapped, the Legacy code is used.') + [
            'name' => ['label' => 'Name', 'required' => true, 'aliases' => ['name', 'display_name', 'complete name', 'customer name', 'cust_name', 'company name']],
            'is_company' => ['label' => 'Company or Individual', 'aliases' => ['is_company', 'is a company', 'type', 'customer type'], 'hint' => 'True/Company = company, False/Individual = individual. Unmapped = company.'],
            'parent_id/id' => ['label' => 'Parent company: Source ID', 'aliases' => ['parent_id/id', 'related company/external id'], 'hint' => 'ODOO: a person at a company becomes a Contact under it.'],
            'parent_id' => ['label' => 'Parent company: name', 'aliases' => ['parent_id', 'related company']],
            'ref' => ['label' => 'Legacy code', 'aliases' => ['ref', 'reference', 'internal reference', 'customer code', 'cust_code', 'code']],
            'company_registry' => ['label' => 'UEN', 'aliases' => ['company_registry', 'company registry', 'uen', 'roc no', 'roc_no', 'l10n_sg_unique_entity_number'], 'hint' => 'Used to spot duplicates.'],
            'vat' => ['label' => 'GST registration no.', 'aliases' => ['vat', 'tax id', 'gst reg no', 'gst_reg', 'gst no'], 'hint' => 'Used to spot duplicates.'],
            'contact_person' => ['label' => 'Contact person', 'aliases' => ['contact person', 'contact', 'attention']],
            'email' => ['label' => 'Email', 'aliases' => ['email', 'e-mail']],
            'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'tel', 'telephone']],
            'mobile' => ['label' => 'Mobile', 'aliases' => ['mobile', 'handphone', 'hp']],
            'website' => ['label' => 'Website', 'aliases' => ['website', 'web']],
            'street' => ['label' => 'Address line 1', 'aliases' => ['street', 'address', 'address 1', 'address1']],
            'street2' => ['label' => 'Address line 2', 'aliases' => ['street2', 'address 2', 'address2']],
            'city' => ['label' => 'City', 'aliases' => ['city']],
            'state_id' => ['label' => 'State', 'aliases' => ['state_id', 'state']],
            'zip' => ['label' => 'Postal code', 'aliases' => ['zip', 'postal code', 'postcode']],
            'country_id' => ['label' => 'Country', 'aliases' => ['country_id', 'country']],
            'category_id' => ['label' => 'Tags', 'aliases' => ['category_id', 'tags']],
            'comment' => ['label' => 'Memo', 'aliases' => ['comment', 'notes', 'remarks', 'memo']],
            'property_payment_term_id' => ['label' => 'Payment terms', 'aliases' => ['property_payment_term_id', 'customer payment terms', 'payment terms', 'terms'], 'hint' => '"30 Days", "30" or "Immediate Payment".'],
            'customer_rank' => ['label' => 'Is a customer (rank)', 'aliases' => ['customer_rank', 'customer rank'], 'hint' => 'Greater than 0 = customer.'],
            'supplier_rank' => ['label' => 'Is a supplier (rank)', 'aliases' => ['supplier_rank', 'supplier rank'], 'hint' => 'Greater than 0 = supplier.'],
            'active' => ['label' => 'Active', 'aliases' => ['active']],
        ];
    }

    public function targetType(Model $model): string
    {
        return $model instanceof Contact ? 'contact' : 'company_individual';
    }

    public function sourceRef(SourceRecord $record, ImportContext $ctx): ?string
    {
        return $record->row->get('id', 'ref');
    }

    public function records(array $rows): array
    {
        $parents = array_values(array_filter($rows, fn (SourceRow $r) => ! $this->hasParent($r)));
        $children = array_values(array_filter($rows, fn (SourceRow $r) => $this->hasParent($r)));

        return parent::records([...$parents, ...$children]);
    }

    private function hasParent(SourceRow $row): bool
    {
        return $row->get('parent_id/id', 'parent_id') !== null;
    }

    public function import(SourceRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $name = $row->get('name');
        if ($name === null) {
            throw new RowFailed('Name is required.');
        }

        if ($this->hasParent($row)) {
            $parent = $ctx->customer($row, 'parent_id/id', 'parent_id');

            return Contact::create([
                'customer_id' => $parent->id,
                'name' => $name,
                'email' => $row->get('email'),
                'phone' => $this->limit($row->get('phone', 'mobile'), 50, 'Phone', $ctx),
                'is_active' => $row->get('active') === null || ImportContext::truthy($row->get('active')),
            ]);
        }

        $uen = $this->limit($row->get('company_registry'), 20, 'UEN', $ctx);
        $gst = $this->limit($row->get('vat'), 50, 'GST registration no.', $ctx);
        $existing = $this->match($record, $ctx, $name, $uen, $gst);
        if ($existing !== null) {
            $ctx->linked = true;

            return $existing;
        }

        $values = [
            'company_id' => $ctx->company->id,
            'customer_type' => $this->isCompany($row->get('is_company')) ? CompanyIndividual::TYPE_COMPANY : CompanyIndividual::TYPE_INDIVIDUAL,
            'name' => $name,
            'legacy_customer_code' => $this->limit($row->get('ref'), 50, 'Legacy code', $ctx),
            'uen' => $uen,
            'gst_registration_no' => $gst,
            'contact_person' => $row->get('contact_person'),
            'billing_email' => $row->get('email'),
            'phone' => $this->limit($row->get('phone'), 50, 'Phone', $ctx),
            'mobile' => $this->limit($row->get('mobile'), 50, 'Mobile', $ctx),
            'website' => $row->get('website'),
            'address_line1' => $row->get('street'),
            'address_line2' => $row->get('street2'),
            'address_city' => $this->limit($row->get('city'), 100, 'City', $ctx),
            'address_state' => $this->limit($row->get('state_id'), 100, 'State', $ctx),
            'address_postal_code' => $this->limit($row->get('zip'), 20, 'Postal code', $ctx),
            'address_country' => $this->limit($row->get('country_id'), 100, 'Country', $ctx),
            'tags' => $this->limit($row->get('category_id'), 255, 'Tags', $ctx),
            'memo' => $this->plainText($row->get('comment')),
            'payment_terms_days' => $this->paymentTermsDays($row->get('property_payment_term_id'), $ctx),
            'is_active' => $row->get('active') === null || ImportContext::truthy($row->get('active')),
            'pdpa_consent_given' => false,
        ];
        if ($row->get('customer_rank') !== null) {
            $values['is_customer'] = (int) $row->get('customer_rank') > 0;
        }
        if ($row->get('supplier_rank') !== null) {
            $values['is_supplier'] = (int) $row->get('supplier_rank') > 0;
        }

        return CompanyIndividual::create($values);
    }

    /**
     * An existing Company / Individual this row should link to, or null
     * to create a new one. Strong match (UEN / GST no.) links; a
     * name-only match needs the user's decision.
     */
    private function match(SourceRecord $record, ImportContext $ctx, string $name, ?string $uen, ?string $gst): ?CompanyIndividual
    {
        foreach (['uen' => $uen, 'gst_registration_no' => $gst] as $column => $value) {
            if ($value === null) {
                continue;
            }
            $key = strtoupper(preg_replace('/[\s-]/', '', $value));
            $strong = CompanyIndividual::where('company_id', $ctx->company->id)
                ->whereRaw("upper(regexp_replace({$column}, '[\\s-]', '', 'g')) = ?", [$key])
                ->first();
            if ($strong !== null) {
                $label = $column === 'uen' ? 'UEN' : 'GST no.';
                $ctx->warn("Linked to existing \"{$strong->name}\" (same {$label} {$value}).");

                return $strong;
            }
        }

        $candidates = $ctx->customersNamed($name);
        if ($candidates->isEmpty()) {
            return null;
        }

        $ref = $this->sourceRef($record, $ctx) ?? '';
        $decision = $ctx->decisions[$ref] ?? null;
        if ($decision === 'new') {
            $ctx->warn('Created new although "'.$candidates->first()->name.'" has the same name (your decision).');

            return null;
        }
        if (is_string($decision) && str_starts_with($decision, 'link:')) {
            $chosen = CompanyIndividual::where('company_id', $ctx->company->id)->find(substr($decision, 5));
            if ($chosen === null) {
                throw new RowFailed('The Company / Individual chosen to link to no longer exists.');
            }
            $ctx->warn("Linked to existing \"{$chosen->name}\" (your decision).");

            return $chosen;
        }

        throw new NeedsDecision(
            "Possible duplicate of existing \"{$candidates->first()->name}\" -- choose Link or Create new.",
            $candidates->map(fn (CompanyIndividual $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'detail' => implode(' · ', array_filter([
                    $c->customer_type === CompanyIndividual::TYPE_INDIVIDUAL ? 'Individual' : 'Company',
                    $c->uen ? "UEN {$c->uen}" : null,
                    $c->billing_email,
                    $c->phone,
                ])),
            ])->values()->all(),
        );
    }

    private function isCompany(?string $value): bool
    {
        if ($value === null) {
            return true;
        }
        $v = mb_strtolower(trim($value));
        if (in_array($v, ['company', 'corporate', 'business', 'c'], true)) {
            return true;
        }
        if (in_array($v, ['individual', 'person', 'personal', 'i'], true)) {
            return false;
        }

        return ImportContext::truthy($value);
    }

    /**
     * "30 Days" or "30" -> 30, "Immediate Payment" -> 0. Anything else
     * ("End of Following Month") has no single-number equivalent here,
     * so it is left blank and reported rather than approximated.
     */
    private function paymentTermsDays(?string $term, ImportContext $ctx): ?int
    {
        if ($term === null) {
            return null;
        }
        if (preg_match('/^\s*(\d+)\s*(days?)?\s*$/i', $term, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^\s*(immediate( payment)?|cod|cash)\s*$/i', $term)) {
            return 0;
        }
        $ctx->warn("Payment term \"{$term}\" has no number-of-days equivalent; left blank.");

        return null;
    }

    private function limit(?string $value, int $max, string $label, ImportContext $ctx): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $ctx->warn("{$label} \"{$value}\" is longer than {$max} characters; left blank.");

            return null;
        }

        return $value;
    }

    /** ODOO stores notes as HTML. */
    private function plainText(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>#i', "\n", $html))));

        return $text === '' ? null : $text;
    }

    public function describe(Model $model): string
    {
        return $model instanceof Contact
            ? "Contact {$model->name} under ".CompanyIndividual::find($model->customer_id)?->name
            : ($model->customer_type === CompanyIndividual::TYPE_INDIVIDUAL ? 'Individual' : 'Company')." {$model->name}";
    }
}
