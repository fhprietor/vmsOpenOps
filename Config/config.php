<?php

/**
 * Valores por defecto de VmsOpenOps.
 *
 * Son SOLO los defaults: el valor efectivo sale de la tabla `settings`
 * (`setting('vms_open_ops...')`), que es lo que se edita en Admin > Settings.
 * Este fichero es el unico sitio donde deben vivir los valores por defecto.
 */
return [
    'name' => 'VmsOpenOps',
    'version' => '1.0.0',
    'jumpseat' => [
        'enabled' => true,
        'cost_per_nm' => 250,
        'min_cost' => 5000,
    ],
    'ferry' => [
        'enabled' => true,
        'cost_per_nm' => 500,
        'require_certification' => true,
        'min_cost_light' => 20000,
        'min_cost_medium' => 50000,
        'min_cost_heavy' => 100000,
    ],
    'require_reason' => true,
    'max_reason_length' => 500,
    'discord_staff_webhook' => '',
];
