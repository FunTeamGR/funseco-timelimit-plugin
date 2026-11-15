<?php

/*
 * Time limits. Defaults are the owner's XAseco flexitime.xml / autotime.xml values.
 * Who may change the time: adminops ability 'flexitime' (config/adminops.php);
 * /admin autotime: ability 'autotime'.
 */
return [
    // FlexiTime: the controller's own countdown; at 0 it forces the next map.
    'flexitime' => [
        'enabled' => true,
        // Limit in minutes (also the maximum when author_mult is used).
        'default_time' => 5,
        // Per-track times set with /timeset.
        'custom_time' => true,
        // Limit = author time × this (rounded up to whole minutes); 0 = use default_time.
        'author_mult' => 0,
        // Minimum in minutes when author_mult is used.
        'min_time' => 15,
        // /tl sets the remaining time to this many seconds.
        'emergency_seconds' => 300,
        // Chat announcements at whole minutes (10s, 5s under an hour, 1) and 30/10/0 seconds.
        'use_chat' => false,
        // Clock panel (position: manialinks.timelimit_clock).
        'show_panel' => true,
        'colour' => 'fff',
        // Below this many seconds left (or below the author time): warn colour.
        'warn_time' => 60,
        'warn_colour' => 'ff4',
        // Below this many seconds left: danger colour (0 = never).
        'danger_time' => 0,
        'danger_colour' => 'f44',
    ],

    // Auto TimeLimit: sets the server's TimeAttack limit from the next map's author time at race end.
    'autotime' => [
        'enabled' => true,
        // New limit = author time × this; 0 disables the feature.
        'multiplicator' => 0,
        // Minutes (decimals allowed).
        'mintime' => 5.5,
        'maxtime' => 5.5,
        'defaulttime' => 5.5,
        // 1 = public chat, 0 = none.
        'display' => 1,
    ],

    'messages' => [
        // FlexiTime. Dynamic values (names) are stripped of style codes so they cannot recolour the rest.
        'time_left'     => '{#highlite}{1}{#server} ({2}) until round end.',
        'time_left_paused' => '{#highlite}{1}{#server} ({2}) until round end (paused).',
        'announce'      => '{#server}> {1}',
        'changed'       => '{#server}> {#highlite}{1}{#server} changed time left: {2}',
        'paused'        => '{#server}> {#highlite}{1}{#server} paused the timer.',
        'resumed'       => '{#server}> {#highlite}{1}{#server} unpaused the timer.',
        'invalid'       => '{#server}> {#error}Invalid parameter to /timeleft.',
        'below_zero'    => '{#server}> {#error}Can\'t set remaining time to less than zero.',
        'no_permission' => '{#server}> {#error}You do not have permission to change the remaining time.',
        'timeset'       => '{#server}> {#highlite}{1}{#server} set future time for this track to {#highlite}{2}{#server} minutes.',
        'timeset_usage' => '{#server}> {#highlite}Usage (where 120 is number of minutes): /timeset 120',
        'timeset_disabled' => '{#server}> {#error}/timeset command not enabled in plugin config.',

        // Auto TimeLimit: {1} default/new/min/max, {2} map name, {3} limit MM:SS, {4} author time MM:SS.hh
        'autotime'      => '{#server}>> Set {1} timelimit for {#highlite}{2}{#server} : {#highlite}{3}{#server} (Author time: {#highlite}{4}{#server})',
        'autotime_set'  => '{#server}> {#admin}Auto TimeLimit set to {1}',
        'autotime_is'   => '{#server}> {#admin}Auto TimeLimit is currently {1}',
        'autotime_unavailable' => '{#server}> {#admin}Auto TimeLimit unavailable - set timelimit.autotime.enabled in config',
    ],
];
