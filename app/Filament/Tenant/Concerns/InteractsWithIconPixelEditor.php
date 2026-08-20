<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Concerns;

use App\Support\IconPixelEditor;
use App\Support\TenantAssetUrl;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

trait InteractsWithIconPixelEditor
{
    public function applyIconPixelEdit(string $field, string $dataUrl): bool
    {
        try {
            $path = IconPixelEditor::store($field, $dataUrl);
        } catch (InvalidArgumentException) {
            Notification::make()
                ->danger()
                ->title(__('Could not save the drawing.'))
                ->send();

            return false;
        }

        $previous = $this->currentUploadPath($field);

        if (
            $previous !== ''
            && $previous !== $path
            && IconPixelEditor::isDrawnPath($previous)
            && TenantAssetUrl::publicDiskExists($previous)
        ) {
            Storage::disk('public')->delete($previous);
        }

        $this->data[$field] = [$path];

        Notification::make()
            ->success()
            ->title(__('Drawing applied. Save settings to keep it.'))
            ->send();

        return true;
    }

    private function currentUploadPath(string $field): string
    {
        $state = $this->data[$field] ?? '';

        if (is_array($state)) {
            $state = $state[array_key_first($state)] ?? '';
        }

        return is_string($state) ? ltrim($state, '/') : '';
    }
}
