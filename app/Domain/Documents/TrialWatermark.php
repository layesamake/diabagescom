<?php

namespace App\Domain\Documents;

/**
 * Filigrane DIABA GESCOM des environnements d'essai.
 *
 * Le drapeau est désactivé par défaut : aucun document de production ne change
 * sans activation explicite de DIABA_TRIAL_WATERMARK sur l'environnement.
 */
final class TrialWatermark
{
    private ?string $dataUri = null;

    public function enabled(): bool
    {
        return (bool) config('diaba.trial_watermark.enabled');
    }

    public function logoDataUri(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        if ($this->dataUri !== null) {
            return $this->dataUri;
        }

        $path = public_path('brand/logo-diaba-gescom-horizontal.png');
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            return null;
        }

        return $this->dataUri = 'data:image/png;base64,'.base64_encode($contents);
    }
}
