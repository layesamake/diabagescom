{{-- Filigrane DIABA GESCOM : visible uniquement sur les environnements d'essai. --}}
@if (app(\App\Domain\Documents\TrialWatermark::class)->enabled())
@php $trialLogo = app(\App\Domain\Documents\TrialWatermark::class)->logoDataUri(); @endphp
@if ($trialLogo)
<style>
    .trial-watermark {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 9999;
        pointer-events: none;
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .trial-watermark img {
        opacity: 0.08;
        transform: rotate(-35deg);
        width: 70%;
        max-width: 400px;
    }
    @media print {
        .trial-watermark { display: flex !important; }
    }
</style>
<div class="trial-watermark" aria-hidden="true">
    <img src="{{ $trialLogo }}" alt="">
</div>
@endif
@endif
