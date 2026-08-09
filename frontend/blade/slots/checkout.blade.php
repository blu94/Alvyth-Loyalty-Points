{{--
    The `checkout` slot: spending points on the order being placed.

    Rendered wherever a theme places `<x-plugin-slot name="checkout" />`, so the package
    follows the contract rather than any one theme's markup and survives a theme change.

    The work is delegated to `RedeemBox` so the decision about who may redeem lives in one
    place with the rules it mirrors, not in a template. A slot file that returns nothing —
    for a guest, a suspended member, an empty balance, or a shop with redemption switched
    off — renders nothing at all, which is the same thing a disabled plugin does.
--}}
@php
    $__redeem = class_exists(\Plugin\LoyaltyPoints\Backend\Support\RedeemBox::class)
        ? app(\Plugin\LoyaltyPoints\Backend\Support\RedeemBox::class)->render($slotData ?? [])
        : '';
@endphp

{!! $__redeem !!}
