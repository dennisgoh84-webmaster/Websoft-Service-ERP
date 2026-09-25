<?php

namespace App\Services\DataMigration;

use App\Models\MigrationMapping;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Carbon;

/**
 * The saved field mapping per Internal Company + source + module, and
 * its Field Gap sign-off (decided 2026-09-25). Every column heading the
 * old system's files have ever had is either mapped to a field here,
 * left out on purpose, or a Field Gap -- a column with no field here
 * yet, which needs one added before its data can come across. Import
 * stays locked until no column is undecided or a Field Gap and a FULL
 * user has signed the mapping off; any change clears the sign-off.
 */
class MigrationMappings
{
    public static function get(string $companyId, string $source, string $entity): MigrationMapping
    {
        MigrationCatalog::requireModule($source, $entity);

        return MigrationMapping::firstOrCreate(
            ['company_id' => $companyId, 'source' => $source, 'entity' => $entity],
            ['mapping' => [], 'headers' => [], 'updated_at' => Carbon::now()],
        );
    }

    /**
     * Add a new file's column headings, auto-mapping the ones not seen
     * before. A heading never seen before clears the sign-off: it has
     * not been reviewed.
     *
     * @param  array<int, string>  $headers
     */
    public static function absorbHeaders(MigrationMapping $mapping, array $headers): MigrationMapping
    {
        $current = $mapping->mapping ?? [];
        $new = array_values(array_filter($headers, fn ($h) => ! array_key_exists($h, $current)));
        if ($new === []) {
            return $mapping;
        }
        $auto = MigrationCatalog::autoMap(MigrationCatalog::importer($mapping->entity), $new);
        foreach ($auto as $header => $key) {
            if ($key !== null && in_array($key, $current, true)) {
                $key = null; // that field already has a column
            }
            $current[$header] = $key;
        }
        $mapping->fill([
            'mapping' => $current,
            'headers' => array_values(array_unique([...($mapping->headers ?? []), ...$headers])),
            'signed_off_at' => null,
            'signed_off_by_user_id' => null,
            'updated_at' => Carbon::now(),
        ])->save();

        return $mapping;
    }

    /**
     * Apply the user's changes (heading => field key, "__skip__",
     * "__new_field__" or null). Refuses a field that doesn't exist or
     * one mapped from two columns.
     *
     * @param  array<string, string|null>  $changes
     */
    public static function update(MigrationMapping $mapping, array $changes, User $actor): MigrationMapping
    {
        $fields = MigrationCatalog::importer($mapping->entity)->fields();
        $current = $mapping->mapping ?? [];
        foreach ($changes as $header => $key) {
            if (! array_key_exists($header, $current)) {
                throw new \InvalidArgumentException("\"{$header}\" is not a column in any uploaded file.");
            }
            if ($key !== null && $key !== MigrationMapping::SKIP && $key !== MigrationMapping::NEW_FIELD && ! isset($fields[$key])) {
                throw new \InvalidArgumentException("Unknown field \"{$key}\".");
            }
            $current[$header] = $key;
        }
        $counts = array_count_values(array_filter($current, fn ($k) => is_string($k) && isset($fields[$k])));
        foreach ($counts as $key => $n) {
            if ($n > 1) {
                throw new \InvalidArgumentException("{$fields[$key]['label']} is mapped from {$n} columns; pick one.");
            }
        }
        if ($current === ($mapping->mapping ?? [])) {
            return $mapping;
        }

        $old = $mapping->mapping;
        $mapping->fill([
            'mapping' => $current,
            'signed_off_at' => null,
            'signed_off_by_user_id' => null,
            'updated_at' => Carbon::now(),
        ])->save();
        Audit::record(
            entityType: 'migration_mapping', entityId: $mapping->id, action: 'data_migration_mapping_changed',
            actorUserId: $actor->id,
            details: strtoupper($mapping->source)." {$mapping->entity}: field mapping changed (sign-off cleared)",
            oldValue: ['mapping' => $old], newValue: ['mapping' => $current],
        );

        return $mapping;
    }

    /**
     * Field Gap per column heading: its decision and what it means.
     *
     * @return array{columns: array<int, array{header: string, field: string|null, field_label: string|null, state: string}>, undecided: int, gaps: int, missing_required: array<int, string>, can_sign_off: bool, signed_off: bool}
     */
    public static function status(MigrationMapping $mapping): array
    {
        $fields = MigrationCatalog::importer($mapping->entity)->fields();
        $columns = [];
        $undecided = 0;
        $gaps = 0;
        // jsonb does not keep key order; the headings list does (the
        // order the columns appear in the old system's file).
        $map = $mapping->mapping ?? [];
        $ordered = array_values(array_unique([...array_map('strval', $mapping->headers ?? []), ...array_map('strval', array_keys($map))]));
        foreach ($ordered as $header) {
            if (! array_key_exists($header, $map)) {
                continue;
            }
            $key = $map[$header];
            $state = match (true) {
                $key === null => 'undecided',
                $key === MigrationMapping::SKIP => 'left_out',
                $key === MigrationMapping::NEW_FIELD => 'field_gap',
                default => 'mapped',
            };
            $undecided += $state === 'undecided' ? 1 : 0;
            $gaps += $state === 'field_gap' ? 1 : 0;
            $columns[] = [
                'header' => (string) $header,
                'field' => $key,
                'field_label' => is_string($key) && isset($fields[$key]) ? $fields[$key]['label'] : null,
                'state' => $state,
            ];
        }
        $mapped = array_filter($mapping->mapping ?? [], fn ($k) => is_string($k) && isset($fields[$k]));
        $missing = [];
        foreach ($fields as $key => $field) {
            if (($field['required'] ?? false) && ! in_array($key, $mapped, true)) {
                $missing[] = $field['label'];
            }
        }

        return [
            'columns' => $columns,
            'undecided' => $undecided,
            'gaps' => $gaps,
            'missing_required' => $missing,
            'can_sign_off' => $columns !== [] && $undecided === 0 && $gaps === 0 && $missing === [],
            'signed_off' => $mapping->signed_off_at !== null,
        ];
    }

    public static function signOff(MigrationMapping $mapping, User $actor): MigrationMapping
    {
        $status = self::status($mapping);
        if ($status['columns'] === []) {
            throw new \InvalidArgumentException('Upload a file first, so there are columns to sign off.');
        }
        if ($status['missing_required'] !== []) {
            throw new \InvalidArgumentException('Map a column to '.implode(', ', $status['missing_required']).' first.');
        }
        if ($status['undecided'] > 0 || $status['gaps'] > 0) {
            throw new \InvalidArgumentException(sprintf(
                '%d column(s) still undecided and %d Field Gap(s): map each column, or mark it Leave out, before signing off.',
                $status['undecided'], $status['gaps'],
            ));
        }
        $mapping->fill(['signed_off_at' => Carbon::now(), 'signed_off_by_user_id' => $actor->id])->save();
        Audit::record(
            entityType: 'migration_mapping', entityId: $mapping->id, action: 'data_migration_field_gap_signed_off',
            actorUserId: $actor->id,
            details: strtoupper($mapping->source)." {$mapping->entity}: Field Gap list signed off (".count($status['columns']).' columns)',
        );

        return $mapping;
    }
}
