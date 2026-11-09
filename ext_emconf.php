<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Supertext Translation',
    'description' => 'Supertext AI translation for TYPO3: translates content automatically when editors localize pages and content elements.',
    'category' => 'be',
    'author' => 'Supertext AG',
    'state' => 'beta',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
        ],
    ],
];
