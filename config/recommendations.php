<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Screen Category Affinity Mapping
    |--------------------------------------------------------------------------
    |
    | Maps category slugs to compatible category clusters for controlled
    | category expansion in the recommendation engine (Option 3 — Expanded Coverage).
    | Categories in the same affinity cluster can be recommended together
    | when budget allows, without introducing arbitrary relationships.
    |
    */
    'category_affinity' => [
        // Retail & Leisure places
        'mall-display' => ['mall-display', 'theatre-screen', 'restaurant-display', 'mall', 'theatre'],
        'theatre-screen' => ['theatre-screen', 'mall-display', 'restaurant-display', 'theatre', 'mall'],
        'restaurant-display' => ['restaurant-display', 'mall-display', 'theatre-screen'],
        'mall' => ['mall', 'theatre', 'mall-display', 'theatre-screen'],
        'theatre' => ['theatre', 'mall', 'theatre-screen', 'mall-display'],

        // Transit & Commuter hubs
        'bus-terminal' => ['bus-terminal', 'bus-stop-shelter-display', 'bus-screen', 'airport-screen'],
        'bus-stop-shelter-display' => ['bus-stop-shelter-display', 'bus-terminal', 'bus-screen'],
        'bus-screen' => ['bus-screen', 'bus-terminal', 'bus-stop-shelter-display'],
        'airport-screen' => ['airport-screen', 'bus-terminal'],

        // Outdoor & Roadside
        'led-billboard' => ['led-billboard', 'outdoor-display', 'digital-totem'],
        'outdoor-display' => ['outdoor-display', 'led-billboard', 'digital-totem'],
        'digital-totem' => ['digital-totem', 'outdoor-display', 'led-billboard'],

        // Mobile Assets
        'led-van' => ['led-van', 'led-truck', 'backpack-team', 'taxi'],
        'led-truck' => ['led-truck', 'led-van', 'backpack-team'],
        'backpack-team' => ['backpack-team', 'led-van', 'led-truck'],
        'taxi' => ['taxi', 'led-van', 'backpack-team'],

        // Events
        'event-display' => ['event-display', 'digital-totem', 'outdoor-display'],

        // General Displays
        'standard-display' => ['standard-display', 'digital-totem', 'outdoor-display'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendation Engine Constraints
    |--------------------------------------------------------------------------
    */
    'max_pricing_calls_per_package' => 25,
    'max_duration_candidates' => 8,
    'min_meaningful_remaining_budget' => 100, // INR
];
