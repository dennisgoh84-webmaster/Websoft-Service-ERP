<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class UpgradeService
{
    private string $backupDir;

    private string $appRoot;

    public function __construct()
    {
        $this->appRoot = base_path();
        $this->backupDir = storage_path('upgrades/backups');

        if (! File::isDirectory($this->backupDir)) {
            File::makeDirectory($this->backupDir, 0755, true);
        }
    }

    /**
     * Execute upgrade to a target version
     */
    public function executeUpgrade(string $targetVersion, ?string $backupId = null): array
    {
        try {
            // Step 1: Get current version
            $currentVersion = $this->getCurrentVersion();

            if ($currentVersion === $targetVersion) {
                return [
                    'success' => false,
                    'error' => 'Already at version '.$targetVersion,
                ];
            }

            // Step 2: Create backup
            $backupPath = $this->createBackup($currentVersion, $targetVersion);

            if (! $backupPath) {
                return [
                    'success' => false,
                    'error' => 'Failed to create backup',
                ];
            }

            // Step 3: Pull latest code from GitHub
            $pullResult = $this->pullLatestCode($targetVersion);

            if (! $pullResult['success']) {
                $this->rollbackCodeFromBackup($backupPath);

                return [
                    'success' => false,
                    'error' => 'Failed to pull latest code: '.$pullResult['error'],
                ];
            }

            // Step 4: Run migrations
            $migrationResult = $this->runMigrations();

            if (! $migrationResult['success']) {
                $this->rollbackCodeFromBackup($backupPath);

                return [
                    'success' => false,
                    'error' => 'Migration failed: '.$migrationResult['error'],
                ];
            }

            // Step 5: Clear caches
            Artisan::call('cache:clear');
            Artisan::call('config:clear');

            return [
                'success' => true,
                'message' => 'Successfully upgraded to version '.$targetVersion,
                'version' => $targetVersion,
                'backup_path' => $backupPath,
                'release_notes' => $pullResult['release_notes'] ?? null,
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute rollback to previous version
     */
    public function executeRollback(?string $fromVersion = null, ?string $toVersion = null, ?string $backupId = null): array
    {
        try {
            $currentVersion = $this->getCurrentVersion();

            // Find the backup to restore from
            $backupPath = $this->findBackupForRollback($fromVersion ?? $currentVersion);

            if (! $backupPath) {
                return [
                    'success' => false,
                    'error' => 'No backup found for rollback',
                ];
            }

            // Restore code from backup
            $restoreResult = $this->restoreCodeFromBackup($backupPath);

            if (! $restoreResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Failed to restore code: '.$restoreResult['error'],
                ];
            }

            // Migrate back down (only if needed - migrations are ideally reversible)
            // For now, we'll just run migrations fresh against the restored code
            $migrationResult = $this->runMigrations();

            if (! $migrationResult['success']) {
                return [
                    'success' => false,
                    'error' => 'Migration after rollback failed: '.$migrationResult['error'],
                ];
            }

            // Clear caches
            Artisan::call('cache:clear');
            Artisan::call('config:clear');

            $targetVersion = $toVersion ?? $this->extractVersionFromPath($backupPath);

            return [
                'success' => true,
                'message' => 'Successfully rolled back to version '.$targetVersion,
                'version' => $targetVersion,
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create backup of code before upgrade
     */
    private function createBackup(string $fromVersion, string $toVersion): ?string
    {
        try {
            $timestamp = now()->format('YmdHis');
            $backupSubdir = "{$fromVersion}_to_{$toVersion}/{$timestamp}";
            $backupPath = $this->backupDir.'/'.$backupSubdir;

            // Create code backup (excluding large directories)
            if (! File::makeDirectory($backupPath, 0755, true)) {
                throw new Exception('Failed to create backup directory');
            }

            // Copy key directories
            foreach (['app', 'routes', 'database', 'config', 'bootstrap'] as $dir) {
                $source = "{$this->appRoot}/{$dir}";
                $dest = "{$backupPath}/{$dir}";

                if (File::isDirectory($source)) {
                    $this->copyDirectory($source, $dest);
                }
            }

            // Backup current .env
            if (File::exists("{$this->appRoot}/.env")) {
                File::copy("{$this->appRoot}/.env", "{$backupPath}/.env");
            }

            return $backupPath;

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Pull latest code from GitHub
     */
    private function pullLatestCode(string $targetVersion): array
    {
        try {
            // This assumes the app is in a git repository
            $result = Process::run(
                "cd {$this->appRoot} && git fetch origin && git checkout {$targetVersion}",
                timeout: 300 // 5 minutes
            );

            if (! $result->successful()) {
                return [
                    'success' => false,
                    'error' => $result->errorOutput(),
                ];
            }

            return [
                'success' => true,
                'release_notes' => null, // Could fetch from GitHub API if needed
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Run pending migrations
     */
    private function runMigrations(): array
    {
        try {
            Artisan::call('migrate', ['--force' => true]);

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get current version from migrations table
     */
    private function getCurrentVersion(): string
    {
        try {
            $migration = DB::table('migrations')
                ->orderByDesc('batch')
                ->orderByDesc('id')
                ->first();

            if ($migration) {
                // Extract version from migration filename
                // e.g., "2026_09_16_000100_create_system_mail_settings"
                if (preg_match('/^(\d{4}_\d{2}_\d{2})/', $migration->migration, $matches)) {
                    return 'v'.str_replace('_', '.', $matches[1]);
                }
            }

            return 'unknown';

        } catch (Exception $e) {
            return 'unknown';
        }
    }

    /**
     * Find backup for rollback
     */
    private function findBackupForRollback(string $fromVersion): ?string
    {
        try {
            $pattern = "{$this->backupDir}/*_to_{$fromVersion}";
            $backups = glob($pattern, GLOB_ONLYDIR);

            if (empty($backups)) {
                return null;
            }

            // Get the most recent backup
            usort($backups, fn ($a, $b) => filemtime($b) <=> filemtime($a));

            $latestBackup = $backups[0];
            $subDirs = glob("{$latestBackup}/*", GLOB_ONLYDIR);

            if (empty($subDirs)) {
                return null;
            }

            usort($subDirs, fn ($a, $b) => filemtime($b) <=> filemtime($a));

            return $subDirs[0];

        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Restore code from backup
     */
    private function restoreCodeFromBackup(string $backupPath): array
    {
        try {
            foreach (['app', 'routes', 'database', 'config', 'bootstrap'] as $dir) {
                $source = "{$backupPath}/{$dir}";
                $dest = "{$this->appRoot}/{$dir}";

                if (File::isDirectory($source)) {
                    File::deleteDirectory($dest);
                    $this->copyDirectory($source, $dest);
                }
            }

            // Restore .env if it exists
            if (File::exists("{$backupPath}/.env")) {
                File::copy("{$backupPath}/.env", "{$this->appRoot}/.env", true);
            }

            return ['success' => true];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Rollback code to what was in backup (for recovery)
     */
    private function rollbackCodeFromBackup(string $backupPath): void
    {
        try {
            $this->restoreCodeFromBackup($backupPath);
        } catch (Exception $e) {
            // Log but don't throw - we've already failed
            report($e);
        }
    }

    /**
     * Helper to copy directory recursively
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (! File::isDirectory($destination)) {
            File::makeDirectory($destination, 0755, true);
        }

        $files = File::allFiles($source);

        foreach ($files as $file) {
            $relativePath = $file->getRelativePathname();
            $dest = "{$destination}/{$relativePath}";

            File::ensureDirectoryExists(dirname($dest));
            File::copy($file->getPathname(), $dest);
        }
    }

    /**
     * Extract version from backup path
     */
    private function extractVersionFromPath(string $backupPath): string
    {
        // Extract from path like: /backups/v2.1.0_to_v2.2.0/20260917120000
        if (preg_match('/(\w+_\d{8}\d{6})/', $backupPath, $matches)) {
            $parts = explode('_to_', basename(dirname($backupPath)));

            return $parts[0] ?? 'unknown';
        }

        return 'unknown';
    }
}
