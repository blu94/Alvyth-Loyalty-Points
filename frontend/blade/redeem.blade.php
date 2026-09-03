{{--
    Spend points on this order.

    Plain HTML on purpose. A theme places the `checkout` slot inside its own Vue-mounted
    summary, which compiles everything it contains as a template — an app mounted here would
    never run and a <style> block would be dropped silently, which is exactly how the account
    badge lost its CSS. Styled with the utilities the surrounding summary already uses, so it
    reads as part of the order rather than a widget dropped onto it.

    `data-checkout-field` is the whole integration. The theme collects it, core hands it to
    `RedeemPointsAtCheckout` through `CheckoutAdjusting::field()`, and nothing between here
    and the listener knows what a loyalty point is.

    Nothing is deducted by typing a number here. The order is priced with the reduction and
    the ledger entry is written when the order is paid, so an abandoned checkout costs the
    customer nothing.

    Copy is written as whole sentences with placeholders, never assembled from translated
    fragments. `__('available, worth about')` reads fine in English and cannot be translated
    into a language that orders the clause differently, because the word order was baked into
    the template rather than left to the translator. `trans_choice` rather than `Str::plural`
    for the same reason: plural rules are not two-way everywhere.
--}}
<div class="mb-4">
    <label for="loyalty-points-input" class="form-label fw-bold fs-14 tracking-wide-1 mb-2">
        {{ __('Use your points') }}
    </label>

    {{--
        Left empty rather than pre-filled with the balance. Points are the customer's to save
        for something larger, and a programme that spends them by default has taken that
        choice away.
    --}}
    <input type="number"
           id="loyalty-points-input"
           class="form-control rounded-0 shadow-none border"
           data-checkout-field="loyalty_points"
           min="0"
           max="{{ $balance }}"
           step="1"
           value=""
           placeholder="0"
           inputmode="numeric"
           autocomplete="off"
           aria-describedby="loyalty-points-help">

    <div id="loyalty-points-help" class="text-muted fs-14 mt-2">
        {{ trans_choice(
            '{1}You have :points point, worth about :worth.|[2,*]You have :points points, worth about :worth.',
            $balance,
            ['points' => number_format($balance), 'worth' => $worth]
        ) }}

        @if ($minimum > 0)
            {{ __('You need to spend at least :minimum in one go.', ['minimum' => number_format($minimum)]) }}
        @endif

        {{-- Said before they type, not after core silently charges them less. --}}
        {{ __('Anything over the order total is left on your balance.') }}
    </div>
</div>
