<?php

/*
|--------------------------------------------------------------------------
| BloodLink content & settings
|--------------------------------------------------------------------------
| Static content for the landing page lives here so you can edit text
| without touching the Blade views. Read it anywhere with config('bloodlink.xxx').
*/

return [

    'blood_types' => [
        'A+'  => 'A+ Positive',
        'A-'  => 'A- Negative',
        'B+'  => 'B+ Positive',
        'B-'  => 'B- Negative',
        'AB+' => 'AB+ Positive',
        'AB-' => 'AB- Negative',
        'O+'  => 'O+ Positive',
        'O-'  => 'O- Negative',
    ],

    'default_blood_type' => 'O-',

    'regions' => [
        'NCR' => 'NCR - National Capital Region (Metro Manila)',
        'R3'  => 'Region III - Central Luzon',
        'R4A' => 'Region IV-A - CALABARZON',
        'R7'  => 'Region VII - Central Visayas',
        'R11' => 'Region XI - Davao Region',
    ],

    'stats' => [
        ['value' => '1,250+', 'title' => 'Verified Donors Joined',    'text' => 'Registered citizens standing by to save lives.'],
        ['value' => '850+',   'title' => 'Fulfillments Completed',    'text' => 'Urgent hospital requests resolved in real-time.'],
        ['value' => '45+',    'title' => 'Partner Medical Centers',   'text' => 'Hospitals connected to our real-time inventory.'],
        ['value' => '15 mins','title' => 'Avg. Match Dispatch',       'text' => 'Fastest deployment window recorded nationwide.'],
    ],

    'steps' => [
        ['icon' => 'user-check.svg', 'number' => '01', 'title' => 'Register & Get Screened',
         'text' => 'Create your profile, input blood type and medical history. Complete quick DOH-compliant digital screening.'],
        ['icon' => 'calendar.svg',   'number' => '02', 'title' => 'Smart Match & Booking',
         'text' => 'Receive instant alerts if a matching hospital request arises. Book convenient slots at nearest partner blood center.'],
        ['icon' => 'heart.svg',      'number' => '03', 'title' => 'Donate & Save Lives',
         'text' => 'Present your secure QR pass at destination clinic, complete blood donation and earn your verified digital Lifesaver Card.'],
    ],

    'dos' => [
        'Ensure you got at least 8 hours of sleep the night before.',
        'Stay fully hydrated. Drink 3-4 full glasses of water prior.',
        'Eat a light, non-fatty meal 4 hours before your slot.',
        'Wear comfortable clothing with easily rollable sleeves.',
    ],

    'donts' => [
        'Strictly no alcohol intake within 24 hours of donation.',
        'Do not smoke at least 4 hours before and after the session.',
        'Avoid strenuous heavy physical activities for 24 hours.',
        'Do not donate on an empty stomach to prevent dizziness.',
    ],

    'footer' => [
        'services' => ['Find Stocks', 'Donor Registry', 'Hospital Dashboard', 'Mobile Dispatch', 'API Integrations'],
        'resources' => ['Health Guidelines', 'DOH Certification', 'Privacy Policy', 'Terms of Use', 'Emergency Hotlines'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Donor rules & labels (used by the donor dashboard)
    |--------------------------------------------------------------------------
    */

    // Minimum days between whole-blood donations.
    // IMPORTANT: confirm this value with DOH / your partner blood center before going live.
    'donation_interval_days' => 90,

    // "Lives saved" estimate = completed donations x this number.
    'lives_per_donation' => 3,

    // A hospital is "low" on a blood type when it has fewer units than this.
    'low_stock_units' => 5,

    // Donor ranks by number of completed donations (keep in ascending order).
    'donor_tiers' => [
        ['name' => 'Bronze', 'min' => 1],
        ['name' => 'Silver', 'min' => 5],
        ['name' => 'Gold',   'min' => 15],
    ],

    'region_short' => [
        'NCR' => 'Metro Manila',
        'R3'  => 'Central Luzon',
        'R4A' => 'CALABARZON',
        'R7'  => 'Central Visayas',
        'R11' => 'Davao Region',
    ],


    /*
    |--------------------------------------------------------------------------
    | Donor pre-screening questionnaire
    |--------------------------------------------------------------------------
    | 'flag' = the answer ('yes' or 'no') that sends the question to the screening team for a
    | private follow-up. The questionnaire NEVER tells a donor they are eligible or not.
    | IMPORTANT: have your partner blood center / medical staff review these questions
    | before going live. Edit, add or remove questions here - no other code changes needed.
    */
    'prescreening' => [

        'steps' => [
            1 => 'Personal details',
            2 => 'Health questionnaire',
            3 => 'Lifestyle & travel',
            4 => 'Review & consent',
        ],

        'sections' => [
            2 => [
                'title'     => 'General health',
                'questions' => [
                    ['key' => 'feeling_well', 'text' => 'Are you feeling well and healthy today?', 'flag' => 'no'],
                    ['key' => 'fever',        'text' => 'Have you had a fever, cough or infection in the past 14 days?', 'flag' => 'yes'],
                    ['key' => 'medication',   'text' => 'Are you currently taking any medication?', 'flag' => 'yes',
                     'hint'    => 'Include prescription medicines, supplements and recent antibiotics.',
                     'details' => [
                         'label'       => 'Medication / supplement details',
                         'placeholder' => 'e.g. Daily multivitamin, no prescription medication',
                         'note'        => 'A clinician will review this information with you.',
                     ]],
                    ['key' => 'surgery',      'text' => 'Have you had surgery or a blood transfusion in the past 12 months?', 'flag' => 'yes'],
                    ['key' => 'vaccine',      'text' => 'Have you received a vaccine in the past 4 weeks?', 'flag' => 'yes'],
                ],
            ],
            3 => [
                'title'     => 'Lifestyle & travel',
                'questions' => [
                    ['key' => 'sleep',   'text' => 'Did you get about 8 hours of sleep last night?', 'flag' => 'no'],
                    ['key' => 'meal',    'text' => 'Have you eaten a light meal in the past 4 hours?', 'flag' => 'no'],
                    ['key' => 'alcohol', 'text' => 'Have you had any alcohol in the past 24 hours?', 'flag' => 'yes'],
                    ['key' => 'tattoo',  'text' => 'Have you had a tattoo, piercing or acupuncture in the past 12 months?', 'flag' => 'yes'],
                    ['key' => 'travel',  'text' => 'Have you travelled outside the Philippines in the past 12 months?', 'flag' => 'yes',
                     'details' => [
                         'label'       => 'Travel details',
                         'placeholder' => 'Where did you go and when?',
                         'note'        => 'A clinician will review this information with you.',
                     ]],
                ],
            ],
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Donor appointment booking
    |--------------------------------------------------------------------------
    */
    'booking' => [
        'lead_hours'   => 2,                    // a slot must start at least this many hours from now
        'horizon_days' => 60,                   // how far ahead donors can book
        'break'        => ['12:00', '13:00'],   // no slots during this break (lunch)
    ],


    /*
    |--------------------------------------------------------------------------
    | Receiver "Find Blood" search
    |--------------------------------------------------------------------------
    */

    // Stock older than this many minutes is shown as "Refresh needed" (call to verify).
    'stock_stale_minutes' => 90,

    'search_radii' => [5, 10, 15, 25, 50],      // kilometres
    'default_radius' => 15,

    // Places a receiver can search around. Coordinates are APPROXIMATE centre points.
    // Distances are straight-line, not travel time. Add more places as you need them.
    'localities' => [
        'ermita'    => ['name' => 'Ermita, Manila',               'region' => 'NCR', 'lat' => 14.5826, 'lng' => 120.9847],
        'makati'    => ['name' => 'Makati CBD',                   'region' => 'NCR', 'lat' => 14.5547, 'lng' => 121.0244],
        'bgc'       => ['name' => 'Bonifacio Global City, Taguig','region' => 'NCR', 'lat' => 14.5509, 'lng' => 121.0503],
        'ortigas'   => ['name' => 'Ortigas Center, Pasig',        'region' => 'NCR', 'lat' => 14.5866, 'lng' => 121.0614],
        'diliman'   => ['name' => 'Diliman, Quezon City',         'region' => 'NCR', 'lat' => 14.6538, 'lng' => 121.0685],
        'angeles'   => ['name' => 'Angeles City, Pampanga',       'region' => 'R3',  'lat' => 15.1450, 'lng' => 120.5887],
        'san_pablo' => ['name' => 'San Pablo City, Laguna',       'region' => 'R4A', 'lat' => 14.0683, 'lng' => 121.3251],
        'calamba'   => ['name' => 'Calamba, Laguna',              'region' => 'R4A', 'lat' => 14.2117, 'lng' => 121.1653],
        'batangas'  => ['name' => 'Batangas City',                'region' => 'R4A', 'lat' => 13.7565, 'lng' => 121.0583],
        'cebu'      => ['name' => 'Cebu City',                    'region' => 'R7',  'lat' => 10.3157, 'lng' => 123.8854],
        'davao'     => ['name' => 'Davao City',                   'region' => 'R11', 'lat' => 7.0731,  'lng' => 125.6128],
    ],


    /*
    |--------------------------------------------------------------------------
    | New blood request form
    |--------------------------------------------------------------------------
    */
    'requests' => [
        'max_units'   => 10,
        'max_files'   => 5,
        'max_file_kb' => 10240,       // 10 MB per file
        'relationships' => [
            'self'      => 'Patient',
            'family'    => 'Family representative',
            'caregiver' => 'Caregiver',
            'guardian'  => 'Legal guardian',
        ],
    ],


    /*
    |--------------------------------------------------------------------------
    | Hospital blood inventory
    |--------------------------------------------------------------------------
    */
    'inventory' => [
        'expiring_days' => 7,     // a unit expiring within this many days shows as "Expiring"
        'max_batch'     => 50,    // most units that can be added in one go
        'per_page'      => 10,
    ],


];
