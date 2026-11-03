<?php

declare(strict_types=1);

namespace Cbstian\AiChat\Speech;

use Filament\Facades\Filament;
use Throwable;

class FilamentPanelId
{
    public function current(): ?string
    {
        if (! class_exists(Filament::class)) {
            return null;
        }

        try {
            $panel = Filament::getCurrentPanel();
        } catch (Throwable) {
            return null;
        }

        if (! is_object($panel) || ! method_exists($panel, 'getId')) {
            return null;
        }

        $id = $panel->getId();

        return is_string($id) && $id !== '' ? $id : null;
    }
}
