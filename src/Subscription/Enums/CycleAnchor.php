<?php

declare(strict_types=1);

namespace Cbox\Billing\Subscription\Enums;

/**
 * Where a billing cycle's anchor day comes from (ADR-0012).
 *
 * - `SignupDay` (default): the cycle renews on the day of the month the subscription
 *   began — signing up on the 17th bills on the 17th every cycle.
 * - `CalendarFirst`: the cycle is pinned to the calendar 1st regardless of signup
 *   day, so every subscription on the plan renews together on the 1st.
 *
 * This is a construction-time choice that fixes the {@see BillingCycle}'s anchor day;
 * it is not stored on the cycle itself (the cycle carries only the resolved day).
 */
enum CycleAnchor: string
{
    case SignupDay = 'signup_day';
    case CalendarFirst = 'calendar_first';
}
