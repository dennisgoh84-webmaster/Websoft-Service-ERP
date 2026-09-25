<?php

namespace App\Services\OdooMigration\Importers;

use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Services\OdooMigration\EntityImporter;
use App\Services\OdooMigration\ImportContext;
use App\Services\OdooMigration\OdooRecord;
use App\Services\OdooMigration\OdooRow;
use App\Services\OdooMigration\RowFailed;
use Illuminate\Database\Eloquent\Model;

/**
 * Odoo Contacts (res.partner) -> Company/Individual, or -> Contact.
 *
 * A partner with no parent becomes a Company/Individual (a company if
 * Odoo's `is_company` is set, else an individual). A partner with a
 * parent (`parent_id`, Odoo's "Related Company") is a person at that
 * company, so it becomes a Contact under the parent's
 * Company/Individual -- parents are imported first, whatever order the
 * file is in.
 *
 * `customer_rank`/`supplier_rank` set the customer/supplier flags when
 * the export has them. PDPA consent is never set by migration: Odoo
 * does not record it, and consent is not something to assume.
 */
class ContactsImporter extends EntityImporter
{
    public function entity(): string
    {
        return 'contacts';
    }

    public function targetType(Model $model): string
    {
        return $model instanceof Contact ? 'contact' : 'company_individual';
    }

    public function records(array $rows): array
    {
        $parents = array_values(array_filter($rows, fn (OdooRow $r) => ! $this->hasParent($r)));
        $children = array_values(array_filter($rows, fn (OdooRow $r) => $this->hasParent($r)));

        return parent::records([...$parents, ...$children]);
    }

    private function hasParent(OdooRow $row): bool
    {
        return $row->get('parent_id/id', 'parent_id', 'related company', 'related company/external id') !== null;
    }

    public function import(OdooRecord $record, ImportContext $ctx): Model
    {
        $row = $record->row;
        $name = $row->get('name', 'display_name', 'complete name');
        if ($name === null) {
            throw new RowFailed('A contact needs a name.');
        }

        if ($this->hasParent($row)) {
            $parent = $ctx->customer($row, 'parent_id', 'related company');

            return Contact::create([
                'customer_id' => $parent->id,
                'name' => $name,
                'email' => $row->get('email'),
                'phone' => $this->limit($row->get('phone', 'mobile'), 50, 'phone', $ctx),
                'is_active' => $row->get('active') === null || ImportContext::truthy($row->get('active')),
            ]);
        }

        $isCompany = ImportContext::truthy($row->get('is_company', 'is a company'));
        $values = [
            'company_id' => $ctx->company->id,
            'customer_type' => $isCompany ? CompanyIndividual::TYPE_COMPANY : CompanyIndividual::TYPE_INDIVIDUAL,
            'name' => $name,
            'legacy_customer_code' => $this->limit($row->get('ref', 'reference', 'internal reference'), 50, 'reference', $ctx),
            'uen' => $this->limit($row->get('company_registry', 'company registry', 'l10n_sg_unique_entity_number', 'uen'), 20, 'UEN', $ctx),
            'gst_registration_no' => $this->limit($row->get('vat', 'tax id', 'gst reg no'), 50, 'tax ID', $ctx),
            'billing_email' => $row->get('email'),
            'phone' => $this->limit($row->get('phone'), 50, 'phone', $ctx),
            'mobile' => $this->limit($row->get('mobile'), 50, 'mobile', $ctx),
            'website' => $row->get('website'),
            'address_line1' => $row->get('street'),
            'address_line2' => $row->get('street2'),
            'address_city' => $this->limit($row->get('city'), 100, 'city', $ctx),
            'address_state' => $this->limit($row->get('state_id', 'state'), 100, 'state', $ctx),
            'address_postal_code' => $this->limit($row->get('zip'), 20, 'postal code', $ctx),
            'address_country' => $this->limit($row->get('country_id', 'country'), 100, 'country', $ctx),
            'tags' => $this->limit($row->get('category_id', 'tags'), 255, 'tags', $ctx),
            'memo' => $this->plainText($row->get('comment', 'notes')),
            'payment_terms_days' => $this->paymentTermsDays($row->get('property_payment_term_id', 'customer payment terms', 'payment terms'), $ctx),
            'is_active' => $row->get('active') === null || ImportContext::truthy($row->get('active')),
            'pdpa_consent_given' => false,
        ];
        if ($row->has('customer_rank', 'customer rank')) {
            $values['is_customer'] = (int) $row->get('customer_rank', 'customer rank') > 0;
        }
        if ($row->has('supplier_rank', 'supplier rank')) {
            $values['is_supplier'] = (int) $row->get('supplier_rank', 'supplier rank') > 0;
        }

        return CompanyIndividual::create($values);
    }

    /**
     * "30 Days" -> 30, "Immediate Payment" -> 0. Anything else ("End of
     * Following Month", "30% Now, Balance 60 Days") has no single-number
     * equivalent here, so it is left blank and reported rather than
     * approximated.
     */
    private function paymentTermsDays(?string $term, ImportContext $ctx): ?int
    {
        if ($term === null) {
            return null;
        }
        if (preg_match('/^\s*(\d+)\s*days?\s*$/i', $term, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^\s*immediate( payment)?\s*$/i', $term)) {
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

    /** Odoo stores notes as HTML. */
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
            : "{$model->customer_type} {$model->name}";
    }
}
