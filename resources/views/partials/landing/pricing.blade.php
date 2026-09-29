{{--
    Pricing section — currently hidden from the landing page while pricing is
    not public. Restore by setting LANDING_SHOW_PRICING=true (see
    config/landing.php); the header/mobile nav links reappear with it.
--}}
<section id="pricing" class="border-t border-hairline bg-white py-20 md:py-28">
    <div class="mx-auto max-w-6xl px-6">
        <div class="mx-auto max-w-2xl text-center reveal">
            <p class="font-display text-sm font-semibold uppercase tracking-widest text-brand-strong" data-i18n="landing.pricing.eyebrow">{{ __('landing.pricing.eyebrow') }}</p>
            <h2 class="mt-3 font-display text-3xl font-semibold tracking-tight text-ink md:text-5xl text-balance" data-i18n="landing.pricing.title">{{ __('landing.pricing.title') }}</h2>
            <p class="mt-4 text-lg text-muted" data-i18n="landing.pricing.subtitle">{{ __('landing.pricing.subtitle') }}</p>
        </div>

        <div class="mt-14 grid items-start gap-4 lg:grid-cols-3">
            <!-- Starter -->
            <div class="card reveal flex flex-col p-8 transition-all duration-300">
                <h3 class="font-display text-lg font-semibold text-ink" data-i18n="landing.pricing.starter.name">{{ __('landing.pricing.starter.name') }}</h3>
                <p class="mt-1 text-sm text-muted" data-i18n="landing.pricing.starter.tag">{{ __('landing.pricing.starter.tag') }}</p>
                <p class="mt-6 font-display text-4xl font-semibold text-ink">{{ __('landing.pricing.starter.price') }}<span class="text-base font-medium text-muted" data-i18n="landing.pricing.starter.period">{{ __('landing.pricing.starter.period') }}</span></p>
                <ul class="mt-6 flex-1 space-y-3 text-sm text-muted">
                    @foreach (__('landing.pricing.starter.features') as $feature)
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-brand-strong">✓</span> {{ $feature }}</li>
                    @endforeach
                </ul>
                <a href="#cta" class="mt-8 rounded-full bg-white px-6 py-3 text-center text-sm font-semibold text-ink transition-all hover:border-black/20" data-i18n="landing.pricing.starter.cta">{{ __('landing.pricing.starter.cta') }}</a>
            </div>
            <!-- Pro (highlight) -->
            <div class="reveal relative flex flex-col rounded-3xl border-2 border-brand bg-ink p-8 shadow-e5 transition-all duration-300" style="transition-delay:.1s">
                <span class="btn btn-primary absolute -top-3 left-1/2 -translate-x-1/2" data-i18n="landing.pricing.pro.badge">{{ __('landing.pricing.pro.badge') }}</span>
                <h3 class="font-display text-lg font-semibold text-white" data-i18n="landing.pricing.pro.name">{{ __('landing.pricing.pro.name') }}</h3>
                <p class="mt-1 text-sm text-white/60" data-i18n="landing.pricing.pro.tag">{{ __('landing.pricing.pro.tag') }}</p>
                <p class="mt-6 font-display text-4xl font-semibold text-white">{{ __('landing.pricing.pro.price') }}<span class="text-base font-medium text-white/60" data-i18n="landing.pricing.pro.period">{{ __('landing.pricing.pro.period') }}</span></p>
                <ul class="mt-6 flex-1 space-y-3 text-sm text-white/70">
                    @foreach (__('landing.pricing.pro.features') as $feature)
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-brand-light">✓</span> {{ $feature }}</li>
                    @endforeach
                </ul>
                <a href="#cta" class="btn btn-primary mt-8" data-i18n="landing.pricing.pro.cta">{{ __('landing.pricing.pro.cta') }}</a>
            </div>
            <!-- Business -->
            <div class="card reveal flex flex-col p-8 transition-all duration-300" style="transition-delay:.2s">
                <h3 class="font-display text-lg font-semibold text-ink" data-i18n="landing.pricing.business.name">{{ __('landing.pricing.business.name') }}</h3>
                <p class="mt-1 text-sm text-muted" data-i18n="landing.pricing.business.tag">{{ __('landing.pricing.business.tag') }}</p>
                <p class="mt-6 font-display text-4xl font-semibold text-ink">{{ __('landing.pricing.business.price') }}<span class="text-base font-medium text-muted" data-i18n="landing.pricing.business.period">{{ __('landing.pricing.business.period') }}</span></p>
                <ul class="mt-6 flex-1 space-y-3 text-sm text-muted">
                    @foreach (__('landing.pricing.business.features') as $feature)
                        <li class="flex items-start gap-2"><span class="mt-0.5 text-brand-strong">✓</span> {{ $feature }}</li>
                    @endforeach
                </ul>
                <a href="#cta" class="mt-8 rounded-full bg-white px-6 py-3 text-center text-sm font-semibold text-ink transition-all hover:border-black/20" data-i18n="landing.pricing.business.cta">{{ __('landing.pricing.business.cta') }}</a>
            </div>
        </div>
    </div>
</section>
