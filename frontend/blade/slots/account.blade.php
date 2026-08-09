{{--
    The `account` slot: the customer's standing, on their own account page.

    Rendered wherever a theme places `<x-plugin-slot name="account" />`, so it follows the
    contract rather than any one theme's markup — switching themes keeps it, because the
    obligation belongs to the theme contract and not to this package.

    The work is delegated to the class the page-builder section also uses: one place decides
    who the customer is and what their standing means, so the two placements cannot disagree.
--}}
@php
    $__badge = class_exists(\Plugin\LoyaltyPoints\Backend\Support\PointsBadge::class)
        ? app(\Plugin\LoyaltyPoints\Backend\Support\PointsBadge::class)->render([
            'heading'       => 'Your points',
            'subheading'    => 'Earned on every order you pay for.',
            'show_value'    => true,
            'show_progress' => true,
        ], app()->getLocale(), '')
        : '';
@endphp

{!! $__badge !!}
