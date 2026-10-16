<?php

return [

    /*
    | ENF01 : la MFA est obligatoire pour tout utilisateur qui détient une
    | permission sensible (★). Ne la désactiver que sur un poste de développement.
    */

    'mfa_obligatoire' => (bool) env('MFA_OBLIGATOIRE', true),

];
