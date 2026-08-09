@php
    $heading    = $data['heading'] ?? 'Your points';
    $subheading = $data['subheading'] ?? null;
    $showValue  = ($data['show_value'] ?? true) && $member->balance > 0;
    $showBar    = ($data['show_progress'] ?? true) && $nextTier !== null;
    $emptyText  = $data['empty_text'] ?? 'Start shopping to earn your first points.';

    // Progress across the band between the tier they hold and the next, not from zero.
    // From zero, someone just inside Gold at 2,000 of a 5,000 Platinum shows 40% and looks
    // barely started, when they are at the foot of the band.
    $floor    = $tier->threshold ?? 0;
    $ceiling  = $nextTier->threshold ?? null;
    $progress = $ceiling && $ceiling > $floor
        ? min(100, max(0, round((($member->lifetime_points - $floor) / ($ceiling - $floor)) * 100)))
        : 0;
@endphp

{{--
    Styled with the theme's own Bootstrap utilities, not a <style> block.

    A block placed on the account page renders inside the page's Vue root, and Vue strips
    <style> tags when it compiles a mount element as a template — the markup survived and the
    CSS silently did not. Borrowing the vocabulary the surrounding page already uses is also
    the reason this looks like part of the account rather than a widget dropped onto it.
--}}
<div class="card bg-light border-0 p-4">
    <h4 class="fw-bold mb-1 fs-5">{{ $heading }}</h4>

    @if ($subheading)
        <p class="text-muted fs-14 mb-0">{{ $subheading }}</p>
    @endif

    @if ($member->balance > 0 || $member->lifetime_points > 0)
        <div class="d-flex flex-wrap gap-4 mt-3">
            <div>
                <div class="fw-bold fs-4 lh-1">{{ number_format($member->balance) }}</div>
                <div class="text-muted fs-14">{{ __('Points available') }}</div>
            </div>

            @if ($showValue)
                <div>
                    <div class="fw-bold fs-4 lh-1">{{ $value }}</div>
                    <div class="text-muted fs-14">{{ __('Worth about') }}</div>
                </div>
            @endif

            @if ($tier)
                <div>
                    <div class="fw-bold fs-4 lh-1">{{ $tier->title }}</div>
                    <div class="text-muted fs-14">{{ __('Your tier') }}</div>
                </div>
            @endif
        </div>

        @if ($showBar)
            <div class="progress mt-4" style="height: 6px;" role="progressbar"
                 aria-valuenow="{{ $progress }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-dark" style="width: {{ $progress }}%"></div>
            </div>
            <p class="text-muted fs-14 mt-2 mb-0">
                {{ number_format($toNext) }}
                {{ \Illuminate\Support\Str::plural('point', $toNext) }}
                to reach {{ $nextTier->title }}
            </p>
        @endif

        {{-- Said plainly rather than left to be discovered at checkout. --}}
        @if ($minimum > 0 && $member->balance < $minimum)
            <p class="text-muted fs-14 mt-2 mb-0">
                {{ __('You can redeem once you reach') }} {{ number_format($minimum) }}
                {{ __('points') }}.
            </p>
        @endif
    @else
        <p class="text-muted fs-14 mt-3 mb-0">{{ $emptyText }}</p>
    @endif
</div>
