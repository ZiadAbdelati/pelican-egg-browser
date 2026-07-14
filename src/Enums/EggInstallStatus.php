<?php

namespace Community\EggBrowser\Enums;

enum EggInstallStatus: string
{
    case NotInstalled = 'not_installed';
    case UpToDate = 'up_to_date';
    case UpdateAvailable = 'update_available';
    case LocalChanges = 'local_changes';
    case LocalChangesAndUpdate = 'local_changes_and_update';
    case SourceUnavailable = 'source_unavailable';
    case UnknownUnlinked = 'unknown_unlinked';
    case CheckFailed = 'check_failed';

    public function label(): string
    {
        return match ($this) {
            self::NotInstalled => trans('egg-browser::strings.status.not_installed'),
            self::UpToDate => trans('egg-browser::strings.status.up_to_date'),
            self::UpdateAvailable => trans('egg-browser::strings.status.update_available'),
            self::LocalChanges => trans('egg-browser::strings.status.local_changes'),
            self::LocalChangesAndUpdate => trans('egg-browser::strings.status.local_changes_and_update'),
            self::SourceUnavailable => trans('egg-browser::strings.status.source_unavailable'),
            self::UnknownUnlinked => trans('egg-browser::strings.status.unknown_unlinked'),
            self::CheckFailed => trans('egg-browser::strings.status.check_failed'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::NotInstalled => 'gray',
            self::UpToDate => 'success',
            self::UpdateAvailable => 'warning',
            self::LocalChanges, self::LocalChangesAndUpdate => 'info',
            self::SourceUnavailable => 'danger',
            self::UnknownUnlinked => 'gray',
            self::CheckFailed => 'danger',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NotInstalled => 'tabler-download',
            self::UpToDate => 'tabler-circle-check',
            self::UpdateAvailable => 'tabler-arrow-up-circle',
            self::LocalChanges => 'tabler-edit',
            self::LocalChangesAndUpdate => 'tabler-alert-triangle',
            self::SourceUnavailable => 'tabler-unlink',
            self::UnknownUnlinked => 'tabler-help-circle',
            self::CheckFailed => 'tabler-exclamation-circle',
        };
    }

    /**
     * Map to the user-facing status names from the product requirements.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::NotInstalled => 'Not installed',
            self::UpToDate => 'Up to date',
            self::UpdateAvailable => 'Update available',
            self::LocalChanges => 'Local changes detected',
            self::LocalChangesAndUpdate => 'Local changes detected (update available)',
            self::SourceUnavailable => 'Source unavailable',
            self::UnknownUnlinked => 'Unknown/unlinked',
            self::CheckFailed => 'Check failed',
        };
    }
}
