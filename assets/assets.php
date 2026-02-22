<?php

/**
 * Synapse Bundle - Asset Mapper Configuration
 * 
 * Ce fichier déclare les assets du bundle pour AssetMapper.
 * Les projets utilisant le bundle doivent inclure ces assets.
 */

return [
    'synapse/styles/admin/synapse-variables.css' => [
        'path' => 'styles/admin/synapse-variables.css',
        'entrypoint' => true,
    ],
    'synapse/styles/admin/synapse-admin.css' => [
        'path' => 'styles/admin/synapse-admin.css',
        'entrypoint' => true,
    ],
    'synapse/styles/synapse.css' => [
        'path' => 'styles/synapse.css',
        'entrypoint' => true,
    ],
    'synapse/controllers/synapse_chat_controller.js' => [
        'path' => 'controllers/synapse_chat_controller.js',
    ],
    'synapse/controllers/synapse_sidebar_controller.js' => [
        'path' => 'controllers/synapse_sidebar_controller.js',
    ],
];
