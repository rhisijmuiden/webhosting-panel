<?php

// Team members of customers (docs/team-members.md).
return [
    // Team members plus open invitations per customer.
    'max_members' => (int) env('WHP_TEAM_MAX_MEMBERS', 25),
    // Days an invitation link stays valid.
    'invite_days' => (int) env('WHP_TEAM_INVITE_DAYS', 7),
];
