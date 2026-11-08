<?php

/*
|--------------------------------------------------------------------------
| AI rates for tracking costs
|--------------------------------------------------------------------------
| Indicative prices in dollars per million tokens: 'in' for what you send, 'out'
| for the answer. A model that is not listed here gets the 'default'
| rate of its service. Cached tokens cost a fraction of 'in'.
| Update this when a service changes its rates (Claude: November 2026).
| The conversion to euros uses the current rate (exchange rates), otherwise
| 'usd_to_eur'.
*/

return [
    'text' => [
        'claude' => [
            'claude-haiku-5-5' => ['in' => 0.10, 'out' => 0.50, 'label' => 'Claude Haiku 5.5 (fast and affordable)'],
            'claude-sonnet-5-5' => ['in' => 2.00, 'out' => 10.00, 'label' => 'Claude Sonnet 5.5'],
            'claude-opus-5-5' => ['in' => 4.00, 'out' => 20.00, 'label' => 'Claude Opus 5.5 (smartest)'],
            'claude-opus-4-8' => ['in' => 5.00, 'out' => 25.00, 'label' => 'Claude Opus 4.8'],
            'default' => ['in' => 5.00, 'out' => 25.00],
        ],
        'openai' => [
            'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60, 'label' => 'GPT-4o mini (fast and affordable)'],
            'gpt-4o' => ['in' => 2.50, 'out' => 10.00, 'label' => 'GPT-4o'],
            'default' => ['in' => 2.50, 'out' => 10.00],
        ],
        'gemini' => [
            'gemini-2.5-flash' => ['in' => 0.30, 'out' => 2.50, 'label' => 'Gemini 2.5 Flash (fast and affordable)'],
            'gemini-2.5-pro' => ['in' => 1.25, 'out' => 10.00, 'label' => 'Gemini 2.5 Pro'],
            'default' => ['in' => 1.25, 'out' => 10.00],
        ],
    ],

    // Share of the 'in' rate for tokens that are written to or read from the cache.
    'cache' => [
        'claude' => ['write' => 1.25, 'read' => 0.10],
        'openai' => ['write' => 1.00, 'read' => 0.50],
        'gemini' => ['write' => 1.00, 'read' => 0.25],
    ],

    'usd_to_eur' => 0.92,
];
